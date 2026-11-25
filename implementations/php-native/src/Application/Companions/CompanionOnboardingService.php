<?php
declare(strict_types=1);

namespace Talyvane\Application\Companions;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Talyvane\Domain\Consent\ConsentGrant;
use Talyvane\Domain\Market\MarketPolicy;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class CompanionOnboardingService
{
    public function __construct(private PDO $pdo, private AuditLogger $audit) {}

    /** @param list<string> $languages */
    public function createVerifiedProfile(
        Principal $verifier,
        string $companionUserId,
        string $destinationId,
        string $displayName,
        ?string $biography,
        array $languages,
        ?string $experienceCategory,
        string $verificationReferenceHash,
        bool $publicationConsentGranted,
        string $consentPolicyVersion,
        string $consentEvidenceReference,
        string $requestId
    ): string {
        Authorization::require($verifier,'companion.adult_eligibility.verify',true);
        $displayName = trim($displayName);
        if (!$verifier->hasRole('verifier') || !$publicationConsentGranted ||
            !preg_match('/^[a-f0-9]{64}$/iD',$verificationReferenceHash) ||
            $displayName === '' || strlen($displayName) > 120 ||
            ($biography !== null && strlen($biography) > 10000) ||
            ($experienceCategory !== null && strlen($experienceCategory) > 80) ||
            $consentPolicyVersion === '' || strlen($consentEvidenceReference) < 8 ||
            strlen($consentEvidenceReference) > 190) {
            throw new \InvalidArgumentException('Companion onboarding requirements are incomplete.');
        }
        foreach ($languages as $language) {
            if (!is_string($language) || !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$language)) {
                throw new \InvalidArgumentException('Language identifiers must use supported BCP-47 language tags.');
            }
        }
        if (count($languages) > 20) {
            throw new \InvalidArgumentException('Too many language entries.');
        }

        $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $profileId = self::uuid();
        $consent = new ConsentGrant($profileId,'profile.publication','public',$consentPolicyVersion,true,$now,null);
        if (!$consent->validFor($profileId,'profile.publication','public',$consentPolicyVersion,$now)) {
            throw new \DomainException('Explicit publication consent is not valid for this profile.');
        }

        $this->pdo->beginTransaction();
        try {
            $destinationQuery = $this->pdo->prepare(
                'SELECT d.country_code,d.status,c.enabled AS country_enabled,mp.enabled,mp.decision,mp.reviewer_id,mp.reviewed_at,mp.expires_at,mp.evidence_reference,mp.review_summary
                 FROM destinations d JOIN countries c ON c.alpha2 = d.country_code LEFT JOIN market_policies mp ON mp.country_code = d.country_code
                 WHERE d.id = :id FOR UPDATE'
            );
            $destinationQuery->execute(['id'=>$destinationId]);
            $destination = $destinationQuery->fetch();
            if (!is_array($destination) || $destination['status'] !== 'published' || !(bool) $destination['country_enabled'] || $destination['decision'] === null) {
                throw new \DomainException('Destination market has no recorded compliance approval.');
            }
            $market = new MarketPolicy(
                (string) $destination['country_code'],
                (bool) $destination['enabled'],
                (string) $destination['decision'],
                $destination['reviewer_id'] === null ? null : (string) $destination['reviewer_id'],
                $destination['reviewed_at'] === null ? null : new DateTimeImmutable((string) $destination['reviewed_at'],new DateTimeZone('UTC')),
                $destination['expires_at'] === null ? null : new DateTimeImmutable((string) $destination['expires_at'],new DateTimeZone('UTC')),
                $destination['evidence_reference'] === null ? null : (string) $destination['evidence_reference'],
                $destination['review_summary'] === null ? null : (string) $destination['review_summary']
            );
            if (!$market->operationalAt($now)) {
                throw new \DomainException('Companion onboarding is blocked until the market is approved and current.');
            }
            $marketReviewer = $this->pdo->prepare('SELECT 1 FROM user_roles ur JOIN admin_mfa_credentials mfa ON mfa.user_id = ur.user_id WHERE ur.user_id = :reviewer AND ur.role_key IN ("owner","admin","super_admin") AND mfa.enabled_at IS NOT NULL LIMIT 1');
            $marketReviewer->execute(['reviewer'=>$market->reviewerId]);
            if ($marketReviewer->fetchColumn() === false) {
                throw new \DomainException('Market approval must be recorded by an MFA-protected compliance administrator.');
            }

            $userQuery = $this->pdo->prepare('SELECT status,email_verified_at FROM users WHERE id = :user FOR UPDATE');
            $userQuery->execute(['user'=>$companionUserId]);
            $user = $userQuery->fetch();
            $roleQuery = $this->pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :user AND role_key = "companion" LIMIT 1');
            $roleQuery->execute(['user'=>$companionUserId]);
            if ($verifier->id === $companionUserId || !is_array($user) || $user['status'] !== 'active' || $user['email_verified_at'] === null || $roleQuery->fetchColumn() === false) {
                throw new \DomainException('An active, email-verified companion account is required.');
            }
            $verifierRole = $this->pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :user AND role_key = "verifier" LIMIT 1');
            $verifierRole->execute(['user'=>$verifier->id]);
            if ($verifierRole->fetchColumn() === false) {
                throw new \DomainException('Adult eligibility verification must be performed by an assigned verifier.');
            }

            $consentInsert = $this->pdo->prepare(
                'INSERT INTO consent_grants (id,subject_type,subject_id,purpose,audience,policy_version,granted,granted_at,withdrawn_at,evidence_reference,created_at)
                 VALUES (:id,"companion_profile",:subject,"profile.publication","public",:version,1,UTC_TIMESTAMP(),NULL,:evidence,UTC_TIMESTAMP())'
            );
            $consentInsert->execute([
                'id'=>self::uuid(),
                'subject'=>$profileId,
                'version'=>$consentPolicyVersion,
                'evidence'=>$consentEvidenceReference,
            ]);
            $profile = $this->pdo->prepare(
                'INSERT INTO companion_profiles
                (id,user_id,destination_id,slug,display_name,biography,languages_json,experience_category,status,adult_eligibility_status,adult_verified_at,adult_verified_by,verification_reference_hash,publication_consent_version,publication_consent_at,publication_consent_withdrawn_at,auto_booking_opt_in,created_at,updated_at)
                VALUES (:id,:user,:destination,:slug,:display,:bio,:languages,:category,"pending_review","verified",UTC_TIMESTAMP(),:verifier,:verification,:consent_version,UTC_TIMESTAMP(),NULL,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $slug = 'profile-' . strtolower(substr(str_replace('-','',$profileId),0,20));
            $profile->execute([
                'id'=>$profileId,
                'user'=>$companionUserId,
                'destination'=>$destinationId,
                'slug'=>$slug,
                'display'=>$displayName,
                'bio'=>$biography,
                'languages'=>json_encode(array_values($languages),JSON_THROW_ON_ERROR),
                'category'=>$experienceCategory,
                'verifier'=>$verifier->id,
                'verification'=>$verificationReferenceHash,
                'consent_version'=>$consentPolicyVersion,
            ]);
            $this->audit->record($verifier->id,'companion.profile.onboarding.reviewed','companion_profile',$profileId,'Independent adult eligibility and publication consent recorded',null,[
                'status'=>'pending_review',
                'adult_eligibility_status'=>'verified',
                'publication_consent_version'=>$consentPolicyVersion,
                'market'=>$destination['country_code'],
            ],$requestId,'companion-onboarding-v1');
            $this->pdo->commit();
            return $profileId;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
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
