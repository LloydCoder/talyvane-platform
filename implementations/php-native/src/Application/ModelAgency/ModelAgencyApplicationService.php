<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Talyvane\Domain\ModelAgency\ApplicationStateMachine;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class ModelAgencyApplicationService
{
    public function __construct(private PDO $pdo, private AuditLogger $audit) {}

    /** @return list<array<string,mixed>> */
    public function pendingForReview(Principal $administrator, int $limit = 50): array
    {
        Authorization::require($administrator,'model_applications.manage',true);
        $this->actorRole($administrator);
        $limit = max(1,min(100,$limit));
        $query = $this->pdo->prepare(
            'SELECT a.id,a.application_reference,a.applicant_name,a.email,a.phone,a.country_code,a.city,
                    a.portfolio_url,a.experience_summary,a.status,a.legal_hold,a.created_at,
                    v.outcome AS latest_verification_outcome,v.expires_at AS latest_verification_expires_at
             FROM model_talent_applications a
             LEFT JOIN model_applicant_verifications v ON v.id = (
                 SELECT v2.id FROM model_applicant_verifications v2
                 WHERE v2.application_id = a.id ORDER BY v2.verified_at DESC LIMIT 1
             )
             WHERE a.anonymized_at IS NULL AND a.status IN ("submitted","under_review","accepted")
             ORDER BY a.created_at DESC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function verificationQueue(Principal $verifier, int $limit = 50): array
    {
        Authorization::require($verifier,'model_applications.adult_eligibility.verify',true);
        if (!$verifier->hasRole('verifier')) {
            throw new \\DomainException('Verifier role is required.');
        }
        $limit = max(1,min(100,$limit));
        $query = $this->pdo->prepare(
            'SELECT a.id,a.application_reference,a.country_code,a.city,a.status,a.adult_eligibility_attested_at,
                    v.outcome AS latest_verification_outcome,v.expires_at AS latest_verification_expires_at
             FROM model_talent_applications a
             LEFT JOIN model_applicant_verifications v ON v.id = (
                 SELECT v2.id FROM model_applicant_verifications v2
                 WHERE v2.application_id = a.id ORDER BY v2.verified_at DESC LIMIT 1
             )
             WHERE a.anonymized_at IS NULL AND a.status IN ("submitted","under_review")
             ORDER BY a.created_at ASC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    public function submit(
        string $applicantName,
        string $email,
        ?string $phone,
        string $countryCode,
        string $city,
        ?string $portfolioUrl,
        ?string $experienceSummary,
        bool $adultAttested,
        bool $privacyAcknowledged,
        string $privacyNoticeVersion,
        string $requestId
    ): string {
        $name = trim($applicantName);
        $email = strtolower(trim($email));
        $country = strtoupper(trim($countryCode));
        $city = trim($city);
        if ($name === '' || strlen($name) > 120 || filter_var($email,FILTER_VALIDATE_EMAIL) === false ||
            strlen($email) > 254 || !preg_match('/^[A-Z]{2}$/D',$country) ||
            $city === '' || strlen($city) > 120 || !$adultAttested || !$privacyAcknowledged ||
            ($phone !== null && (strlen($phone) > 40 || preg_match('/^[+0-9(). \-]{5,40}$/D',trim($phone)) !== 1)) ||
            ($experienceSummary !== null && strlen($experienceSummary) > 8000) ||
            ($portfolioUrl !== null && (strlen($portfolioUrl) > 2048 ||
                filter_var($portfolioUrl,FILTER_VALIDATE_URL) === false ||
                strtolower((string) parse_url($portfolioUrl,PHP_URL_SCHEME)) !== 'https')) ||
            strlen($privacyNoticeVersion) < 1 || strlen($privacyNoticeVersion) > 64 ||
            strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('Model application details or required adult attestation are invalid.');
        }

        $settings = $this->pdo->prepare('SELECT setting_json,version FROM deployment_settings WHERE setting_key = :key LIMIT 1');
        $settings->execute(['key'=>'model_agency.intake']);
        $setting = $settings->fetch();
        if (!is_array($setting)) {
            throw new \DomainException('Model agency application intake has not been explicitly configured.');
        }
        $policy = json_decode((string) $setting['setting_json'],true);
        if (!is_array($policy) || !($policy['enabled'] ?? false)) {
            throw new \DomainException('Model agency application intake is currently disabled.');
        }
        $requiredNotice = $policy['privacy_notice_version'] ?? null;
        $retentionDays = $policy['retention_days'] ?? null;
        if (!is_string($requiredNotice) || $requiredNotice === '' || !is_int($retentionDays) ||
            $retentionDays < 30 || $retentionDays > 3650 || !hash_equals($requiredNotice,$privacyNoticeVersion)) {
            throw new \DomainException('The current privacy notice or retention policy is invalid.');
        }
        $countryQuery = $this->pdo->prepare('SELECT enabled FROM countries WHERE alpha2 = :country LIMIT 1');
        $countryQuery->execute(['country'=>$country]);
        if ((int) $countryQuery->fetchColumn() !== 1) {
            throw new \DomainException('Applications are not enabled for this country.');
        }

        $id = self::uuid();
        $reference = 'MOD-' . strtoupper(bin2hex(random_bytes(6)));
        $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $retention = $now->modify('+' . $retentionDays . ' days');
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO model_talent_applications
                 (id,application_reference,applicant_name,email,phone,country_code,city,portfolio_url,experience_summary,
                  adult_eligibility_attested_at,privacy_acknowledged_at,privacy_notice_version,status,retention_due_at,created_at,updated_at)
                 VALUES (:id,:reference,:name,:email,:phone,:country,:city,:portfolio,:experience,
                  UTC_TIMESTAMP(),UTC_TIMESTAMP(),:notice,"submitted",:retention,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id'=>$id,'reference'=>$reference,'name'=>$name,'email'=>$email,'phone'=>$phone,
                'country'=>$country,'city'=>$city,'portfolio'=>$portfolioUrl,'experience'=>$experienceSummary,
                'notice'=>$privacyNoticeVersion,'retention'=>$retention->format('Y-m-d H:i:s'),
            ]);
            $this->recordEvent($id,null,'applicant','draft','submitted','Applicant submitted the independent model-agency application',$privacyNoticeVersion);
            $this->audit->record(null,'model_application.submitted','model_application',$id,'Applicant submitted a model-agency application',null,[
                'country_code'=>$country,
                'privacy_notice_version'=>$privacyNoticeVersion,
                'adult_attestation_recorded'=>true,
                'privacy_acknowledged'=>true,
                'retention_due_at'=>$retention->format('Y-m-d H:i:s'),
            ],$requestId,'model-agency-intake-v1');
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function verifyAdultEligibility(
        Principal $verifier,
        string $applicationId,
        string $method,
        string $providerReference,
        string $outcome,
        int $validityDays,
        string $requestId
    ): void {
        Authorization::require($verifier,'model_applications.adult_eligibility.verify',true);
        if (!$verifier->hasRole('verifier') || !in_array($outcome,['verified','failed','review_required'],true) ||
            !preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D',$method) ||
            strlen(trim($providerReference)) < 8 || strlen($providerReference) > 500 ||
            $validityDays < 1 || $validityDays > 730 || strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('Adult eligibility verification input or verifier role is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $application = $this->pdo->prepare('SELECT id,status FROM model_talent_applications WHERE id = :id FOR UPDATE');
            $application->execute(['id'=>$applicationId]);
            $row = $application->fetch();
            if (!is_array($row) || !in_array($row['status'],['submitted','under_review'],true)) {
                throw new \DomainException('Only an active application under review can receive an eligibility decision.');
            }
            $id = self::uuid();
            $this->pdo->prepare(
                'INSERT INTO model_applicant_verifications
                 (id,application_id,verifier_user_id,verification_method,provider_reference_hash,outcome,policy_version,verified_at,expires_at,created_at)
                 VALUES (:id,:application,:verifier,:method,:reference,:outcome,"model-adult-verification-v1",UTC_TIMESTAMP(),
                 IF(:outcome_expiry = "verified",DATE_ADD(UTC_TIMESTAMP(),INTERVAL :days DAY),NULL),UTC_TIMESTAMP())'
            )->execute([
                'id'=>$id,'application'=>$applicationId,'verifier'=>$verifier->id,'method'=>$method,
                'reference'=>hash('sha256',trim($providerReference)),'outcome'=>$outcome,
                'outcome_expiry'=>$outcome,'days'=>$validityDays,
            ]);
            $this->audit->record($verifier->id,'model_application.adult_eligibility.decided','model_application',$applicationId,
                'Independent adult eligibility verification decision recorded',null,[
                    'outcome'=>$outcome,'verification_method'=>$method,'provider_reference_hash'=>hash('sha256',trim($providerReference)),
                ],$requestId,'model-adult-verification-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function review(Principal $administrator, string $applicationId, string $toStatus, string $reason, string $requestId): string
    {
        Authorization::require($administrator,'model_applications.manage',true);
        if (strlen(trim($reason)) < 8 || strlen($reason) > 500 || strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('A review reason and valid request identifier are required.');
        }
        $role = $this->actorRole($administrator);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT status FROM model_talent_applications WHERE id = :id FOR UPDATE');
            $query->execute(['id'=>$applicationId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new \DomainException('Model application does not exist.');
            }
            $from = (string) $row['status'];
            $to = ApplicationStateMachine::transition($from,$toStatus,$role);
            if ($to === 'accepted') {
                $verification = $this->pdo->prepare(
                    'SELECT outcome,expires_at FROM model_applicant_verifications
                     WHERE application_id = :application ORDER BY verified_at DESC LIMIT 1 FOR UPDATE'
                );
                $verification->execute(['application'=>$applicationId]);
                $latestVerification = $verification->fetch();
                if (!is_array($latestVerification) || $latestVerification['outcome'] !== 'verified' ||
                    $latestVerification['expires_at'] === null ||
                    new DateTimeImmutable((string) $latestVerification['expires_at'],new DateTimeZone('UTC')) <= new DateTimeImmutable('now',new DateTimeZone('UTC'))) {
                    throw new \DomainException('Application cannot be accepted without a current, latest independent adult-eligibility verification decision.');
                }
            }
            $update = $this->pdo->prepare('UPDATE model_talent_applications SET status = :status,updated_at = UTC_TIMESTAMP() WHERE id = :id AND status = :from_status');
            $update->execute(['status'=>$to,'id'=>$applicationId,'from_status'=>$from]);
            if ($update->rowCount() !== 1) {
                throw new \DomainException('Application state changed concurrently.');
            }
            $this->recordEvent($applicationId,$administrator->id,$role,$from,$to,trim($reason),'model-agency-review-v1');
            $this->audit->record($administrator->id,'model_application.reviewed','model_application',$applicationId,trim($reason),[
                'status'=>$from,
            ],[
                'status'=>$to,
            ],$requestId,'model-agency-review-v1');
            $this->pdo->commit();
            return $to;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function updateLegalHold(Principal $administrator,string $applicationId,bool $place,string $reason,string $requestId): void
    {
        Authorization::require($administrator,'model_applications.manage',true);
        if (!$administrator->mfaVerified || (!$administrator->hasRole('super_admin') && !$administrator->hasRole('owner')) ||
            strlen(trim($reason)) < 15 || strlen($reason) > 500 || strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \DomainException('A MFA-verified owner or Super Admin and documented legal basis are required.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT status,legal_hold,anonymized_at FROM model_talent_applications WHERE id = :id FOR UPDATE');
            $query->execute(['id'=>$applicationId]);
            $application = $query->fetch();
            if (!is_array($application) || $application['anonymized_at'] !== null) {
                throw new \DomainException('Application is unavailable for legal-hold governance.');
            }
            if ((bool) $application['legal_hold'] === $place) {
                throw new \DomainException('Requested legal-hold state is already active.');
            }
            $this->pdo->prepare('UPDATE model_talent_applications SET legal_hold = :hold,legal_hold_reason = :reason,updated_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['hold'=>$place ? 1 : 0,'reason'=>$place ? trim($reason) : null,'id'=>$applicationId]);
            $event = $place ? 'application.legal_hold.placed' : 'application.legal_hold.released';
            $this->recordEvent($applicationId,$administrator->id,'admin',(string) $application['status'],(string) $application['status'],trim($reason),'model-legal-hold-v1');
            $this->audit->record($administrator->id,$event,'model_application',$applicationId,trim($reason),
                ['legal_hold'=>(bool) $application['legal_hold']],['legal_hold'=>$place],$requestId,'model-legal-hold-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function actorRole(Principal $actor): string
    {
        foreach (['super_admin','owner','admin'] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }
        throw new \DomainException('Actor is not authorized to review model applications.');
    }

    private function recordEvent(string $applicationId, ?string $actorId, string $actorType, string $from, string $to, string $reason, string $policyVersion): void
    {
        $this->pdo->prepare(
            'INSERT INTO model_talent_application_events
             (id,application_id,actor_user_id,actor_type,from_status,to_status,reason,policy_version,created_at)
             VALUES (:id,:application,:actor,:actor_type,:from_status,:to_status,:reason,:policy,UTC_TIMESTAMP())'
        )->execute([
            'id'=>self::uuid(),'application'=>$applicationId,'actor'=>$actorId,'actor_type'=>$actorType,
            'from_status'=>$from,'to_status'=>$to,'reason'=>$reason,'policy'=>$policyVersion,
        ]);
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
