<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class SafeguardingBackupPolicyService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    public function approve(Principal $actor,int $expiryDays,string $policyVersion,string $reason,string $requestId): void
    {
        Authorization::require($actor,'safeguarding.governance',true);
        if (!$actor->hasRole('owner')&&!$actor->hasRole('super_admin')) {
            throw new DomainException('Only an MFA-enrolled Owner or Super Admin may approve safeguarding backup policy.');
        }
        $policyVersion=trim($policyVersion);$reason=trim($reason);
        if ($expiryDays<1||$expiryDays>3650||!preg_match('/^[a-zA-Z0-9._-]{3,64}$/D',$policyVersion)||
            strlen($reason)<20||strlen($reason)>500||!preg_match('/^[a-f0-9]{16,64}$/D',$requestId)) {
            throw new InvalidArgumentException('Backup expiry period, policy version, reason or request reference is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $live=$this->pdo->prepare(
                'SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_key IN ("owner","super_admin")
                 JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
                 WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1 FOR UPDATE'
            );
            $live->execute(['id'=>$actor->id]);
            if ($live->fetchColumn()===false) { throw new DomainException('The backup policy approver must have an active verified account and current MFA.'); }
            $current=$this->pdo->query('SELECT approved_by,policy_version FROM safeguarding_backup_retention_policies WHERE singleton_id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (is_array($current)&&$current['approved_by']===$actor->id) {
                throw new DomainException('A different Owner or Super Admin must approve a changed safeguarding backup policy.');
            }
            $save=$this->pdo->prepare(
                'INSERT INTO safeguarding_backup_retention_policies
                 (singleton_id,policy_version,expiry_days,encrypted_backups,enabled,approved_by,approved_at,created_at,updated_at)
                 VALUES (1,:version,:days,1,1,:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE policy_version=VALUES(policy_version),expiry_days=VALUES(expiry_days),
                    encrypted_backups=1,enabled=1,approved_by=VALUES(approved_by),approved_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()'
            );
            $save->execute(['version'=>$policyVersion,'days'=>$expiryDays,'actor'=>$actor->id]);
            $event=$this->pdo->prepare(
                'INSERT INTO safeguarding_backup_policy_events
                 (id,policy_version,actor_user_id,event_key,expiry_days,encrypted_backups,reason,occurred_at)
                 VALUES (:id,:version,:actor,"backup_policy.approved",:days,1,:reason,UTC_TIMESTAMP())'
            );
            $event->execute(['id'=>self::uuid(),'version'=>$policyVersion,'actor'=>$actor->id,'days'=>$expiryDays,'reason'=>$reason]);
            $this->audit->record($actor->id,'safeguarding.backup_policy.approved','safeguarding_backup_policy','singleton-1',$reason,null,
                ['policy_version'=>$policyVersion,'expiry_days'=>$expiryDays,'encrypted_backups'=>true],$requestId,'safeguarding-backup-policy-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function current(): ?array
    {
        $row=$this->pdo->query(
            'SELECT p.policy_version,p.expiry_days,p.encrypted_backups,p.enabled,p.approved_at,u.email AS approver_email
             FROM safeguarding_backup_retention_policies p JOIN users u ON u.id=p.approved_by
             WHERE p.singleton_id=1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$h=bin2hex($bytes);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
