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
    public function request(string $reference,string $email,string $requestType,string $ipAddress,string $requestId,?string $assetId=null): void
    {
        $reference = strtoupper(trim($reference));
        $email = strtolower(trim($email));
        if (!preg_match('/^[A-Z0-9-]{6,32}$/D',$reference) || filter_var($email,FILTER_VALIDATE_EMAIL) === false ||
            !in_array($requestType,['access','correction','withdrawal','erasure','portfolio_withdrawal','portal_access'],true) ||
            ($requestType==='portfolio_withdrawal' && (!is_string($assetId) || !preg_match('/^[0-9a-f-]{36}$/iD',$assetId))) ||
            ($requestType!=='portfolio_withdrawal' && $assetId!==null) ||
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
        if ($requestType==='portfolio_withdrawal') {
            $asset=$this->pdo->prepare(
                'SELECT a.id FROM model_portfolio_assets a
                 WHERE a.id=:asset AND a.application_id=:application
                   AND a.status IN ("pending_review","approved") AND a.consent_withdrawn_at IS NULL
                 LIMIT 1'
            );
            $asset->execute(['asset'=>$assetId,'application'=>$application['id']]);
            if ($asset->fetchColumn()===false) return;
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
                 (id,application_id,related_asset_id,request_type,status,otp_hash,expires_at,attempts,verified_at,completed_at,created_at)
                 VALUES (:id,:application,:asset,:type,"pending",:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),0,NULL,NULL,UTC_TIMESTAMP())'
            )->execute(['id'=>$rightsId,'application'=>$application['id'],'asset'=>$assetId,'type'=>$requestType,'hash'=>$codeHash]);
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
            $subject=$requestType==='portal_access'?'Your Talyvane model workspace sign-in':'Your Talyvane applicant privacy request';
            $message=$requestType==='portal_access'
                ? "A secure model workspace sign-in was requested for application {$reference}.\n\nYour one-time verification code is: {$code}\n\nThe code expires in 10 minutes and can be used once. The verified workspace session lasts up to 30 minutes. If you did not request this, ignore this email."
                : "A privacy request was started for application {$reference}.\n\nYour one-time verification code is: {$code}\n\nThe code expires in 10 minutes and can be used once. If you did not request this, ignore this email.";
            $this->mailer->send($email,$subject,$message);
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
                'SELECT id,request_type,otp_hash,attempts,expires_at,status FROM model_applicant_rights_requests
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
            $eventKey=$rights['request_type']==='portal_access'?'model_application.portal_access.verified':'model_application.rights.verified';
            $this->audit->record(null,$eventKey,'model_application',(string) $app['id'],
                $rights['request_type']==='portal_access'?'Applicant authenticated to the model self-service workspace':'Applicant verified a privacy-rights request',
                null,['request_type'=>$rights['request_type']],$requestId,'applicant-rights-v1');
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
        if (strlen($requestId) < 16 || strlen($requestId) > 64) { throw new \InvalidArgumentException('Request identifier is invalid.'); }
        $this->pdo->beginTransaction();
        try {
            [$rights,$application] = $this->verifiedContext($rightsRequestId,'access');
            $assetQuery=$this->pdo->prepare(
                'SELECT id,status,mime_type,width,height,file_size,created_at,consent_withdrawn_at
                 FROM model_portfolio_assets WHERE application_id=:application
                 ORDER BY created_at ASC LIMIT 100'
            );
            $assetQuery->execute(['application'=>$application['id']]);
            $result = [
                'portfolio_assets'=>$assetQuery->fetchAll(PDO::FETCH_ASSOC),
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

    /** @return array<string,mixed> */
    public function portalRead(string $rightsRequestId,string $requestId): array
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Request identifier is invalid.');
        $this->pdo->beginTransaction();
        try {
            [$rights,$application]=$this->verifiedContext($rightsRequestId,'portal_access');
            $assets=$this->pdo->prepare(
                'SELECT id,status,mime_type,width,height,file_size,created_at,consent_withdrawn_at
                 FROM model_portfolio_assets WHERE application_id=:application
                 ORDER BY created_at DESC LIMIT 100'
            );
            $assets->execute(['application'=>$application['id']]);
            $licences=$this->pdo->prepare(
                'SELECT id,licence_reference,purpose,territory_code,status,starts_on,ends_on,
                        exclusivity,compensation_amount,currency_code,created_at
                 FROM model_image_licences WHERE application_id=:application
                 ORDER BY created_at DESC LIMIT 100'
            );
            $licences->execute(['application'=>$application['id']]);
            $castings=$this->pdo->prepare(
                'SELECT s.id,s.submission_reference,s.status,s.submitted_at,c.casting_reference,c.title,
                        c.project_purpose,c.country_code,c.shoot_starts_at,c.shoot_ends_at
                 FROM model_casting_submissions s
                 INNER JOIN model_casting_calls c ON c.id=s.casting_call_id
                 WHERE s.application_id=:application
                 ORDER BY s.submitted_at DESC LIMIT 100'
            );
            $castings->execute(['application'=>$application['id']]);
            $availableCalls=$this->pdo->prepare(
                'SELECT id,casting_reference,title,project_purpose,country_code,
                        opens_at,closes_at,shoot_starts_at,shoot_ends_at,compensation_amount,currency_code
                 FROM model_casting_calls
                 WHERE status="open" AND country_code=:country
                   AND opens_at<=UTC_TIMESTAMP() AND closes_at>UTC_TIMESTAMP()
                 ORDER BY closes_at ASC LIMIT 50'
            );
            $availableCalls->execute(['country'=>$application['country_code']]);
            $assignments=$this->pdo->prepare(
                'SELECT id,assignment_reference,status,project_purpose,country_code,
                        shoot_starts_at,shoot_ends_at,compensation_minor,currency_code,
                        created_at,offered_at,accepted_at,confirmed_at,started_at,completed_at
                 FROM model_assignments WHERE application_id=:application
                 ORDER BY created_at DESC LIMIT 100'
            );
            $assignments->execute(['application'=>$application['id']]);
            $result=[
                'application_reference'=>$application['application_reference'],
                'applicant_name'=>$application['applicant_name'],'email'=>$application['email'],
                'phone'=>$application['phone'],'country_code'=>$application['country_code'],
                'city'=>$application['city'],'portfolio_url'=>$application['portfolio_url'],
                'experience_summary'=>$application['experience_summary'],'status'=>$application['status'],
                'privacy_notice_version'=>$application['privacy_notice_version'],
                'privacy_acknowledged_at'=>$application['privacy_acknowledged_at'],'created_at'=>$application['created_at'],
                'portfolio_assets'=>$assets->fetchAll(PDO::FETCH_ASSOC),
                'image_licences'=>$licences->fetchAll(PDO::FETCH_ASSOC),
                'casting_submissions'=>$castings->fetchAll(PDO::FETCH_ASSOC),
                'available_casting_calls'=>$availableCalls->fetchAll(PDO::FETCH_ASSOC),
                'assignments'=>$assignments->fetchAll(PDO::FETCH_ASSOC),
                'legal_hold_restricts_mutations'=>(bool)$application['legal_hold'],
            ];
            $this->audit->record(null,'model_application.portal.accessed','model_application',(string)$application['id'],
                'Applicant accessed their authenticated model self-service workspace',null,
                ['rights_request_id'=>$rightsRequestId,'asset_count'=>count($result['portfolio_assets']),
                 'licence_count'=>count($result['image_licences']),'casting_count'=>count($result['casting_submissions']),
                 'assignment_count'=>count($result['assignments'])],$requestId,'model-applicant-portal-v1');
            $this->pdo->commit();
            return $result;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** Resolve the applicant's own application only from a live OTP-verified portal session. */
    public function portalApplicationId(string $rightsRequestId): string
    {
        $this->pdo->beginTransaction();
        try {
            [, $application]=$this->verifiedContext($rightsRequestId,'portal_access');
            if($application['status']!=='accepted' || (bool)$application['legal_hold']) {
                throw new \\DomainException('This model application is not eligible for casting self-service.');
            }
            $id=(string)$application['id'];
            $this->pdo->commit();
            return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $input */
    public function portalCorrect(string $rightsRequestId,array $input,string $requestId): void
    {
        $name=trim((string)($input['applicant_name']??''));
        $phone=isset($input['phone'])?(string)$input['phone']:null;
        $phone=$phone===null?null:trim($phone);
        $city=trim((string)($input['city']??''));
        $portfolio=$input['portfolio_url']??null;
        $experience=trim((string)($input['experience_summary']??''));
        if($name==='' || strlen($name)>120 ||
            ($phone!==null && ($phone!=='' && (strlen($phone)>40 || preg_match('/^[+0-9(). \\-]{5,40}$/D',$phone)!==1))) ||
            $city==='' || strlen($city)>120 || $experience==='' || strlen($experience)>8000 ||
            ($portfolio!==null && (!is_string($portfolio) || strlen($portfolio)>2048 ||
                filter_var($portfolio,FILTER_VALIDATE_URL)===false || strtolower((string)parse_url($portfolio,PHP_URL_SCHEME))!=='https')) ||
            strlen($requestId)<16 || strlen($requestId)>64) {
            throw new \InvalidArgumentException('Applicant profile fields are invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            [$rights,$application]=$this->verifiedContext($rightsRequestId,'portal_access');
            if($application['status']==='withdrawn') throw new \DomainException('A withdrawn application is read-only.');
            $this->pdo->prepare(
                'UPDATE model_talent_applications SET applicant_name=:name,phone=:phone,city=:city,
                 portfolio_url=:portfolio,experience_summary=:experience,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND anonymized_at IS NULL'
            )->execute(['name'=>$name,'phone'=>$phone===''?null:$phone,'city'=>$city,'portfolio'=>$portfolio,
                'experience'=>$experience,'id'=>$application['id']]);
            $this->recordApplicantEvent($application['id'],'application.portal_profile_corrected',(string)$application['status']);
            $this->audit->record(null,'model_application.portal_profile.corrected','model_application',(string)$application['id'],
                'Applicant corrected their own model profile',null,
                ['fields'=>['applicant_name','phone','city','portfolio_url','experience_summary']],$requestId,'model-applicant-portal-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function logoutPortal(string $rightsRequestId,string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Request identifier is invalid.');
        $this->pdo->beginTransaction();
        try {
            [$rights,$application]=$this->verifiedContext($rightsRequestId,'portal_access');
            $this->completeRequest($rightsRequestId);
            $this->audit->record(null,'model_application.portal_access.ended','model_application',(string)$application['id'],
                'Applicant ended their authenticated model workspace session',null,
                ['rights_request_id'=>$rightsRequestId],$requestId,'model-applicant-portal-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
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
            // Match the Laravel lifecycle gate: contractual records require a separate
            // retention review, and private portfolio bytes must be removed before PII erasure.
            $agreement = $this->pdo->prepare(
                'SELECT id FROM model_representation_agreements WHERE application_id = :application LIMIT 1 FOR UPDATE'
            );
            $agreement->execute(['application'=>$application['id']]);
            if ($agreement->fetchColumn() !== false) {
                $this->pdo->prepare(
                    'UPDATE model_applicant_rights_requests SET status = "rejected",otp_hash = "",completed_at = UTC_TIMESTAMP() WHERE id = :id'
                )->execute(['id'=>$rightsRequestId]);
                $this->recordApplicantEvent($application['id'],'application.erasure_deferred_contract_retention',(string) $application['status']);
                $this->audit->record(null,'model_application.erasure.deferred','model_application',(string) $application['id'],
                    'Erasure deferred because representation-agreement records require a separate documented retention review',
                    null,['blocker'=>'representation_agreement'], $requestId,'applicant-rights-v1');
                $this->pdo->commit();
                return false;
            }

            $privateAssets = $this->pdo->prepare(
                'SELECT id FROM model_portfolio_assets WHERE application_id = :application AND private_storage_key IS NOT NULL LIMIT 1 FOR UPDATE'
            );
            $privateAssets->execute(['application'=>$application['id']]);
            if ($privateAssets->fetchColumn() !== false) {
                $this->pdo->prepare(
                    'UPDATE model_applicant_rights_requests SET status = "rejected",otp_hash = "",completed_at = UTC_TIMESTAMP() WHERE id = :id'
                )->execute(['id'=>$rightsRequestId]);
                $this->recordApplicantEvent($application['id'],'application.erasure_deferred_private_media',(string) $application['status']);
                $this->audit->record(null,'model_application.erasure.deferred','model_application',(string) $application['id'],
                    'Erasure deferred until private portfolio files have been withdrawn and physically removed',
                    null,['blocker'=>'private_portfolio_media'], $requestId,'applicant-rights-v1');
                $this->pdo->commit();
                return false;
            }

            // Only metadata with no remaining private bytes can be removed. Agreement
            // and licence foreign keys are protected by the earlier contract gate.
            $this->pdo->prepare('DELETE FROM model_portfolio_assets WHERE application_id = :application')
                ->execute(['application'=>$application['id']]);

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
