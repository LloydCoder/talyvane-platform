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

    /**
     * Creates a non-public profile only after independent adult verification,
     * approved market review, and explicit versioned publication consent.
     *
     * @param list<string> $languages
     */
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
        if (!$verifier->hasRole('verifier') || !$publicationConsentGranted ||
            !preg_match('/^[a-f0-9]{64}$/iD',$verificationReferenceHash) ||
            strlen(trim($displayName)) < 1 || strlen($displayName) > 120 ||
            ($biography !== null && strlen($biography) > 10000) ||
            $consentPolicyVersion === '' || $consentEvidenceReference === '') {
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

        $destinationQuery = $this->pdo->prepare('SELECT country_code,status FROM destinations WHERE id = :id LIMIT 1');
        $destinationQuery->execute(['id'=>$destinationId]);
        $destination = $destinationQuery->fetch();
        if (!is_array($destination) || $destination['status'] !== 'published') {
            throw new \DomainException('Destination is not available for companion onboarding.');
        }
        $marketQuery = $this->pdo->prepare('SELECT enabled,decision,reviewer_id,reviewed_at,expires_at,evidence_reference,review_summary FROM market_policies WHERE country_code = :country LIMIT 1');
        $marketQuery->execute(['country'=>$destination['country_code']]);
        $market = $marketQuery->fetch();
        if (!is_array($market)) {
            throw new \DomainException('Destination market has no recorded compliance approval.');
        }
        $policy = new MarketPolicy(
            (string) $destination['country_code'],
            (bool) $market['enabled'],
            (string) $market['decision'],
            $market['reviewer_id'] === null ? null : (string) $market['reviewer_id'],
            $market['reviewed_at'] === null ? null : new DateTimeImmutable((string) $market['reviewed_at'],new DateTimeZone('UTC')),
            $market['expires_at'] === null ? null : new DateTimeImmutable((string) $market['expires_at'],new DateTimeZone('UTC')),
            $market['evidence_reference'] === null ? null : (string) $market['evidence_reference'],
            $market['review_summary'] === null ? null : (string) $market['review_summary']
        );
        $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if (!$policy->operationalAt($now)) {
            throw new \DomainException('Companion onboarding is blocked until the market is approved and current.');
        }

        $profileId = self::uuid();
        $consent = new ConsentGrant('companion_profile',$profileId,'profile.publication','public',$consentPolicyVersion,true,$now,null);
        if (!$consent->validFor('companion_profile',$profileId,'profile.publication','public',$consentPolicyVersion,$now)) {
            throw new \DomainException('Explicit publication consent is not valid for this profile.');
        }

        $this->pdo->beginTransaction();
        try {
            $userQuery = $this->pdo->prepare('SELECT status,email_verified_at FROM users WHERE id = :user FOR UPDATE');
            $userQuery->execute(['user'=>$companionUserId]);
            $user = $userQuery->fetch();
            $roleQuery = $this->pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :user AND role_key = "companion" LIMIT 1');
            $roleQuery->execute(['user'=>$companionUserId]);
            if (!is_array($user) || $user['status'] !== 'active' || $user['email_verified_at'] === null || $roleQuery->fetchColumn() === false) {
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
