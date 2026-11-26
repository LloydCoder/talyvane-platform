<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

/** Verified request intake for client/companion correction and erasure; never erases data automatically. */
final class PortalPrivacyRightsService
{
    public function __construct(
        private PDO $pdo,
        private Mailer $mailer,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private SecretBox $box,
        private string $otpPepper
    ) {
        if (strlen($otpPepper)<32) {
            throw new \InvalidArgumentException('Portal privacy OTP key material must contain at least 256 bits.');
        }
    }

    public function request(Principal $actor,string $requestType,?string $summary,string $ipAddress,string $requestId): string
    {
        $accountType=self::accountType($actor);
        if (!in_array($requestType,['correction','erasure'],true) ||
            $ipAddress==='' || strlen($ipAddress)>64 || strlen($requestId)<16 || strlen($requestId)>64) {
            throw new \InvalidArgumentException('Privacy request details are invalid.');
        }
        $summary=$summary===null?null:trim($summary);
        if (($requestType==='correction' && ($summary===null || $summary==='' || strlen($summary)>2000)) ||
            ($requestType==='erasure' && $summary!==null && $summary!=='')) {
            throw new \InvalidArgumentException('Privacy request explanation is invalid.');
        }
        if (!$this->limiter->consume('portal.privacy.request',$actor->id.'|'.$ipAddress,3,3600,3600)) {
            throw new \DomainException('Privacy requests are temporarily rate limited.');
        }
        $userQuery=$this->pdo->prepare('SELECT email,status,email_verified_at FROM users WHERE id=:id LIMIT 1');
        $userQuery->execute(['id'=>$actor->id]);
        $user=$userQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || $user['status']!=='active' || $user['email_verified_at']===null ||
            !is_string($user['email']) || !filter_var($user['email'],FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('An active, email-verified portal account is required.');
        }

        $id=self::uuid();
        $code=str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);
        $hash=hash_hmac('sha256',$id."\0".$code,$this->otpPepper);
        $payload=$requestType==='correction'
            ? $this->box->encrypt(json_encode(['summary'=>$summary],JSON_THROW_ON_ERROR),'portal-privacy-request-v1:payload:'.$id)
            : null;
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests
                 SET status="superseded",otp_hash="",completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE user_id=:user AND status="pending" AND completed_at IS NULL'
            )->execute(['user'=>$actor->id]);
            $insert=$this->pdo->prepare(
                'INSERT INTO portal_privacy_requests
                 (id,user_id,account_type,request_type,status,payload_ciphertext,otp_hash,expires_at,attempts,
                  verified_at,reviewed_by,reviewed_at,decision_reason,completed_at,created_at,updated_at)
                 VALUES (:id,:user,:account,:type,"pending",:payload,:hash,
                         DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),0,NULL,NULL,NULL,NULL,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id'=>$id,'user'=>$actor->id,'account'=>$accountType,'type'=>$requestType,
                'payload'=>$payload,'hash'=>$hash,
            ]);
            $this->audit->record($actor->id,'portal.privacy.requested','portal_privacy_request',$id,
                'Portal account holder initiated a verified privacy-rights request',null,
                ['account_type'=>$accountType,'request_type'=>$requestType],$requestId,'portal-privacy-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        try {
            $this->mailer->send((string)$user['email'],'Your Talyvane privacy request',
                "A privacy-rights request was started for your Talyvane account.\n\n".
                'Request reference: '.$id."\n".
                'One-time verification code: '.$code."\n\n".
                "The code expires in 10 minutes and can be used once. Your request will enter the restricted review queue only after verification. Erasure is not automatic; legal holds, active bookings, financial records, safeguarding obligations and approved retention rules must be reviewed first.\n\n".
                'If you did not request this, ignore this message.');
        } catch(Throwable $error) {
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests SET status="delivery_failed",otp_hash="",completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status="pending"'
            )->execute(['id'=>$id]);
            $this->audit->record($actor->id,'portal.privacy.delivery_failed','portal_privacy_request',$id,
                'Privacy request verification email could not be delivered',null,['request_type'=>$requestType],$requestId,'portal-privacy-rights-v1');
            throw new \RuntimeException('Privacy request verification email could not be delivered.',0,$error);
        }
        return $id;
    }

    public function verify(Principal $actor,string $requestId,string $code,string $ipAddress,string $correlationId): bool
    {
        self::accountType($actor);
        if (!self::isUuid($requestId) || preg_match('/^[0-9]{8}$/D',$code)!==1 ||
            $ipAddress==='' || strlen($ipAddress)>64 || strlen($correlationId)<16 || strlen($correlationId)>64) {
            throw new \InvalidArgumentException('Privacy verification input is invalid.');
        }
        if (!$this->limiter->consume('portal.privacy.verify',$actor->id.'|'.$ipAddress,10,600,1800)) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,request_type,otp_hash,attempts,expires_at,status
                 FROM portal_privacy_requests WHERE id=:id AND user_id=:user AND status="pending"
                 AND completed_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $query->execute(['id'=>$requestId,'user'=>$actor->id]);
            $request=$query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($request)) {
                $this->pdo->commit();
                return false;
            }
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $expiry=new DateTimeImmutable((string)$request['expires_at'],new DateTimeZone('UTC'));
            $attempts=(int)$request['attempts'];
            if ($expiry<=$now || $attempts>=5 || $request['otp_hash']==='') {
                $this->pdo->prepare(
                    'UPDATE portal_privacy_requests SET status=:status,otp_hash="",completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE id=:id'
                )->execute(['status'=>$attempts>=5?'locked':'expired','id'=>$requestId]);
                $this->pdo->commit();
                return false;
            }
            $attempts++;
            $expected=hash_hmac('sha256',$requestId."\0".$code,$this->otpPepper);
            if (!hash_equals((string)$request['otp_hash'],$expected)) {
                $locked=$attempts>=5;
                $this->pdo->prepare(
                    'UPDATE portal_privacy_requests SET attempts=:attempts,status=:status,otp_hash=:hash,
                     completed_at=:completed,updated_at=UTC_TIMESTAMP() WHERE id=:id'
                )->execute([
                    'attempts'=>$attempts,'status'=>$locked?'locked':'pending',
                    'hash'=>$locked?'':$request['otp_hash'],
                    'completed'=>$locked?$now->format('Y-m-d H:i:s'):null,'id'=>$requestId,
                ]);
                $this->pdo->commit();
                return false;
            }
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests SET attempts=:attempts,status="in_review",otp_hash="",
                 verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['attempts'=>$attempts,'id'=>$requestId]);
            $this->audit->record($actor->id,'portal.privacy.verified','portal_privacy_request',$requestId,
                'Portal account holder verified a privacy-rights request',null,
                ['request_type'=>$request['request_type']],$correlationId,'portal-privacy-rights-v1');
            $this->pdo->commit();
            return true;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(Principal $actor): array
    {
        self::accountType($actor);
        $query=$this->pdo->prepare(
            'SELECT id,request_type,status,verified_at,reviewed_at,decision_reason,completed_at,created_at
             FROM portal_privacy_requests WHERE user_id=:user ORDER BY created_at DESC LIMIT 20'
        );
        $query->execute(['user'=>$actor->id]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function accountType(Principal $actor): string
    {
        $isClient=$actor->hasRole('client') && count($actor->roles)===1;
        $isCompanion=$actor->hasRole('companion') && count($actor->roles)===1;
        if(!$isClient && !$isCompanion) throw new \DomainException('A client or companion portal account is required.');
        Authorization::require($actor,$isClient?'client.privacy.manage':'companion.privacy.manage',false);
        return $isClient?'client':'companion';
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$value)===1;
    }
}
