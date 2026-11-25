<?php
declare(strict_types=1);

namespace Talyvane\Application\Globalization;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Separate, evidence-backed Super Admin review of whether a country market may operate. */
final class MarketPolicyManagementService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor): array
    {
        $this->assertSuperAdmin($actor);
        return $this->pdo->query(
            'SELECT c.alpha2,c.alpha3,c.name,c.enabled AS country_enabled,
                    m.enabled AS market_enabled,m.decision,m.reviewer_id,m.reviewed_at,m.expires_at,
                    m.evidence_reference,m.review_summary,m.policy_version
             FROM countries c LEFT JOIN market_policies m ON m.country_code = c.alpha2
             ORDER BY c.name'
        )->fetchAll();
    }

    public function review(
        Principal $actor,
        string $countryCode,
        string $decision,
        string $evidenceReference,
        string $summary,
        string $expiresAt,
        bool $enable,
        string $requestId
    ): void {
        $this->assertSuperAdmin($actor);
        if (!preg_match('/^[A-Z]{2}$/D',$countryCode)) {
            throw new InvalidArgumentException('Country code must be an ISO alpha-2 code.');
        }
        if (!in_array($decision,['approved','rejected'],true)) {
            throw new InvalidArgumentException('Choose an approval or rejection decision.');
        }
        $evidenceReference = trim($evidenceReference);
        $summary = trim($summary);
        if (strlen($evidenceReference) < 8 || strlen($evidenceReference) > 500 ||
            strlen($summary) < 20 || strlen($summary) > 2000) {
            throw new InvalidArgumentException('A valid evidence reference and substantive review summary are required.');
        }
        if ($decision === 'approved' && !$enable) {
            throw new InvalidArgumentException('Explicitly enable the market when approving it for service availability.');
        }
        if ($decision === 'rejected' && $enable) {
            throw new InvalidArgumentException('A rejected market cannot be enabled.');
        }
        $utc = new DateTimeZone('UTC');
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d',$expiresAt,$utc);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$expiry instanceof DateTimeImmutable ||
            (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
            $expiry->format('Y-m-d') !== $expiresAt ||
            $expiry <= new DateTimeImmutable('today',$utc) ||
            $expiry > (new DateTimeImmutable('today',$utc))->modify('+2 years')) {
            throw new InvalidArgumentException('Review expiry must be a valid future date within two years.');
        }

        $this->pdo->beginTransaction();
        try {
            $country = $this->pdo->prepare('SELECT alpha2 FROM countries WHERE alpha2 = :country LIMIT 1 FOR UPDATE');
            $country->execute(['country'=>$countryCode]);
            if ($country->fetchColumn() === false) {
                throw new DomainException('Country must exist in the verified reference dataset before market review.');
            }
            $current = $this->pdo->prepare(
                'SELECT enabled,decision,reviewer_id,reviewed_at,expires_at,evidence_reference,review_summary,policy_version
                 FROM market_policies WHERE country_code = :country LIMIT 1 FOR UPDATE'
            );
            $current->execute(['country'=>$countryCode]);
            $before = $current->fetch();
            $policyVersion = is_array($before) ? (string) $before['policy_version'] : 'market-policy-v1';
            $after = [
                'enabled'=>$enable,'decision'=>$decision,'reviewer_id'=>$actor->id,
                'reviewed_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>$expiry->modify('+1 day')->modify('-1 second')->format('Y-m-d H:i:s'),
                'evidence_reference'=>$evidenceReference,'review_summary'=>$summary,
                'policy_version'=>$policyVersion,
            ];
            if (is_array($before)) {
                $update = $this->pdo->prepare(
                    'UPDATE market_policies SET enabled=:enabled,decision=:decision,reviewer_id=:reviewer,
                     reviewed_at=UTC_TIMESTAMP(),expires_at=:expires,evidence_reference=:evidence,
                     review_summary=:summary,updated_at=UTC_TIMESTAMP()
                     WHERE country_code=:country'
                );
                $update->execute([
                    'enabled'=>$enable ? 1 : 0,'decision'=>$decision,'reviewer'=>$actor->id,
                    'expires'=>$expiry->modify('+1 day')->modify('-1 second')->format('Y-m-d H:i:s'),'evidence'=>$evidenceReference,
                    'summary'=>$summary,'country'=>$countryCode,
                ]);
            } else {
                $insert = $this->pdo->prepare(
                    'INSERT INTO market_policies
                     (country_code,enabled,decision,reviewer_id,reviewed_at,expires_at,evidence_reference,review_summary,policy_version,updated_at)
                     VALUES (:country,:enabled,:decision,:reviewer,UTC_TIMESTAMP(),:expires,:evidence,:summary,:version,UTC_TIMESTAMP())'
                );
                $insert->execute([
                    'country'=>$countryCode,'enabled'=>$enable ? 1 : 0,'decision'=>$decision,
                    'reviewer'=>$actor->id,'expires'=>$expiry->modify('+1 day')->modify('-1 second')->format('Y-m-d H:i:s'),
                    'evidence'=>$evidenceReference,'summary'=>$summary,'version'=>$policyVersion,
                ]);
            }
            $this->audit->record($actor->id,'country_market.compliance_reviewed','country_market',$countryCode,
                'Super Admin recorded an evidence-backed market decision.',
                is_array($before) ? $before : null,$after,$requestId,'market-policy-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertSuperAdmin(Principal $actor): void
    {
        Authorization::require($actor,'market.policy.manage',true);
        if (!$actor->hasRole('super_admin')) {
            throw new DomainException('Only the deployment Super Admin can record market compliance decisions.');
        }
    }
}
