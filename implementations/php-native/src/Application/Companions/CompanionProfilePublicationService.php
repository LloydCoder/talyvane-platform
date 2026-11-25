<?php
declare(strict_types=1);

namespace Talyvane\Application\Companions;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Talyvane\Domain\Market\MarketPolicy;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Human publication approval after independent adult verification and explicit public consent. */
final class CompanionProfilePublicationService
{
    public function __construct(private PDO $pdo, private AuditLogger $audit) {}

    /** @return list<array<string,mixed>> */
    public function pending(Principal $actor, int $limit = 50): array
    {
        Authorization::require($actor,'profiles.manage',true);
        if (!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) {
            throw new \\DomainException('Only an authorized administrator can review companion profiles.');
        }
        $limit = max(1,min(100,$limit));
        $query = $this->pdo->prepare(
            'SELECT p.id,p.slug,p.display_name,p.status,p.adult_eligibility_status,p.adult_verified_at,
                    p.publication_consent_version,p.created_at,d.display_name AS destination_name
             FROM companion_profiles p JOIN destinations d ON d.id = p.destination_id
             WHERE p.status = "pending_review"
             ORDER BY p.created_at ASC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    public function publish(Principal $actor, string $profileId, string $reason, string $requestId): void
    {
        Authorization::require($actor,'profiles.manage',true);
        if (!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) {
            throw new \DomainException('Only an authorized administrator can publish a companion profile.');
        }
        $reason = trim($reason);
        if (strlen($reason) < 10 || strlen($reason) > 500) {
            throw new \InvalidArgumentException('A publication reason between 10 and 500 characters is required.');
        }
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now',$utc);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT p.id,p.user_id,p.status,p.adult_eligibility_status,p.adult_verified_at,p.adult_verified_by,
                        p.verification_reference_hash,p.publication_consent_version,p.publication_consent_withdrawn_at,
                        d.country_code,d.status AS destination_status,c.enabled AS country_enabled,
                        m.enabled AS market_enabled,m.decision AS market_decision,m.reviewer_id,m.reviewed_at,
                        m.expires_at,m.evidence_reference,m.review_summary
                 FROM companion_profiles p JOIN destinations d ON d.id = p.destination_id
                 JOIN countries c ON c.alpha2 = d.country_code
                 LEFT JOIN market_policies m ON m.country_code = d.country_code
                 WHERE p.id = :id FOR UPDATE'
            );
            $query->execute(['id'=>$profileId]);
            $row = $query->fetch();
            if (!is_array($row) || $row['status'] !== 'pending_review' ||
                $row['adult_eligibility_status'] !== 'verified' || $row['adult_verified_at'] === null ||
                $row['adult_verified_by'] === null || !is_string($row['verification_reference_hash']) ||
                !preg_match('/^[a-f0-9]{64}$/iD',$row['verification_reference_hash']) ||
                $row['publication_consent_version'] === null || $row['publication_consent_withdrawn_at'] !== null ||
                $row['destination_status'] !== 'published' || !(bool) $row['country_enabled'] ||
                $row['market_decision'] === null) {
                throw new \DomainException('Profile does not meet adult-verification, market and consent publication gates.');
            }
            $market = new MarketPolicy(
                (string) $row['country_code'],(bool) $row['market_enabled'],(string) $row['market_decision'],
                $row['reviewer_id'] === null ? null : (string) $row['reviewer_id'],
                $row['reviewed_at'] === null ? null : new DateTimeImmutable((string) $row['reviewed_at'],$utc),
                $row['expires_at'] === null ? null : new DateTimeImmutable((string) $row['expires_at'],$utc),
                $row['evidence_reference'] === null ? null : (string) $row['evidence_reference'],
                $row['review_summary'] === null ? null : (string) $row['review_summary']
            );
            if (!$market->operationalAt($now)) {
                throw new \DomainException('Destination market approval is not current.');
            }
            $reviewer = $this->pdo->prepare('SELECT 1 FROM user_roles ur JOIN admin_mfa_credentials mfa ON mfa.user_id = ur.user_id WHERE ur.user_id = :reviewer AND ur.role_key IN ("owner","admin","super_admin") AND mfa.enabled_at IS NOT NULL LIMIT 1');
            $reviewer->execute(['reviewer'=>$market->reviewerId]);
            if ($reviewer->fetchColumn() === false) {
                throw new \DomainException('Market approval lacks an MFA-protected compliance reviewer.');
            }
            $consent = $this->pdo->prepare(
                'SELECT 1 FROM consent_grants WHERE subject_type = "companion_profile" AND subject_id = :profile
                 AND purpose = "profile.publication" AND audience = "public" AND policy_version = :version
                 AND granted = 1 AND withdrawn_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $consent->execute(['profile'=>$profileId,'version'=>$row['publication_consent_version']]);
            if ($consent->fetchColumn() === false) {
                throw new \DomainException('Current explicit public publication consent is missing or withdrawn.');
            }
            $update = $this->pdo->prepare('UPDATE companion_profiles SET status = "published",updated_at = UTC_TIMESTAMP() WHERE id = :id AND status = "pending_review" AND publication_consent_withdrawn_at IS NULL');
            $update->execute(['id'=>$profileId]);
            if ($update->rowCount() !== 1) {
                throw new \DomainException('Profile changed during publication; reload and retry.');
            }
            $this->audit->record($actor->id,'companion.profile.published','companion_profile',$profileId,$reason,['status'=>'pending_review'],['status'=>'published','consent_version'=>$row['publication_consent_version']],$requestId,'profile-publication-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }
}
