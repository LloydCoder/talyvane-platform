<?php
declare(strict_types=1);

namespace Talyvane\Application\Destinations;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Domain\Market\MarketPolicy;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** MFA-protected destination metadata; market approval remains a separate legal control. */
final class DestinationManagementService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor): array
    {
        $this->assertAdministrator($actor);
        return $this->pdo->query(
            'SELECT d.id,d.slug,d.country_code,c.name AS country_name,c.enabled AS country_enabled,
                    d.city_name,d.display_name,d.currency_code,cur.name AS currency_name,
                    cur.enabled AS currency_enabled,d.locale,d.time_zone,d.status,
                    CASE WHEN d.cover_media_key IS NULL THEN 0 ELSE 1 END AS has_cover,
                    m.enabled AS market_enabled,m.decision AS market_decision,m.expires_at AS market_expires_at
             FROM destinations d
             JOIN countries c ON c.alpha2 = d.country_code
             LEFT JOIN currencies cur ON cur.code = d.currency_code
             LEFT JOIN market_policies m ON m.country_code = d.country_code
             ORDER BY c.name,d.city_name,d.display_name'
        )->fetchAll();
    }

    /** @return array{countries:list<array<string,mixed>>,currencies:list<array<string,mixed>>} */
    public function catalogs(Principal $actor): array
    {
        $this->assertAdministrator($actor);
        return [
            'countries'=>$this->pdo->query('SELECT alpha2,name,enabled FROM countries ORDER BY name')->fetchAll(),
            'currencies'=>$this->pdo->query('SELECT code,name,enabled FROM currencies ORDER BY code')->fetchAll(),
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(Principal $actor,?string $destinationId,array $input,string $requestId): string
    {
        $this->assertAdministrator($actor);
        $displayName = self::text($input['display_name'] ?? null,2,190,'Display name');
        $slug = strtolower(self::text($input['slug'] ?? null,2,190,'Slug'));
        if (!preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D',$slug)) {
            throw new InvalidArgumentException('Slug must contain lowercase letters, numbers, hyphens or underscores.');
        }
        $countryCode = self::text($input['country_code'] ?? null,2,2,'Country code');
        $currencyCode = self::text($input['currency_code'] ?? null,3,3,'Currency code');
        $cityName = self::text($input['city_name'] ?? null,2,190,'City');
        $locale = self::text($input['locale'] ?? null,2,35,'Locale');
        $timeZone = self::text($input['time_zone'] ?? null,1,64,'Time zone');
        $status = self::text($input['status'] ?? 'draft',5,9,'Status');
        if (!preg_match('/^[A-Z]{2}$/D',$countryCode) || !preg_match('/^[A-Z]{3}$/D',$currencyCode)) {
            throw new InvalidArgumentException('Choose a valid ISO country and currency code.');
        }
        if (!preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D',$locale)) {
            throw new InvalidArgumentException('Locale must use a supported language or language-region tag.');
        }
        if (!in_array($timeZone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true) && $timeZone !== 'UTC') {
            throw new InvalidArgumentException('Choose an IANA time zone.');
        }
        if (!in_array($status,['draft','published'],true)) {
            throw new InvalidArgumentException('Destination status must be draft or published.');
        }

        $this->pdo->beginTransaction();
        try {
            $country = $this->pdo->prepare('SELECT alpha2,name,enabled FROM countries WHERE alpha2 = :code LIMIT 1 FOR UPDATE');
            $country->execute(['code'=>$countryCode]);
            $countryRow = $country->fetch();
            $currency = $this->pdo->prepare('SELECT code,enabled FROM currencies WHERE code = :code LIMIT 1 FOR UPDATE');
            $currency->execute(['code'=>$currencyCode]);
            $currencyRow = $currency->fetch();
            if (!is_array($countryRow) || !is_array($currencyRow)) {
                throw new DomainException('Country and currency must exist in the platform reference datasets.');
            }

            $slugQuery = $destinationId === null
                ? $this->pdo->prepare('SELECT id FROM destinations WHERE slug = :slug LIMIT 1')
                : $this->pdo->prepare('SELECT id FROM destinations WHERE slug = :slug AND id <> :id LIMIT 1');
            $slugParams = ['slug'=>$slug];
            if ($destinationId !== null) {
                $slugParams['id'] = $destinationId;
            }
            $slugQuery->execute($slugParams);
            if ($slugQuery->fetchColumn() !== false) {
                throw new InvalidArgumentException('That destination slug is already in use.');
            }

            $before = null;
            if ($destinationId !== null) {
                $existing = $this->pdo->prepare(
                    'SELECT id,slug,country_code,city_name,display_name,currency_code,locale,time_zone,status
                     FROM destinations WHERE id = :id LIMIT 1 FOR UPDATE'
                );
                $existing->execute(['id'=>$destinationId]);
                $before = $existing->fetch();
                if (!is_array($before)) {
                    throw new DomainException('Destination was not found.');
                }
            } else {
                $destinationId = self::uuid();
            }

            if ($status === 'published') {
                $this->assertMarketPublishable($countryCode,(bool) $countryRow['enabled'],(bool) $currencyRow['enabled']);
            }

            $after = [
                'slug'=>$slug,'country_code'=>$countryCode,'city_name'=>$cityName,
                'display_name'=>$displayName,'currency_code'=>$currencyCode,'locale'=>$locale,
                'time_zone'=>$timeZone,'status'=>$status,
            ];
            if ($before === null) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO destinations
                     (id,slug,country_code,city_name,display_name,currency_code,locale,time_zone,status,created_at,updated_at)
                     VALUES (:id,:slug,:country,:city,:name,:currency,:locale,:zone,:status,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
                );
                $insert->execute([
                    'id'=>$destinationId,'slug'=>$slug,'country'=>$countryCode,'city'=>$cityName,
                    'name'=>$displayName,'currency'=>$currencyCode,'locale'=>$locale,'zone'=>$timeZone,'status'=>$status,
                ]);
                $event = 'destination.created';
            } else {
                $update = $this->pdo->prepare(
                    'UPDATE destinations SET slug=:slug,country_code=:country,city_name=:city,display_name=:name,
                     currency_code=:currency,locale=:locale,time_zone=:zone,status=:status,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id'
                );
                $update->execute([
                    'slug'=>$slug,'country'=>$countryCode,'city'=>$cityName,'name'=>$displayName,
                    'currency'=>$currencyCode,'locale'=>$locale,'zone'=>$timeZone,'status'=>$status,'id'=>$destinationId,
                ]);
                $event = 'destination.updated';
            }
            $this->audit->record($actor->id,$event,'destination',$destinationId,
                'Authorized administrator changed destination metadata.',$before === null ? null : $before,$after,
                $requestId,'destination-management-v1');
            $this->pdo->commit();
            return $destinationId;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertMarketPublishable(string $countryCode,bool $countryEnabled,bool $currencyEnabled): void
    {
        if (!$countryEnabled || !$currencyEnabled) {
            throw new DomainException('Published destinations require enabled country and currency reference records.');
        }
        $query = $this->pdo->prepare(
            'SELECT enabled,decision,reviewer_id,reviewed_at,expires_at,evidence_reference,review_summary
             FROM market_policies WHERE country_code = :country LIMIT 1 FOR UPDATE'
        );
        $query->execute(['country'=>$countryCode]);
        $row = $query->fetch();
        if (!is_array($row)) {
            throw new DomainException('A current, independently reviewed market approval is required before publication.');
        }
        $utc = new DateTimeZone('UTC');
        $policy = new MarketPolicy(
            $countryCode,(bool) $row['enabled'],(string) $row['decision'],
            $row['reviewer_id'] === null ? null : (string) $row['reviewer_id'],
            $row['reviewed_at'] === null ? null : new DateTimeImmutable((string) $row['reviewed_at'],$utc),
            $row['expires_at'] === null ? null : new DateTimeImmutable((string) $row['expires_at'],$utc),
            $row['evidence_reference'] === null ? null : (string) $row['evidence_reference'],
            $row['review_summary'] === null ? null : (string) $row['review_summary']
        );
        if (!$policy->operationalAt(new DateTimeImmutable('now',$utc))) {
            throw new DomainException('Market approval is not current; destination publication is blocked.');
        }
        $reviewer = $this->pdo->prepare(
            'SELECT 1 FROM user_roles ur JOIN admin_mfa_credentials mfa ON mfa.user_id = ur.user_id
             WHERE ur.user_id = :reviewer AND ur.role_key IN ("owner","admin","super_admin")
               AND mfa.enabled_at IS NOT NULL LIMIT 1'
        );
        $reviewer->execute(['reviewer'=>$policy->reviewerId]);
        if ($reviewer->fetchColumn() === false) {
            throw new DomainException('Market approval must be signed by an MFA-protected compliance administrator.');
        }
    }

    private function assertAdministrator(Principal $actor): void
    {
        Authorization::require($actor,'site.content.manage',true);
        if (!$actor->hasRole('super_admin') && count(array_intersect($actor->roles,['owner','admin'])) !== 1) {
            throw new DomainException('Only an authorized agency administrator can manage destinations.');
        }
    }

    private static function text(mixed $value,int $min,int $max,string $label): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        $value = trim($value);
        if (strlen($value) < $min || strlen($value) > $max) {
            throw new InvalidArgumentException($label . ' length is invalid.');
        }
        return $value;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
