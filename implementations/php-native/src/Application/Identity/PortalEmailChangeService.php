<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\Principal;

final class PortalEmailChangeService
{
    public function __construct(
        private PDO $pdo,
        private Mailer $mailer,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private string $pepper
    ) {
        if (strlen($pepper) < 32) throw new \InvalidArgumentException('Email change key material is invalid.');
    }

    public function request(Principal $actor,string $newEmail,string $currentPassword,string $requestId): void
    {
        $this->assertPortalActor($actor);
        $newEmail=strtolower(trim($newEmail));
        if (!filter_var($newEmail,FILTER_VALIDATE_EMAIL) || strlen($newEmail)>254 || strlen($currentPassword)>1024) {
            throw new \InvalidArgumentException('Email change details are invalid.');
        }
        if (!$this->limiter->consume('portal.email-change.request',$actor->id,3,3600,3600)) {
            throw new \DomainException('Email change requests are rate limited.');
        }
        $query=$this->pdo->prepare('SELECT email,password_hash,status FROM users WHERE id=:id');
        $query->execute(['id'=>$actor->id]);
        $user=$query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || $user['status']!=='active' || !password_verify($currentPassword,(string)$user['password_hash'])) {
            throw new \DomainException('Current credentials could not be verified.');
        }
        $oldEmail=strtolower((string)$user['email']);
        if (hash_equals($oldEmail,$newEmail)) throw new \InvalidArgumentException('The new address must differ from the current address.');
        $exists=$this->pdo->prepare('SELECT 1 FROM users WHERE LOWER(email)=:email AND id<>:id LIMIT 1');
        $exists->execute(['email'=>$newEmail,'id'=>$actor->id]);
        if ($exists->fetchColumn()!==false) throw new \DomainException('That email address is already associated with an account.');
        $id=self::uuid();
        $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE portal_email_change_challenges SET consumed_at=UTC_TIMESTAMP() WHERE user_id=:user AND consumed_at IS NULL')->execute(['user'=>$actor->id]);
            $insert=$this->pdo->prepare(
                'INSERT INTO portal_email_change_challenges
                 (id,user_id,old_email,new_email,code_hash,expires_at,attempts,consumed_at,created_at)
                 VALUES (:id,:user,:old,:new,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),0,NULL,UTC_TIMESTAMP())'
            );
            $insert->execute(['id'=>$id,'user'=>$actor->id,'old'=>$oldEmail,'new'=>$newEmail,'hash'=>hash_hmac('sha256',$code,$this->pepper)]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
        try {
            $this->mailer->send($newEmail,'Verify your new Talyvane email address',
                "Your verification code is: {$code}\n\nIt expires in 10 minutes. Your account email will not change until this code is verified. If you did not request this change, ignore this message.");
        } catch (\Throwable $e) {
            $this->pdo->prepare('UPDATE portal_email_change_challenges SET consumed_at=UTC_TIMESTAMP() WHERE id=:id AND consumed_at IS NULL')->execute(['id'=>$id]);
            throw new \RuntimeException('The new email address could not be verified because delivery failed.',0,$e);
        }
    }

    /** Returns the new session version after an atomic address change. */
    public function verify(Principal $actor,string $code,string $requestId): int
    {
        $this->assertPortalActor($actor);
        if (!preg_match('/^[0-9]{6}$/D',$code) || !$this->limiter->consume('portal.email-change.verify',$actor->id,5,300,900)) {
            throw new \DomainException('The verification details could not be accepted.');
        }
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare(
                'SELECT id,old_email,new_email,code_hash,expires_at,attempts FROM portal_email_change_challenges
                 WHERE user_id=:user AND consumed_at IS NULL ORDER BY created_at DESC LIMIT 1 FOR UPDATE'
            );
            $q->execute(['user'=>$actor->id]);
            $challenge=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($challenge) || (int)$challenge['attempts']>=5 ||
                strtotime((string)$challenge['expires_at'].' UTC')<=time()) {
                throw new \DomainException('The verification details could not be accepted.');
            }
            if(!hash_equals((string)$challenge['code_hash'],hash_hmac('sha256',$code,$this->pepper))) {
                $this->pdo->prepare('UPDATE portal_email_change_challenges SET attempts=attempts+1 WHERE id=:id')->execute(['id'=>$challenge['id']]);
                $this->pdo->commit();
                throw new \DomainException('The verification details could not be accepted.');
            }
            $userQ=$this->pdo->prepare('SELECT email,status,admin_session_version FROM users WHERE id=:id FOR UPDATE');
            $userQ->execute(['id'=>$actor->id]);
            $user=$userQ->fetch(PDO::FETCH_ASSOC);
            if(!is_array($user) || $user['status']!=='active' ||
                !hash_equals(strtolower((string)$user['email']),(string)$challenge['old_email'])) {
                throw new \DomainException('The account changed while email verification was pending.');
            }
            $change=$this->pdo->prepare(
                'UPDATE users SET email=:email,email_verified_at=UTC_TIMESTAMP(),
                 admin_session_version=admin_session_version+1,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND LOWER(email)=:old'
            );
            $change->execute(['email'=>$challenge['new_email'],'id'=>$actor->id,'old'=>$challenge['old_email']]);
            if($change->rowCount()!==1) throw new \DomainException('The email address could not be changed.');
            $this->pdo->prepare('UPDATE portal_email_change_challenges SET consumed_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$challenge['id']]);
            $version=(int)$user['admin_session_version']+1;
            $this->audit->record($actor->id,'portal.email.changed','user',$actor->id,
                'Portal contact email changed after current-password and new-address verification',
                ['email_changed'=>true],['email_changed'=>true,'session_version'=>$version],
                $requestId,'portal-email-change-v1');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
        try {
            $this->mailer->send((string)$challenge['old_email'],'Your Talyvane account email was changed',
                "The contact email for your Talyvane account was changed after verification of the new address. If you did not authorize this change, use the published support channel immediately.");
        } catch (\Throwable) {
            // The committed change is not rolled back by failure of a post-change security notice.
        }
        return $version;
    }

    private function assertPortalActor(Principal $actor): void
    {
        if (!(($actor->hasOnlyRole('client')) || $actor->hasOnlyRole('companion'))) {
            throw new \DomainException('Email self-service is available only to client and companion accounts.');
        }
    }

    private static function uuid(): string
    {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
