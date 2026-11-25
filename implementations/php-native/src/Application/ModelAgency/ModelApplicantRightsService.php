<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Talyvane\Domain\ModelAgency\ApplicationStateMachine;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Infrastructure\Security\RateLimiter;

final class ModelApplicantRightsService
{
    public function __construct(
        private PDO $pdo,
        private Mailer $mailer,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private string $otpPepper
    ) {
        if (strlen($otpPepper) < 32) {
            throw new \InvalidArgumentException('Applicant-rights OTP key material must contain at least 32 bytes.');
        }
    }

    /** Always returns the same outcome to avoid disclosing whether an application exists. */
    public function request(string $reference,string $email,string $requestType,string $ipAddress,string $requestId): void
    {
        $reference = strtoupper(trim($reference));
        $email = strtolower(trim($email));
        if (!preg_match('/^[A-Z0-9-]{6,32}$/D',$reference) || filter_var($email,FILTER_VALIDATE_EMAIL) === false ||
            !in_array($requestType,['access','correction','withdrawal','erasure'],true) ||
            $ipAddress === '' || strlen($ipAddress) > 64 || strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('Applicant privacy request details are invalid.');
        }
        $rateKey = $reference . "\0" . $email . "\0" . $ipAddress;
        if (!$this->limiter->consume('model-rights.request',$rateKey,3,3600,3600)) {
            return;
        }
        $query = $this->pdo->prepare('SELECT id,email,status,anonymized_at FROM model_talent_applications WHERE application_reference = :reference LIMIT 1');
        $query->execute(['reference'=>$reference]);
        $application = $query->fetch();
        if (!is_array($application) || $application['anonymized_at'] !== null ||
            !hash_equals(strtolower((string) $application['email']),$email)) {
            return;
        }
        $rightsId = self::uuid();
        $code = str_pad((string) random_int(0,99999999),8,'0',STR_PAD_LEFT);
        $codeHash = hash_hmac('sha256',$rightsId . "\0" . $code,$this->otpPepper);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE model_applicant_rights_requests SET status = "superseded",otp_hash = "",completed_at = UTC_TIMESTAMP()
                 WHERE application_id = :application AND status IN ("pending","verified") AND completed_at IS NULL'
            )->execute(['application'=>$application['id']]);
            $this->pdo->prepare(
                'INSERT INTO model_applicant_rights_requests
                 (id,application_id,request_type,status,otp_hash,expires_at,attempts,verified_at,completed_at,created_at)
                 VALUES (:id,:application,:type,"pending",:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),0,NULL,NULL,UTC_TIMESTAMP())'
            )->execute(['id'=>$rightsId,'application'=>$application['id'],'type'=>$requestType,'hash'=>$codeHash]);
            $this->pdo->prepare(
                'INSERT INTO model_talent_application_events
                 (id,application_id,actor_user_id,actor_type,from_status,to_status,reason,policy_version,created_at)
                 VALUES (:id,:application,NULL,"applicant",:from_status,:to_status,:reason,"applicant-rights-v1",UTC_TIMESTAMP())'
            )->execute([
                'id'=>self::uuid(),'application'=>$application['id'],'from_status'=>$application['status'],'to_status'=>$application['status'],
                'reason'=>'Applicant initiated a verified privacy-rights request: ' . $requestType,
            ]);
            $this->audit->record(null,'model_application.rights.requested','model_application',(string) $application['id'],
                'Applicant initiated a verified privacy-rights request',null,['request_type'=>$requestType],$requestId,'applicant-rights-v1');
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        try {
            $this->mailer->send(
                $email,
                'Your Talyvane applicant privacy request',
                "A privacy request was started for application {$reference}.\n\nYour one-time verification code is: {$code}\n\nThe code expires in 10 minutes and can be used once. If you did not request this, ignore this email."
            );
        } catch (Throwable) {
            $this->pdo->prepare(
                'UPDATE model_applicant_rights_requests SET status = "delivery_failed",otp_hash = "",completed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = "pending"'
            )->execute(['id'=>$rightsId]);
        }
    }

    public function verify(string $reference,string $email,string $code,string $ipAddress,string $requestId): ?string
    {
        $reference = strtoupper(trim($reference));
        $email = strtolower(trim($email));
        if (!preg_match('/^[A-Z0-9-]{6,32}$/D',$reference) || filter_var($email,FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/^[0-9]{8}$/D',$code) !== 1 || $ipAddress === '' ||
            strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('Applicant verification input is invalid.');
        }
        if (!$this->limiter->consume('model-rights.verify',$reference . "\0" . $email . "\0" . $ipAddress,10,600,1800)) {
            return null;
        }
        $application = $this->pdo->prepare('SELECT id,email,anonymized_at FROM model_talent_applications WHERE application_reference = :reference LIMIT 1');
        $application->execute(['reference'=>$reference]);
        $app = $application->fetch();
        if (!is_array($app) || $app['anonymized_at'] !== null || !hash_equals(strtolower((string) $app['email']),$email)) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT id,otp_hash,attempts,expires_at,status FROM model_applicant_rights_requests
                 WHERE application_id = :application AND status = "pending" AND completed_at IS NULL
                 ORDER BY created_at DESC LIMIT 1 FOR UPDATE'
            );
            $query->execute(['application'=>$app['id']]);
            $rights = $query->fetch();
            if (!is_array($rights)) {
                $this->pdo->commit();
                return null;
            }
            $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $expiry = new DateTimeImmutable((string) $rights['expires_at'],new DateTimeZone('UTC'));
            $attempts = (int) $rights['attempts'];
            if ($expiry <= $now || $attempts >= 5 || $rights['otp_hash'] === '') {
                $status = $attempts >= 5 ? 'locked' : 'expired';
                $this->pdo->prepare('UPDATE model_applicant_rights_requests SET status = :status,otp_hash = "",completed_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->execute(['status'=>$status,'id'=>$rights['id']]);
                $this->pdo->commit();
                return null;
            }
            $attempts++;
            $expected = hash_hmac('sha256',(string) $rights['id'] . "\0" . $code,$this->otpPepper);
            if (!hash_equals((string) $rights['otp_hash'],$expected)) {
                $locked = $attempts >= 5;
                $this->pdo->prepare('UPDATE model_applicant_rights_requests SET attempts = :attempts,status = :status,otp_hash = :hash,completed_at = :completed WHERE id = :id')
                    ->execute([
                        'attempts'=>$attempts,'status'=>$locked ? 'locked' : 'pending',
                        'hash'=>$locked ? '' : $rights['otp_hash'],
                        'completed'=>$locked ? $now->format('Y-m-d H:i:s') : null,'id'=>$rights['id'],
                    ]);
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare(
                'UPDATE model_applicant_rights_requests SET attempts = :attempts,status = "verified",otp_hash = "",
                 verified_at = UTC_TIMESTAMP(),expires_at = DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)
                 WHERE id = :id'
            )->execute(['attempts'=>$attempts,'id'=>$rights['id']]);
            $this->pdo->commit();
            return (string) $rights['id'];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function read(string $rightsRequestId,string $requestId): array
    {
        if (strlen($requestId) < 16 || strlen($requestId) > 64) { throw new \\InvalidArgumentException('Request identifier is invalid.'); }
        $this->pdo->beginTransaction();
        try {
            [$rights,$application] = $this->verifiedContext($rightsRequestId,'access');
            $result = [
                'application_reference'=>$application['application_reference'],
                'applicant_name'=>$application['applicant_name'],
                'email'=>$application['email'],
                'phone'=>$application['phone'],
                'country_code'=>$application['country_code'],
                'city'=>$application['city'],
                'portfolio_url'=>$application['portfolio_url'],
                'experience_summary'=>$application['experience_summary'],
                'status'=>$application['status'],
                'privacy_notice_version'=>$application['privacy_notice_version'],
                'privacy_acknowledged_at'=>$application['privacy_acknowledged_at'],
                'created_at'=>$application['created_at'],
            ];
            $this->audit->record(null,'model_application.rights.accessed','model_application',(string) $application['id'],
                'Applicant accessed their own application data',null,['rights_request_id'=>$rightsRequestId],$requestId,'applicant-rights-v1');
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $input */
    public function correct(string $rightsRequestId,array $input,string $requestId): void
    {
        $name = trim((string) ($input['applicant_name'] ?? ''));
        $phone = isset($input['phone']) ? trim((string) $input['phone']) : null;
        $city = trim((string) ($input['city'] ?? ''));
        $portfolio = $input['portfolio_url'] ?? null;
        $experience = trim((string) ($input['experience_summary'] ?? ''));
        if ($name === '' || strlen($name) > 120 || ($phone !== null && (strlen($phone) > 40 || preg_match('/^[+0-9(). \-]{5,40}$/D',$phone) !== 1)) ||
            $city === '' || strlen($city) > 120 || $experience === '' || strlen($experience) > 8000 ||
            ($portfolio !== null && (!is_string($portfolio) || strlen($portfolio) > 2048 ||
                filter_var($portfolio,FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($portfolio,PHP_URL_SCHEME)) !== 'https')) ||
            strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('Applicant correction fields are invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            [$rights,$application] = $this->verifiedContext($rightsRequestId,'correction');
            $this->pdo->prepare(
                'UPDATE model_talent_applications SET applicant_name = :name,phone = :phone,city = :city,
                 portfolio_url = :portfolio,experience_summary = :experience,updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND anonymized_at IS NULL'
            )->execute(['name'=>$name,'phone'=>$phone,'city'=>$city,'portfolio'=>$portfolio,'experience'=>$experience,'id'=>$application['id']]);
            $this->recordApplicantEvent($application['id'],'application.corrected',(string) $application['status']);
            $this->completeRequest($rightsRequestId);
            $this->audit->record(null,'model_application.corrected','model_application',(string) $application['id'],
                'Applicant corrected their submitted information',null,['fields'=>['applicant_name','phone','city','portfolio_url','experience_summary']],
                $requestId,'applicant-rights-v1');
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function withdraw(string $rightsRequestId,string $requestId): void
    {
        $this->pdo->beginTransaction();
        try {
            [$rights,$application] = $this->verifiedContext($rightsRequestId,'withdrawal');
            $to = ApplicationStateMachine::transition((string) $application['status'],'withdrawn','applicant');
            $this->pdo->prepare('UPDATE model_talent_applications SET status = :status,updated_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['status'=>$to,'id'=>$application['id']]);
            $this->recordApplicantEvent($application['id'],'application.withdrawn',(string) $application['status'],$to);
            $this->completeRequest($rightsRequestId);
            $this->audit->record(null,'model_application.withdrawn','model_application',(string) $application['id'],
                'Applicant withdrew their model-agency application',['status'=>$application['status']],['status'=>$to],
                $requestId,'applicant-rights-v1');
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Returns false when a documented legal hold prevents erasure. */
    public function erase(string $rightsRequestId,string $requestId): bool
    {
        $this->pdo->beginTransaction();
        try {
            [$rights,$application] = $this->verifiedContext($rightsRequestId,'erasure');
            if ((bool) $application['legal_hold']) {
                $this->recordApplicantEvent($application['id'],'application.erasure_deferred',(string) $application['status']);
                $this->pdo->prepare('UPDATE model_applicant_rights_requests SET status = "rejected",otp_hash = "",completed_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->execute(['id'=>$rightsRequestId]);
                $this->audit->record(null,'model_application.erasure.deferred','model_application',(string) $application['id'],
                    'Erasure deferred due to documented legal hold',null,['legal_hold'=>true],$requestId,'applicant-rights-v1');
                $this->pdo->commit();
                return false;
            }
            $replacementEmail = 'erased+' . bin2hex(random_bytes(12)) . '@invalid.example';
            $this->pdo->prepare(
                'UPDATE model_talent_applications SET applicant_name = "Erased applicant",email = :email,phone = NULL,
                 country_code = NULL,city = "Erased",portfolio_url = NULL,experience_summary = NULL,
                 retention_due_at = NULL,anonymized_at = UTC_TIMESTAMP(),status = "anonymized",updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute(['email'=>$replacementEmail,'id'=>$application['id']]);
            $this->pdo->prepare(
                'UPDATE model_applicant_rights_requests SET status = "superseded",otp_hash = "",completed_at = UTC_TIMESTAMP()
                 WHERE application_id = :application AND id <> :current AND completed_at IS NULL'
            )->execute(['application'=>$application['id'],'current'=>$rightsRequestId]);
            $this->recordApplicantEvent($application['id'],'application.anonymized',(string) $application['status'],'anonymized');
            $this->completeRequest($rightsRequestId);
            $this->audit->record(null,'model_application.anonymized','model_application',(string) $application['id'],
                'Applicant personal data was anonymized',null,['anonymized'=>true],$requestId,'applicant-rights-v1');
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function finish(string $rightsRequestId): void
    {
        $this->pdo->beginTransaction();
        try {
            [$rights] = $this->verifiedContext($rightsRequestId,null);
            $this->completeRequest($rightsRequestId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function verifiedContext(string $rightsRequestId,?string $expectedType): array
    {
        $query = $this->pdo->prepare('SELECT * FROM model_applicant_rights_requests WHERE id = :id FOR UPDATE');
        $query->execute(['id'=>$rightsRequestId]);
        $rights = $query->fetch();
        if (!is_array($rights) || $rights['status'] !== 'verified' || $rights['completed_at'] !== null ||
            new DateTimeImmutable((string) $rights['expires_at'],new DateTimeZone('UTC')) <= new DateTimeImmutable('now',new DateTimeZone('UTC')) ||
            ($expectedType !== null && $rights['request_type'] !== $expectedType)) {
            throw new \DomainException('Verified applicant privacy session is invalid or expired.');
        }
        $applicationQuery = $this->pdo->prepare('SELECT * FROM model_talent_applications WHERE id = :id FOR UPDATE');
        $applicationQuery->execute(['id'=>$rights['application_id']]);
        $application = $applicationQuery->fetch();
        if (!is_array($application) || $application['anonymized_at'] !== null) {
            throw new \DomainException('Applicant record is no longer available.');
        }
        return [$rights,$application];
    }

    private function completeRequest(string $rightsRequestId): void
    {
        $this->pdo->prepare('UPDATE model_applicant_rights_requests SET status = "completed",otp_hash = "",completed_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id'=>$rightsRequestId]);
    }

    private function recordApplicantEvent(string $applicationId,string $event, string $from,?string $to = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO model_talent_application_events
             (id,application_id,actor_user_id,actor_type,from_status,to_status,reason,policy_version,created_at)
             VALUES (:id,:application,NULL,"applicant",:from_status,:to_status,:reason,"applicant-rights-v1",UTC_TIMESTAMP())'
        )->execute([
            'id'=>self::uuid(),'application'=>$applicationId,'from_status'=>$from,
            'to_status'=>$to ?? $from,'reason'=>$event,
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
