<?php
declare(strict_types=1);

namespace Talyvane\Application\Bookings;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Issues one-time, hashed, revocable bearer credentials for private calendar subscriptions. */
final class CalendarFeedTokenService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    /** @return array{id:string,token:string,label:string,expires_at:string} */
    public function issue(Principal $actor,string $label,int $expiresInDays,string $requestId): array
    {
        $role=$this->role($actor);
        $this->authorize($actor,$role);
        $label=trim($label);
        if(strlen($label)<2 || strlen($label)>80) throw new \InvalidArgumentException('Calendar feed label must contain 2 to 80 characters.');
        if($expiresInDays<1 || $expiresInDays>365) throw new \InvalidArgumentException('Calendar feed expiry must be between 1 and 365 days.');
        $this->assertRequestId($requestId);
        $this->assertActiveRole($actor->id,$role);
        $id=self::uuid();
        $token=bin2hex(random_bytes(32));
        $expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$expiresInDays.' days')->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO calendar_feed_tokens (id,user_id,role_key,label,token_hash,expires_at,created_at)
                 VALUES (:id,:user,:role,:label,:hash,:expires,UTC_TIMESTAMP())'
            )->execute(['id'=>$id,'user'=>$actor->id,'role'=>$role,'label'=>$label,'hash'=>hash('sha256',$token),'expires'=>$expires]);
            $this->event($id,$actor->id,$role,'issued');
            $this->audit->record($actor->id,'calendar.feed_token.issued','calendar_feed_token',$id,
                'Issued a private calendar subscription token',null,
                ['role'=>$role,'label'=>$label,'expires_at'=>$expires],$requestId,'calendar-feed-v1');
            $this->pdo->commit();
            return ['id'=>$id,'token'=>$token,'label'=>$label,'expires_at'=>$expires];
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array{id:string,role_key:string,label:string,expires_at:string,revoked_at:?string,last_used_at:?string,created_at:string}> */
    public function list(Principal $actor): array
    {
        $role=$this->role($actor);$this->authorize($actor,$role);
        $query=$this->pdo->prepare(
            'SELECT id,role_key,label,expires_at,revoked_at,last_used_at,created_at
             FROM calendar_feed_tokens WHERE user_id=:user AND role_key=:role ORDER BY created_at DESC LIMIT 50'
        );
        $query->execute(['user'=>$actor->id,'role'=>$role]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revoke(Principal $actor,string $tokenId,string $requestId): void
    {
        $role=$this->role($actor);$this->authorize($actor,$role);$this->assertRequestId($requestId);
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT id,revoked_at FROM calendar_feed_tokens WHERE id=:id AND user_id=:user AND role_key=:role LIMIT 1 FOR UPDATE');
            $query->execute(['id'=>$tokenId,'user'=>$actor->id,'role'=>$role]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)) throw new \DomainException('Calendar feed token does not belong to this account.');
            if($row['revoked_at']===null) {
                $this->pdo->prepare('UPDATE calendar_feed_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$tokenId]);
                $this->event($tokenId,$actor->id,$role,'revoked');
                $this->audit->record($actor->id,'calendar.feed_token.revoked','calendar_feed_token',$tokenId,
                    'Revoked a private calendar subscription token',['revoked'=>false],['revoked'=>true],
                    $requestId,'calendar-feed-v1');
            }
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function resolve(string $token): ?Principal
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$token)!==1) return null;
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,role_key FROM calendar_feed_tokens
                 WHERE token_hash=:hash AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE'
            );
            $query->execute(['hash'=>hash('sha256',$token)]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row) || !in_array($row['role_key'],['client','companion'],true)) {
                $this->pdo->commit();return null;
            }
            $user=$this->pdo->prepare(
                'SELECT u.status,ur.role_key FROM users u JOIN user_roles ur ON ur.user_id=u.id
                 WHERE u.id=:user AND ur.role_key=:role LIMIT 1 FOR UPDATE'
            );
            $user->execute(['user'=>$row['user_id'],'role'=>$row['role_key']]);
            $account=$user->fetch(PDO::FETCH_ASSOC);
            if(!is_array($account) || $account['status']!=='active') {
                $this->pdo->commit();return null;
            }
            $this->pdo->prepare('UPDATE calendar_feed_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$row['id']]);
            $this->pdo->commit();
            return new Principal((string)$row['user_id'],[(string)$row['role_key']],false);
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function role(Principal $actor): string
    {
        $roles=array_values(array_intersect($actor->roles,['client','companion']));
        if(count($roles)!==1) throw new \DomainException('A single client or companion portal identity is required.');
        return $roles[0];
    }

    private function authorize(Principal $actor,string $role): void
    {
        Authorization::require($actor,$role==='client'?'client.booking.manage':'companion.self.view',false);
        if(!$actor->hasRole($role)) throw new \DomainException('Calendar feed access is not authorized.');
    }

    private function assertActiveRole(string $userId,string $role): void
    {
        $query=$this->pdo->prepare(
            'SELECT u.status FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_key=:role WHERE u.id=:user LIMIT 1'
        );
        $query->execute(['user'=>$userId,'role'=>$role]);
        if($query->fetchColumn()!=='active') throw new \DomainException('An active portal account is required.');
    }

    private function event(string $tokenId,string $userId,string $role,string $type): void
    {
        $this->pdo->prepare(
            'INSERT INTO calendar_feed_token_events (id,token_id,user_id,role_key,event_type,occurred_at)
             VALUES (:id,:token,:user,:role,:event,UTC_TIMESTAMP())'
        )->execute(['id'=>self::uuid(),'token'=>$tokenId,'user'=>$userId,'role'=>$role,'event'=>$type]);
    }

    private function assertRequestId(string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Request identifier is invalid.');
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
