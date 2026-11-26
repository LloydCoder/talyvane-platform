<?php
declare(strict_types=1);

namespace Talyvane\Application\Bookings;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Talyvane\Domain\Money\QuoteSnapshot;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\BookingPolicyRepository;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Immutable, client/profile-bound booking quote issuance and acceptance. */
final class BookingQuoteService
{
    public function __construct(
        private PDO $pdo,
        private AuditLogger $audit,
        private BookingPolicyRepository $policies
    ) {}

    /** @param array<string,int|string> $fees */
    public function createFromMajor(
        Principal $actor,string $clientId,string $profileId,string $amountMajor,string $currency,
        array $fees,DateTimeImmutable $expiresAt,string $requestId
    ): string {
        $currency=strtoupper(trim($currency));
        if(preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,4})?$/D',$amountMajor)!==1) {
            throw new \InvalidArgumentException('Quote amount must be a positive decimal value.');
        }
        $query=$this->pdo->prepare('SELECT minor_units,enabled FROM currencies WHERE code=:currency LIMIT 1');
        $query->execute(['currency'=>$currency]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || (int)$row['enabled']!==1) throw new \DomainException('Quote currency is not enabled.');
        $precision=(int)$row['minor_units'];
        if($precision<0 || $precision>4) throw new \DomainException('Quote currency precision is unsupported.');
        [$whole,$fraction]=array_pad(explode('.',$amountMajor,2),2,'');
        if(strlen($fraction)>$precision && trim(substr($fraction,$precision),'0')!=='') {
            throw new \InvalidArgumentException('Quote amount exceeds currency minor-unit precision.');
        }
        $scale=10**$precision;$fractionMinor=(int)str_pad(substr($fraction,0,$precision),$precision,'0');
        if((int)$whole>intdiv(PHP_INT_MAX-$fractionMinor,$scale)) throw new \InvalidArgumentException('Quote amount exceeds the supported integer range.');
        $amountMinor=((int)$whole*$scale)+$fractionMinor;
        return $this->create($actor,$clientId,$profileId,$amountMinor,$currency,$fees,$expiresAt,$requestId);
    }

    /** @param array<string,int|string> $fees */
    public function create(
        Principal $actor,
        string $clientId,
        string $profileId,
        int $amountMinor,
        string $currency,
        array $fees,
        DateTimeImmutable $expiresAt,
        string $requestId
    ): string {
        $this->authorizeAdministrator($actor);
        $this->assertRequestId($requestId);
        $currency=strtoupper(trim($currency));
        if($amountMinor<=0 || preg_match('/^[A-Z]{3}$/D',$currency)!==1) {
            throw new \InvalidArgumentException('Quote amount must be positive and use an ISO 4217 currency.');
        }
        $this->validateFees($fees,$amountMinor);
        $utc=new DateTimeZone('UTC');$now=new DateTimeImmutable('now',$utc);$expiresAt=$expiresAt->setTimezone($utc);
        if($expiresAt<=$now->modify('+5 minutes') || $expiresAt>$now->modify('+30 days')) {
            throw new \InvalidArgumentException('Quote expiry must be between five minutes and thirty days from now.');
        }
        $policy=$this->policies->current();
        $this->pdo->beginTransaction();
        try {
            $client=$this->pdo->prepare(
                'SELECT u.id,u.status,u.email_verified_at FROM users u
                 JOIN user_roles r ON r.user_id=u.id AND r.role_key="client"
                 WHERE u.id=:client LIMIT 1 FOR UPDATE'
            );
            $client->execute(['client'=>$clientId]);$clientRow=$client->fetch(PDO::FETCH_ASSOC);
            if(!is_array($clientRow) || $clientRow['status']!=='active' || $clientRow['email_verified_at']===null) {
                throw new \DomainException('Quote recipient must be an active, email-verified client.');
            }
            $profile=$this->lockEligibleProfile($profileId,$now);
            $currencyQuery=$this->pdo->prepare('SELECT enabled FROM currencies WHERE code=:currency LIMIT 1 FOR UPDATE');
            $currencyQuery->execute(['currency'=>$currency]);
            if((int)$currencyQuery->fetchColumn()!==1) throw new \DomainException('Quote currency is not enabled.');
            $snapshot=new QuoteSnapshot($amountMinor,$currency,null,null,null,null,null,null,null,$fees,
                (string)$profile['country_code'],$policy->version,$expiresAt);
            $id=self::uuid();$reference='TVQ-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(6)));
            $this->pdo->prepare(
                'INSERT INTO quote_snapshots
                 (id,quote_reference,client_user_id,profile_id,destination_id,country_code,original_amount_minor,
                  original_currency,target_amount_minor,target_currency,exchange_rate_snapshot_id,tax_policy_version,
                  fees_json,expires_at,accepted_at,accepted_by,consumed_at,consumed_booking_id,policy_version,created_by,created_at)
                 VALUES (:id,:reference,:client,:profile,:destination,:country,:amount,:currency,NULL,NULL,NULL,NULL,
                  :fees,:expires,NULL,NULL,NULL,NULL,:policy,:actor,UTC_TIMESTAMP())'
            )->execute([
                'id'=>$id,'reference'=>$reference,'client'=>$clientId,'profile'=>$profileId,
                'destination'=>$profile['destination_id'],'country'=>$snapshot->marketCode,'amount'=>$snapshot->originalAmountMinor,
                'currency'=>$snapshot->originalCurrency,'fees'=>json_encode($snapshot->fees,JSON_THROW_ON_ERROR),
                'expires'=>$snapshot->expiresAt->format('Y-m-d H:i:s'),'policy'=>$snapshot->policyVersion,'actor'=>$actor->id,
            ]);
            $this->audit->record($actor->id,'booking.quote.created','quote_snapshot',$id,
                'Created an immutable booking quote',null,
                ['client_user_id'=>$clientId,'profile_id'=>$profileId,'country_code'=>$profile['country_code'],
                 'amount_minor'=>$amountMinor,'currency'=>$currency,'fee_count'=>count($fees),'policy_version'=>$policy->version],
                $requestId,'booking-quote-v1');
            $this->pdo->commit();return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function accept(Principal $client,string $quoteId,string $requestId): void
    {
        Authorization::require($client,'client.booking.create',false);
        if(!$client->hasOnlyRole('client')) throw new \DomainException('A single-role client account is required to accept a booking quote.');
        $this->assertRequestId($requestId);
        $utc=new DateTimeZone('UTC');$now=new DateTimeImmutable('now',$utc);
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT * FROM quote_snapshots WHERE id=:id LIMIT 1 FOR UPDATE');
            $query->execute(['id'=>$quoteId]);$row=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row) || $row['client_user_id']!==$client->id) throw new \DomainException('Quote is not available to this client.');
            if($row['accepted_at']!==null) {
                if($row['accepted_by']===$client->id && $row['consumed_at']===null && $row['consumed_booking_id']===null) {
                    $this->pdo->commit();return;
                }
                throw new \DomainException('Quote has already been accepted or consumed.');
            }
            if($row['consumed_at']!==null || $row['consumed_booking_id']!==null || $row['expires_at']===null ||
                new DateTimeImmutable((string)$row['expires_at'],$utc)<=$now) {
                throw new \DomainException('Quote is expired or no longer available.');
            }
            $policy=$this->policies->current();
            if(!hash_equals((string)$row['policy_version'],$policy->version)) {
                throw new \DomainException('Booking policy changed; request a revised quote.');
            }
            $profile=$this->lockEligibleProfile((string)$row['profile_id'],$now);
            if($profile['destination_id']!==$row['destination_id'] || $profile['country_code']!==$row['country_code']) {
                throw new \DomainException('Quote market or destination no longer matches the companion profile.');
            }
            $fees=json_decode((string)($row['fees_json']??'[]'),true);
            if(!is_array($fees)) throw new \DomainException('Quote fee snapshot is corrupt.');
            $rate=null;
            if($row['exchange_rate_snapshot_id']!==null) {
                $rateQuery=$this->pdo->prepare('SELECT base_currency,quote_currency,rate_decimal,source,observed_at,expires_at FROM exchange_rate_snapshots WHERE id=:id LIMIT 1 FOR UPDATE');
                $rateQuery->execute(['id'=>$row['exchange_rate_snapshot_id']]);$rate=$rateQuery->fetch(PDO::FETCH_ASSOC);
                if(!is_array($rate) || $rate['base_currency']!==$row['original_currency'] ||
                    $rate['quote_currency']!==$row['target_currency'] ||
                    new DateTimeImmutable((string)$rate['observed_at'],$utc)>$now ||
                    new DateTimeImmutable((string)$rate['expires_at'],$utc)<=$now) {
                    throw new \DomainException('Quote exchange-rate snapshot is missing, mismatched or expired.');
                }
            }
            $quote=new QuoteSnapshot(
                (int)$row['original_amount_minor'],(string)$row['original_currency'],
                $row['target_amount_minor']===null?null:(int)$row['target_amount_minor'],
                $row['target_currency']===null?null:(string)$row['target_currency'],
                $rate===null?null:(string)$rate['rate_decimal'],
                $rate===null?null:(string)$rate['source'],
                $rate===null?null:new DateTimeImmutable((string)$rate['observed_at'],$utc),
                $rate===null?null:new DateTimeImmutable((string)$rate['expires_at'],$utc),
                $row['tax_policy_version']===null?null:(string)$row['tax_policy_version'],
                $fees,(string)$row['country_code'],(string)$row['policy_version'],
                new DateTimeImmutable((string)$row['expires_at'],$utc)
            );
            $accepted=$quote->accept($now);
            $update=$this->pdo->prepare(
                'UPDATE quote_snapshots SET accepted_at=:accepted,accepted_by=:actor
                 WHERE id=:id AND accepted_at IS NULL AND consumed_at IS NULL AND consumed_booking_id IS NULL AND expires_at>:now'
            );
            $update->execute(['accepted'=>$accepted->acceptedAt?->format('Y-m-d H:i:s'),'actor'=>$client->id,'id'=>$quoteId,'now'=>$now->format('Y-m-d H:i:s')]);
            if($update->rowCount()!==1) throw new \DomainException('Quote changed concurrently; refresh and retry.');
            $this->audit->record($client->id,'booking.quote.accepted','quote_snapshot',$quoteId,
                'Client accepted an immutable booking quote',['accepted'=>false],['accepted'=>true,'policy_version'=>$policy->version],
                $requestId,'booking-quote-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function clientQuotes(Principal $client,string $profileId): array
    {
        Authorization::require($client,'client.booking.create',false);
        if(!$client->hasRole('client') || count($client->roles)!==1) throw new \DomainException('A single-role client account is required.');
        $query=$this->pdo->prepare(
            'SELECT q.id,q.quote_reference,q.original_amount_minor,q.original_currency,q.fees_json,q.expires_at,
                    q.accepted_at,q.consumed_at,q.consumed_booking_id,p.slug AS profile_slug,p.display_name AS profile_name,c.minor_units AS currency_precision
             FROM quote_snapshots q JOIN companion_profiles p ON p.id=q.profile_id
             JOIN currencies c ON c.code=q.original_currency
             WHERE q.client_user_id=:client AND q.profile_id=:profile
             ORDER BY q.created_at DESC LIMIT 50'
        );
        $query->execute(['client'=>$client->id,'profile'=>$profileId]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) $row['fees']=json_decode((string)($row['fees_json']??'[]'),true)?:[];
        unset($row);return $rows;
    }

    /** @return array{clients:list<array<string,mixed>>,profiles:list<array<string,mixed>>} */
    public function adminOptions(Principal $actor): array
    {
        $this->authorizeAdministrator($actor);
        $clients=$this->pdo->query(
            'SELECT u.id,u.email FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_key="client"
             WHERE u.status="active" AND u.email_verified_at IS NOT NULL ORDER BY u.email LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);
        $profiles=$this->pdo->query(
            'SELECT p.id,p.display_name,d.display_name AS destination_name,d.country_code
             FROM companion_profiles p JOIN destinations d ON d.id=p.destination_id
             WHERE p.status="published" AND p.adult_eligibility_status="verified"
               AND p.adult_verified_at IS NOT NULL AND p.publication_consent_withdrawn_at IS NULL
               AND d.status="published" ORDER BY p.display_name LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);
        return ['clients'=>$clients,'profiles'=>$profiles];
    }

    /** @return list<array<string,mixed>> */
    public function adminList(Principal $actor,int $limit=100): array
    {
        $this->authorizeAdministrator($actor);$limit=max(1,min(200,$limit));
        $query=$this->pdo->prepare(
            'SELECT q.id,q.quote_reference,q.client_user_id,q.profile_id,q.country_code,q.original_amount_minor,
                    q.original_currency,q.expires_at,q.accepted_at,q.consumed_at,q.policy_version,
                    u.email AS client_email,p.display_name AS profile_name,c.minor_units AS currency_precision
             FROM quote_snapshots q JOIN users u ON u.id=q.client_user_id
             JOIN companion_profiles p ON p.id=q.profile_id
             JOIN currencies c ON c.code=q.original_currency
             ORDER BY q.created_at DESC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function lockEligibleProfile(string $profileId,DateTimeImmutable $now): array
    {
        $utc=new DateTimeZone('UTC');
        $query=$this->pdo->prepare(
            'SELECT p.id,p.destination_id,p.status,p.adult_eligibility_status,p.adult_verified_at,
                    p.publication_consent_version,p.publication_consent_withdrawn_at,d.country_code,d.status AS destination_status,
                    c.enabled AS country_enabled,m.enabled AS market_enabled,m.decision,m.reviewer_id,m.expires_at,m.evidence_reference
             FROM companion_profiles p JOIN destinations d ON d.id=p.destination_id
             JOIN countries c ON c.alpha2=d.country_code LEFT JOIN market_policies m ON m.country_code=d.country_code
             WHERE p.id=:profile LIMIT 1 FOR UPDATE'
        );
        $query->execute(['profile'=>$profileId]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || $row['status']!=='published' || $row['adult_eligibility_status']!=='verified' ||
            $row['adult_verified_at']===null || $row['publication_consent_version']===null ||
            $row['publication_consent_withdrawn_at']!==null || $row['destination_status']!=='published' ||
            (int)$row['country_enabled']!==1 || (int)($row['market_enabled']??0)!==1 ||
            $row['decision']!=='approved' || $row['expires_at']===null || $row['evidence_reference']===null ||
            new DateTimeImmutable((string)$row['expires_at'],$utc)<=$now) {
            throw new \DomainException('Companion profile, consent or market is not currently eligible for a booking quote.');
        }
        $reviewer=$this->pdo->prepare(
            'SELECT 1 FROM user_roles ur JOIN admin_mfa_credentials mfa ON mfa.user_id=ur.user_id
             WHERE ur.user_id=:reviewer AND ur.role_key IN ("owner","admin","super_admin")
               AND mfa.enabled_at IS NOT NULL LIMIT 1'
        );
        $reviewer->execute(['reviewer'=>$row['reviewer_id']]);
        if($reviewer->fetchColumn()===false) throw new \DomainException('Market approval is not backed by an MFA-protected compliance administrator.');
        $consent=$this->pdo->prepare(
            'SELECT granted,withdrawn_at,policy_version FROM consent_grants
             WHERE subject_type="companion_profile" AND subject_id=:profile
               AND purpose="profile.publication" AND audience="public"
             ORDER BY granted_at DESC LIMIT 1 FOR UPDATE'
        );
        $consent->execute(['profile'=>$profileId]);$grant=$consent->fetch(PDO::FETCH_ASSOC);
        if(!is_array($grant) || (int)$grant['granted']!==1 || $grant['withdrawn_at']!==null ||
            !hash_equals((string)$row['publication_consent_version'],(string)$grant['policy_version'])) {
            throw new \DomainException('Current public publication consent is required.');
        }
        return $row;
    }

    private function validateFees(array $fees,int $totalAmountMinor): void
    {
        if(count($fees)>20) throw new \InvalidArgumentException('A quote can contain no more than twenty fee components.');
        $feeTotal=0;
        foreach($fees as $key=>$amount) {
            if(!is_string($key) || preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D',$key)!==1 ||
                (!is_int($amount) && !is_string($amount)) || !is_numeric($amount) ||
                (int)$amount<0 || (string)(int)$amount!==(string)$amount ||
                (int)$amount>PHP_INT_MAX-$feeTotal) {
                throw new \InvalidArgumentException('Quote fee components must be named non-negative integer minor-unit amounts.');
            }
            $feeTotal+=(int)$amount;
        }
        if($feeTotal>$totalAmountMinor) throw new \InvalidArgumentException('Fee components cannot exceed the total quoted amount.');
    }

    private function authorizeAdministrator(Principal $actor): void
    {
        Authorization::require($actor,'bookings.manage',true);
        if(!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) {
            throw new \DomainException('MFA-verified booking administrator role is required.');
        }
    }

    private function assertRequestId(string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Request identifier is invalid.');
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
