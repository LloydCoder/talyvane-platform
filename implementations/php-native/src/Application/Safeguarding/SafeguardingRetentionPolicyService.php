<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class SafeguardingRetentionPolicyService
{
    private const CATEGORIES=['safety_concern','exploitation','coercion','trafficking','minor_safety','harassment','violence','wellbeing','other'];

    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    public function save(Principal $actor,string $category,int $days,string $legalBasis,string $version,bool $enabled,string $reason,string $requestId): void
    {
        Authorization::require($actor,'safeguarding.governance',true);
        if (!$actor->hasRole('owner')&&!$actor->hasRole('super_admin')) {
            throw new DomainException('Only an MFA-enrolled Owner or Super Admin may change safeguarding retention policy.');
        }
        $legalBasis=trim($legalBasis);$version=trim($version);$reason=trim($reason);
        if (!in_array($category,self::CATEGORIES,true)||$days<1||$days>3650||
            strlen($legalBasis)<5||strlen($legalBasis)>190||
            !preg_match('/^[a-zA-Z0-9._-]{3,64}$/D',$version)||
            strlen($reason)<20||strlen($reason)>500||
            !preg_match('/^[a-f0-9]{16,64}$/D',$requestId)) {
            throw new InvalidArgumentException('Retention category, period, legal basis, policy version or reason is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $actorQuery=$this->pdo->prepare(
                'SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_key IN ("owner","super_admin")
                 JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
                 WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1 FOR UPDATE'
            );
            $actorQuery->execute(['id'=>$actor->id]);
            if ($actorQuery->fetchColumn()===false) { throw new DomainException('Retention policy changes require an active verified account with current MFA.'); }
            $backup=$this->pdo->query('SELECT policy_version,approved_by,enabled,encrypted_backups FROM safeguarding_backup_retention_policies WHERE singleton_id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($backup)||(int)$backup['enabled']!==1||(int)$backup['encrypted_backups']!==1||
                !hash_equals($version,(string)$backup['policy_version'])||$backup['approved_by']===$actor->id) {
                throw new DomainException('A different Owner or Super Admin must approve the encrypted-backup policy before this retention schedule can be activated.');
            }
            $save=$this->pdo->prepare(
                'INSERT INTO safeguarding_retention_policies
                 (category,retention_days,legal_basis_reference,policy_version,enabled,updated_by,updated_at)
                 VALUES (:category,:days,:basis,:version,:enabled,:actor,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE retention_days=VALUES(retention_days),legal_basis_reference=VALUES(legal_basis_reference),
                    policy_version=VALUES(policy_version),enabled=VALUES(enabled),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()'
            );
            $save->execute(['category'=>$category,'days'=>$days,'basis'=>$legalBasis,'version'=>$version,'enabled'=>$enabled?1:0,'actor'=>$actor->id]);
            $event=$this->pdo->prepare(
                'INSERT INTO safeguarding_retention_policy_events
                 (id,category,policy_version,actor_user_id,event_key,retention_days,legal_basis_reference,enabled,reason,occurred_at)
                 VALUES (:id,:category,:version,:actor,"retention_policy.updated",:days,:basis,:enabled,:reason,UTC_TIMESTAMP())'
            );
            $event->execute(['id'=>self::uuid(),'category'=>$category,'version'=>$version,'actor'=>$actor->id,'days'=>$days,'basis'=>$legalBasis,'enabled'=>$enabled?1:0,'reason'=>$reason]);
            $this->audit->record($actor->id,'safeguarding.retention_policy.updated','safeguarding_retention_policy',$category,$reason,null,
                ['policy_version'=>$version,'retention_days'=>$days,'enabled'=>$enabled],$requestId,'safeguarding-retention-policy-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT p.category,p.retention_days,p.legal_basis_reference,p.policy_version,p.enabled,
                    p.updated_at,u.email AS updated_by_email
             FROM safeguarding_retention_policies p JOIN users u ON u.id=p.updated_by
             ORDER BY p.category'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function uuid(): string
    {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
