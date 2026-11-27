<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Security\SecretBox;
use Talyvane\Security\Totp;

/**
 * Offline Super Admin recovery for a lost authenticator.
 *
 * The offline key is single-use: successful recovery rotates its hash,
 * revokes all administrator sessions and MFA factors, and notifies both
 * previously verified administrator addresses before committing.
 */
final class SuperAdminRecoveryService
{
    public function __construct(
        private PDO $pdo,
        private Mailer $mailer,
        private AuditLogger $audit,
        private ?EmailVerificationService $emailVerification = null,
        private ?SecretBox $secretBox = null
    ) {}

    /**
     * @return string New 43-character offline key, displayed only to the caller.
     */
    public function recover(string $password,string $offlineRecoveryKey,string $reason,string $requestId): string
    {
        $reason=trim($reason);
        if ($password==='' || strlen($password)>1024 ||
            preg_match('/^[A-Za-z0-9_-]{43}$/D',$offlineRecoveryKey)!==1 ||
            strlen($reason)<20 || strlen($reason)>500 ||
            strlen($requestId)<16 || strlen($requestId)>64) {
            throw new \InvalidArgumentException('Recovery input or audit context is invalid.');
        }

        $userId=null;
        $sessionVersion=null;
        try {
            $this->pdo->beginTransaction();
            $recoveryQuery=$this->pdo->query(
                'SELECT super_admin_user_id,recovery_email,offline_recovery_key_hash,recovery_version
                 FROM super_admin_recovery WHERE singleton_id=1 FOR UPDATE'
            );
            $recovery=$recoveryQuery->fetch();
            if (!is_array($recovery)) throw new SuperAdminRecoveryRejected();

            $userId=(string)$recovery['super_admin_user_id'];
            $userQuery=$this->pdo->prepare(
                'SELECT id,email,password_hash,status,email_verified_at,admin_session_version,remember_token_hash
                 FROM users WHERE id=:id FOR UPDATE'
            );
            $userQuery->execute(['id'=>$userId]);
            $user=$userQuery->fetch();
            if (!is_array($user)) throw new SuperAdminRecoveryRejected();

            $sessionVersion=(int)$user['admin_session_version'];
            $attemptQuery=$this->pdo->query(
                'SELECT COUNT(*) FROM super_admin_recovery_events
                 WHERE event_key IN ("recovery_rejected","recovery_rate_limited")
                   AND created_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)'
            );
            if ((int)$attemptQuery->fetchColumn()>=5) throw new SuperAdminRecoveryRateLimited();

            $roleQuery=$this->pdo->prepare('SELECT 1 FROM user_roles WHERE user_id=:user AND role_key="super_admin" LIMIT 1');
            $roleQuery->execute(['user'=>$userId]);
            $mfaQuery=$this->pdo->prepare('SELECT enabled_at FROM admin_mfa_credentials WHERE user_id=:user LIMIT 1');
            $mfaQuery->execute(['user'=>$userId]);
            $mfa=$mfaQuery->fetch();
            $primaryEmail=(string)$user['email'];
            $recoveryEmail=(string)$recovery['recovery_email'];
            $valid=$user['status']==='active' && $user['email_verified_at']!==null &&
                filter_var($primaryEmail,FILTER_VALIDATE_EMAIL) && filter_var($recoveryEmail,FILTER_VALIDATE_EMAIL) &&
                strcasecmp($primaryEmail,$recoveryEmail)!==0 &&
                $roleQuery->fetchColumn()!==false && is_array($mfa) && $mfa['enabled_at']!==null &&
                password_verify($password,(string)$user['password_hash']) &&
                password_verify($offlineRecoveryKey,(string)$recovery['offline_recovery_key_hash']);
            if (!$valid) throw new SuperAdminRecoveryRejected();
            if ($sessionVersion>=PHP_INT_MAX || (int)$recovery['recovery_version']>=PHP_INT_MAX) {
                throw new \RuntimeException('Recovery version cannot be advanced.');
            }

            $newKey=self::newOfflineKey();
            $newKeyHash=password_hash($newKey,PASSWORD_DEFAULT);
            if (!is_string($newKeyHash)) throw new \RuntimeException('Could not rotate the offline recovery key.');
            $sessionAfter=$sessionVersion+1;
            $recoveryVersion=(int)$recovery['recovery_version']+1;

            $this->pdo->prepare(
                'UPDATE users SET admin_session_version=:version,remember_token_hash=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['version'=>$sessionAfter,'id'=>$userId]);
            $this->pdo->prepare('DELETE FROM admin_mfa_recovery_codes WHERE user_id=:user')->execute(['user'=>$userId]);
            $this->pdo->prepare('DELETE FROM admin_mfa_credentials WHERE user_id=:user')->execute(['user'=>$userId]);
            $this->pdo->prepare('DELETE FROM admin_step_up_challenges WHERE user_id=:user')->execute(['user'=>$userId]);
            $this->pdo->prepare('DELETE FROM auth_challenges WHERE user_id=:user')->execute(['user'=>$userId]);
            $this->pdo->prepare(
                'UPDATE super_admin_recovery SET offline_recovery_key_hash=:hash,recovery_version=:version,updated_at=UTC_TIMESTAMP()
                 WHERE singleton_id=1'
            )->execute(['hash'=>$newKeyHash,'version'=>$recoveryVersion]);

            $subject='Super Admin recovery completed';
            $body="A Super Admin offline recovery attempt was initiated for your Talyvane installation.\n\n".
                "The system completes the key rotation and session invalidation only after both verified administrator addresses receive this notice. If this message arrives without a matching notice at the other address, treat the recovery as not completed; do not use a replacement key.\n\n".
                "If you did not authorise this action, immediately secure the administrator account and contact your platform operator.\n\n".
                "Event reference: ".$requestId;
            try {
                $this->mailer->send($primaryEmail,$subject,$body);
                $this->mailer->send($recoveryEmail,$subject,$body);
            } catch (\Throwable) {
                throw new SuperAdminRecoveryNotificationFailed();
            }

            $this->insertEvent($userId,'mfa_recovered',$reason,'sent',$sessionVersion,$sessionAfter,$requestId);
            $this->audit->record($userId,'super_admin.recovery.completed','super_admin_recovery','1',$reason,
                ['recovery_version'=>$recoveryVersion-1,'session_version'=>$sessionVersion,'mfa_enabled'=>true],
                ['recovery_version'=>$recoveryVersion,'session_version'=>$sessionAfter,'mfa_reset'=>true,'notifications'=>'sent'],
                $requestId,'super-admin-recovery-v1');
            $this->pdo->commit();
            return $newKey;
        } catch (SuperAdminRecoveryRateLimited) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->recordFailure($userId,'recovery_rate_limited',$requestId);
            throw new \DomainException('Recovery is temporarily unavailable. Wait before trying again.');
        } catch (SuperAdminRecoveryRejected) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->recordFailure($userId,'recovery_rejected',$requestId);
            throw new \DomainException('Recovery could not be completed. Verify the credentials and contact addresses or use the approved recovery process.');
        } catch (SuperAdminRecoveryNotificationFailed) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->recordFailure($userId,'recovery_notification_failed',$requestId,'failed',$sessionVersion,$sessionVersion);
            throw new \RuntimeException('Recovery notifications could not be delivered. No recovery changes were committed.');
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return array{user_id:string,primary_email:string,recovery_email:?string} */
    public function recoveryContactContext(): array
    {
        $query=$this->pdo->query(
            'SELECT u.id AS user_id,u.email AS primary_email,r.recovery_email
             FROM super_admin_recovery r JOIN users u ON u.id=r.super_admin_user_id
             WHERE r.singleton_id=1 LIMIT 1'
        );
        $row=$query->fetch();
        if (is_array($row)) {
            return [
                'user_id'=>(string)$row['user_id'],
                'primary_email'=>(string)$row['primary_email'],
                'recovery_email'=>is_string($row['recovery_email'])&&$row['recovery_email']!==''?(string)$row['recovery_email']:null,
            ];
        }
        $query=$this->pdo->query(
            'SELECT u.id AS user_id,u.email AS primary_email
             FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_key="super_admin"
             JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
             WHERE u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 2'
        );
        $rows=$query->fetchAll();
        if (count($rows)!==1) throw new \DomainException('Exactly one active, verified Super Admin with enabled MFA is required.');
        return ['user_id'=>(string)$rows[0]['user_id'],'primary_email'=>(string)$rows[0]['primary_email'],'recovery_email'=>null];
    }

    /** Enrol verified recovery contacts and issue a fresh offline key without resetting MFA. */
    public function enrollRecovery(
        string $password,string $totpCode,string $recoveryEmail,string $reason,string $requestId
    ): string {
        $this->assertRecoveryDependencies();
        $recoveryEmail=strtolower(trim($recoveryEmail));
        $reason=trim($reason);
        if ($password==='' || strlen($password)>1024 || !preg_match('/^[0-9]{6}$/D',$totpCode) ||
            !filter_var($recoveryEmail,FILTER_VALIDATE_EMAIL) || strlen($recoveryEmail)>254 ||
            strlen($reason)<20 || strlen($reason)>500 || preg_match('/[\x00-\x1F\x7F]/',$reason)===1 ||
            strlen($requestId)<16 || strlen($requestId)>64) {
            throw new \InvalidArgumentException('Recovery enrollment input is invalid.');
        }

        $userId=null;
        $sessionBefore=null;
        try {
            $this->pdo->beginTransaction();
            $recoveryQuery=$this->pdo->query('SELECT * FROM super_admin_recovery WHERE singleton_id=1 FOR UPDATE');
            $recovery=$recoveryQuery->fetch();
            if (is_array($recovery)) {
                $userId=(string)$recovery['super_admin_user_id'];
            } else {
                $candidateQuery=$this->pdo->query(
                    'SELECT u.id FROM users u
                     JOIN user_roles r ON r.user_id=u.id AND r.role_key="super_admin"
                     JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
                     WHERE u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 2 FOR UPDATE'
                );
                $candidates=$candidateQuery->fetchAll();
                if (count($candidates)!==1) throw new SuperAdminRecoveryRejected();
                $userId=(string)$candidates[0]['id'];
            }

            $userQuery=$this->pdo->prepare(
                'SELECT id,email,password_hash,status,email_verified_at,admin_session_version
                 FROM users WHERE id=:id FOR UPDATE'
            );
            $userQuery->execute(['id'=>$userId]);
            $user=$userQuery->fetch();
            $roleQuery=$this->pdo->prepare('SELECT 1 FROM user_roles WHERE user_id=:user AND role_key="super_admin" LIMIT 1');
            $roleQuery->execute(['user'=>$userId]);
            if (!is_array($user) || $user['status']!=='active' || $user['email_verified_at']===null ||
                $roleQuery->fetchColumn()===false || !password_verify($password,(string)$user['password_hash']) ||
                strcasecmp((string)$user['email'],$recoveryEmail)===0) {
                throw new SuperAdminRecoveryRejected();
            }
            if (is_array($recovery) &&
                trim((string)$recovery['offline_recovery_key_hash'])!=='' &&
                trim((string)$recovery['recovery_email'])!=='') {
                throw new \DomainException('Super Admin recovery credentials are already enrolled.');
            }
            $primaryEmail=strtolower((string)$user['email']);
            if (!$this->emailVerification->bothAdminAddressesVerified($userId,$primaryEmail,$recoveryEmail)) {
                throw new SuperAdminRecoveryRejected();
            }

            $mfaQuery=$this->pdo->prepare(
                'SELECT encrypted_totp_secret,enabled_at,last_accepted_counter FROM admin_mfa_credentials WHERE user_id=:user FOR UPDATE'
            );
            $mfaQuery->execute(['user'=>$userId]);
            $mfa=$mfaQuery->fetch();
            if (!is_array($mfa) || $mfa['enabled_at']===null) throw new SuperAdminRecoveryRejected();
            $secret=$this->secretBox->decrypt((string)$mfa['encrypted_totp_secret'],'totp:'.$userId);
            $counter=Totp::verify($secret,$totpCode,time(),(int)$mfa['last_accepted_counter']);
            if ($counter===null) throw new SuperAdminRecoveryRejected();

            $sessionBefore=(int)$user['admin_session_version'];
            if ($sessionBefore>=PHP_INT_MAX || (is_array($recovery)&&(int)$recovery['recovery_version']>=PHP_INT_MAX)) {
                throw new \RuntimeException('Recovery version cannot be advanced.');
            }
            $sessionAfter=$sessionBefore+1;
            $version=is_array($recovery)?(int)$recovery['recovery_version']+1:1;
            $newKey=self::newOfflineKey();
            $newHash=password_hash($newKey,PASSWORD_DEFAULT);
            if (!is_string($newHash)) throw new \RuntimeException('Could not create offline recovery key hash.');

            $this->pdo->prepare(
                'UPDATE users SET admin_session_version=:version,remember_token_hash=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['version'=>$sessionAfter,'id'=>$userId]);
            $this->pdo->prepare(
                'UPDATE admin_mfa_credentials SET last_accepted_counter=:counter,updated_at=UTC_TIMESTAMP() WHERE user_id=:user'
            )->execute(['counter'=>$counter,'user'=>$userId]);
            if (is_array($recovery)) {
                $this->pdo->prepare(
                    'UPDATE super_admin_recovery SET recovery_email=:email,offline_recovery_key_hash=:hash,
                     recovery_version=:version,enrolled_at=COALESCE(enrolled_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
                     WHERE singleton_id=1'
                )->execute(['email'=>$recoveryEmail,'hash'=>$newHash,'version'=>$version]);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO super_admin_recovery
                     (singleton_id,super_admin_user_id,recovery_email,offline_recovery_key_hash,recovery_version,enrolled_at,updated_at)
                     VALUES (1,:user,:email,:hash,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
                )->execute(['user'=>$userId,'email'=>$recoveryEmail,'hash'=>$newHash]);
            }

            $body="Super Admin recovery credentials were enrolled for your Talyvane installation.\n\n".
                "A new offline recovery key was issued. All prior administrator sessions were invalidated. The key is displayed only in the trusted terminal that initiated enrollment.\n\n".
                "If you did not authorise this action, immediately secure the administrator account.\n\nEvent reference: ".$requestId;
            try {
                $this->mailer->send($primaryEmail,'Super Admin recovery credentials enrolled',$body);
                $this->mailer->send($recoveryEmail,'Super Admin recovery credentials enrolled',$body);
            } catch (\Throwable) {
                throw new SuperAdminRecoveryNotificationFailed();
            }
            $this->insertEvent($userId,'recovery_credentials_enrolled',$reason,'sent',$sessionBefore,$sessionAfter,$requestId);
            $this->insertEvent($userId,'notification_sent',null,'sent',$sessionBefore,$sessionAfter,$requestId);
            $this->audit->record($userId,'super_admin.recovery.enrolled','super_admin_recovery','1',$reason,
                ['recovery_version'=>is_array($recovery)?(int)$recovery['recovery_version']:0,'session_version'=>$sessionBefore],
                ['recovery_version'=>$version,'session_version'=>$sessionAfter,'verified_notification_addresses'=>2],
                $requestId,'super-admin-recovery-v1');
            $this->pdo->commit();
            return $newKey;
        } catch (SuperAdminRecoveryRejected) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->recordFailure($userId,'recovery_enrollment_rejected',$requestId);
            throw new \DomainException('Recovery enrollment could not be completed. Verify the password, authenticator and both email codes.');
        } catch (SuperAdminRecoveryNotificationFailed) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->recordFailure($userId,'recovery_notification_failed',$requestId,'failed',$sessionBefore,$sessionBefore);
            throw new \RuntimeException('Recovery enrollment notifications failed. No recovery changes were committed.');
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** Rotate the separate recovery mailbox and the offline key after fresh dual-address verification. */
    public function rotateRecoveryContacts(
        string $password,string $totpCode,string $newRecoveryEmail,string $reason,string $requestId
    ): string {
        $this->assertRecoveryDependencies();
        $newRecoveryEmail=strtolower(trim($newRecoveryEmail));
        $reason=trim($reason);
        if ($password==='' || strlen($password)>1024 || !preg_match('/^[0-9]{6}$/D',$totpCode) ||
            !filter_var($newRecoveryEmail,FILTER_VALIDATE_EMAIL) || strlen($newRecoveryEmail)>254 ||
            strlen($reason)<20 || strlen($reason)>500 || preg_match('/[\x00-\x1F\x7F]/',$reason)===1 ||
            strlen($requestId)<16 || strlen($requestId)>64) {
            throw new \InvalidArgumentException('Recovery contact rotation input is invalid.');
        }

        $userId=null;
        $sessionBefore=null;
        try {
            $this->pdo->beginTransaction();
            $recoveryQuery=$this->pdo->query('SELECT * FROM super_admin_recovery WHERE singleton_id=1 FOR UPDATE');
            $recovery=$recoveryQuery->fetch();
            if (!is_array($recovery) || trim((string)$recovery['recovery_email'])==='' ||
                trim((string)$recovery['offline_recovery_key_hash'])==='') {
                throw new SuperAdminRecoveryRejected();
            }
            $userId=(string)$recovery['super_admin_user_id'];
            $userQuery=$this->pdo->prepare(
                'SELECT id,email,password_hash,status,email_verified_at,admin_session_version
                 FROM users WHERE id=:id FOR UPDATE'
            );
            $userQuery->execute(['id'=>$userId]);
            $user=$userQuery->fetch();
            if (!is_array($user) || $user['status']!=='active' || $user['email_verified_at']===null ||
                !password_verify($password,(string)$user['password_hash'])) {
                throw new SuperAdminRecoveryRejected();
            }
            $primaryEmail=strtolower((string)$user['email']);
            $oldRecoveryEmail=strtolower((string)$recovery['recovery_email']);
            if (strcasecmp($primaryEmail,$newRecoveryEmail)===0 || strcasecmp($oldRecoveryEmail,$newRecoveryEmail)===0 ||
                !$this->emailVerification->bothAdminAddressesVerified($userId,$primaryEmail,$newRecoveryEmail)) {
                throw new SuperAdminRecoveryRejected();
            }

            $mfaQuery=$this->pdo->prepare(
                'SELECT encrypted_totp_secret,enabled_at,last_accepted_counter FROM admin_mfa_credentials WHERE user_id=:user FOR UPDATE'
            );
            $mfaQuery->execute(['user'=>$userId]);
            $mfa=$mfaQuery->fetch();
            if (!is_array($mfa) || $mfa['enabled_at']===null) throw new SuperAdminRecoveryRejected();
            $secret=$this->secretBox->decrypt((string)$mfa['encrypted_totp_secret'],'totp:'.$userId);
            $counter=Totp::verify($secret,$totpCode,time(),(int)$mfa['last_accepted_counter']);
            if ($counter===null) throw new SuperAdminRecoveryRejected();

            $sessionBefore=(int)$user['admin_session_version'];
            $recoveryVersion=(int)$recovery['recovery_version'];
            if ($sessionBefore>=PHP_INT_MAX || $recoveryVersion>=PHP_INT_MAX) {
                throw new \RuntimeException('Recovery version cannot be advanced.');
            }
            $sessionAfter=$sessionBefore+1;
            $newVersion=$recoveryVersion+1;
            $newKey=self::newOfflineKey();
            $newHash=password_hash($newKey,PASSWORD_DEFAULT);
            if (!is_string($newHash)) throw new \RuntimeException('Could not create offline recovery key hash.');

            $this->pdo->prepare(
                'UPDATE users SET admin_session_version=:version,remember_token_hash=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['version'=>$sessionAfter,'id'=>$userId]);
            $this->pdo->prepare(
                'UPDATE admin_mfa_credentials SET last_accepted_counter=:counter,updated_at=UTC_TIMESTAMP() WHERE user_id=:user'
            )->execute(['counter'=>$counter,'user'=>$userId]);
            $this->pdo->prepare(
                'UPDATE super_admin_recovery SET recovery_email=:email,offline_recovery_key_hash=:hash,
                 recovery_version=:version,updated_at=UTC_TIMESTAMP() WHERE singleton_id=1'
            )->execute(['email'=>$newRecoveryEmail,'hash'=>$newHash,'version'=>$newVersion]);

            $body="Super Admin recovery notification contacts and the offline recovery key were rotated for your Talyvane installation.\n\n".
                "All prior administrator sessions were invalidated. A replacement offline key is displayed only in the trusted terminal that initiated this operation.\n\n".
                "If you did not authorise this change, immediately secure the administrator account.\n\nEvent reference: ".$requestId;
            try {
                $this->mailer->send($primaryEmail,'Super Admin recovery contacts rotated',$body);
                $this->mailer->send($oldRecoveryEmail,'Super Admin recovery contacts rotated',$body);
                $this->mailer->send($newRecoveryEmail,'Super Admin recovery contacts rotated',$body);
            } catch (\Throwable) {
                throw new SuperAdminRecoveryNotificationFailed();
            }
            $this->insertEvent($userId,'recovery_contacts_rotated',$reason,'sent',$sessionBefore,$sessionAfter,$requestId);
            $this->insertEvent($userId,'notification_sent',null,'sent',$sessionBefore,$sessionAfter,$requestId);
            $this->audit->record($userId,'super_admin.recovery.contacts_rotated','super_admin_recovery','1',$reason,
                ['recovery_email'=>$oldRecoveryEmail,'recovery_version'=>$recoveryVersion,'session_version'=>$sessionBefore],
                ['recovery_email'=>$newRecoveryEmail,'recovery_version'=>$newVersion,'session_version'=>$sessionAfter,'notifications'=>3],
                $requestId,'super-admin-recovery-v1');
            $this->pdo->commit();
            return $newKey;
        } catch (SuperAdminRecoveryRejected) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->recordFailure($userId,'recovery_contacts_rotation_rejected',$requestId);
            throw new \DomainException('Recovery contact rotation could not be completed. Verify the password, authenticator and both email codes.');
        } catch (SuperAdminRecoveryNotificationFailed) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->recordFailure($userId,'recovery_notification_failed',$requestId,'failed',$sessionBefore,$sessionBefore);
            throw new \RuntimeException('Recovery contact notifications failed. No recovery changes were committed.');
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function assertRecoveryDependencies(): void
    {
        if (!$this->emailVerification instanceof EmailVerificationService || !$this->secretBox instanceof SecretBox) {
            throw new \RuntimeException('Recovery enrollment and contact rotation require email verification and application encryption services.');
        }
    }

    private function recordFailure(
        ?string $userId,string $eventKey,string $requestId,?string $notificationStatus=null,
        ?int $sessionBefore=null,?int $sessionAfter=null
    ): void {
        $this->pdo->beginTransaction();
        try {
            $this->insertEvent($userId,$eventKey,null,$notificationStatus,$sessionBefore,$sessionAfter,$requestId);
            $this->audit->record($userId,'super_admin.recovery.attempt_recorded','super_admin_recovery','1',
                'Offline recovery attempt was not completed',
                null,['event_key'=>$eventKey,'notification_status'=>$notificationStatus],
                $requestId,'super-admin-recovery-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function insertEvent(
        ?string $userId,string $eventKey,?string $reason,?string $notificationStatus,
        ?int $sessionBefore,?int $sessionAfter,string $requestId
    ): void {
        $this->pdo->prepare(
            'INSERT INTO super_admin_recovery_events
             (id,actor_user_id,event_key,reason,notification_status,session_version_before,session_version_after,request_id,created_at)
             VALUES (:id,:actor,:event,:reason,:notification,:before,:after,:request,UTC_TIMESTAMP())'
        )->execute([
            'id'=>self::uuid(),'actor'=>$userId,'event'=>$eventKey,'reason'=>$reason,
            'notification'=>$notificationStatus,'before'=>$sessionBefore,'after'=>$sessionAfter,'request'=>$requestId,
        ]);
    }

    private static function newOfflineKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}

final class SuperAdminRecoveryRejected extends \RuntimeException {}
final class SuperAdminRecoveryRateLimited extends \RuntimeException {}
final class SuperAdminRecoveryNotificationFailed extends \RuntimeException {}
