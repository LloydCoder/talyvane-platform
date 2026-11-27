<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Mail\Mailer;

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
        private AuditLogger $audit
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
            if ($sessionVersion>=PHP_INT_MAX) throw new \RuntimeException('Administrator session version cannot be advanced.');

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
            $body="A Super Admin offline recovery was completed for your Talyvane installation.\n\n".
                "The authenticator factor and prior MFA recovery codes were revoked. All existing administrator sessions and pending step-up challenges were invalidated. A new offline recovery key was generated and displayed only in the terminal session that initiated the recovery.\n\n".
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
