<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** Applies approved retention only when external per-case key destruction is available. */
final class SafeguardingRetentionService
{
    public function __construct(private PDO $pdo,private SafeguardingCryptoService $crypto) {}

    public function apply(int $limit=50): int
    {
        if ($limit<1||$limit>200) { throw new \InvalidArgumentException('Retention batch size must be between 1 and 200.'); }
        if (!$this->crypto->supportsDestruction()) {
            throw new RuntimeException('Safeguarding retention is blocked: local application-key custody cannot guarantee cryptographic erasure across backups. Configure and verify external Vault Transit key custody.');
        }
        $backup=$this->pdo->query(
            'SELECT policy_version,expiry_days,encrypted_backups,enabled,approved_by
             FROM safeguarding_backup_retention_policies WHERE singleton_id=1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($backup)||(int)$backup['enabled']!==1||(int)$backup['encrypted_backups']!==1||
            (int)$backup['expiry_days']<1||trim((string)$backup['policy_version'])==='') {
            throw new RuntimeException('Safeguarding retention is blocked until an encrypted-backup expiry policy is approved.');
        }
        $approver=$this->pdo->prepare(
            'SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_key IN ("owner","super_admin")
             JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
             WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1'
        );
        $approver->execute(['id'=>$backup['approved_by']]);
        if ($approver->fetchColumn()===false) { throw new RuntimeException('Backup policy approver is no longer an active MFA-enrolled Owner or Super Admin.'); }
        $restore=$this->pdo->prepare(
            'SELECT 1 FROM safeguarding_restore_verifications v
             JOIN users u ON u.id=v.verified_by AND u.status="active"
             JOIN user_roles r ON r.user_id=u.id AND r.role_key IN ("owner","super_admin")
             JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
             WHERE v.policy_version=:version AND v.verification_status="passed"
               AND v.verified_at>=UTC_TIMESTAMP()-INTERVAL 90 DAY
               AND v.verified_by<>:approver LIMIT 1'
        );
        $restore->execute(['version'=>$backup['policy_version'],'approver'=>$backup['approved_by']]);
        if ($restore->fetchColumn()===false) {
            throw new RuntimeException('Safeguarding retention requires a recent successful Vault restore and key-reconciliation verification by a second MFA-enrolled Owner or Super Admin.');
        }
        $this->scheduleEligibleClosedCases((int)$backup['expiry_days'],(string)$backup['policy_version']);
        $query=$this->pdo->prepare(
            'SELECT id FROM safeguarding_cases
             WHERE status="closed" AND legal_hold=0 AND retention_due_at IS NOT NULL
               AND retention_due_at<=UTC_TIMESTAMP() AND retention_applied_at IS NULL
               AND encrypted_case_key IS NOT NULL AND encryption_provider=:provider
             ORDER BY retention_due_at ASC,created_at ASC LIMIT :limit'
        );
        $query->bindValue(':provider',$this->crypto->providerName(),PDO::PARAM_STR);
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->execute();
        $erased=0;
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ($this->eraseIfDue((string)$id,(int)$backup['expiry_days'],(string)$backup['policy_version'])) { $erased++; }
        }
        return $erased;
    }

    private function scheduleEligibleClosedCases(int $backupDays,string $policyVersion): void
    {
        $query=$this->pdo->prepare(
            'SELECT c.id,c.closed_at,c.retention_due_at,p.retention_days
             FROM safeguarding_cases c
             JOIN safeguarding_retention_policies p ON p.category=c.category AND p.enabled=1 AND p.policy_version=:version
             WHERE c.status="closed" AND c.legal_hold=0 AND c.retention_applied_at IS NULL
               AND c.encrypted_case_key IS NOT NULL AND c.encryption_provider=:provider
               AND c.closed_at IS NOT NULL'
        );
        $query->execute(['version'=>$policyVersion,'provider'=>$this->crypto->providerName()]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $minimum=(new DateTimeImmutable((string)$row['closed_at'],new DateTimeZone('UTC')))
                ->modify('+'.((int)$row['retention_days']+$backupDays).' days');
            $existing=is_string($row['retention_due_at'])&&$row['retention_due_at']!==''
                ? new DateTimeImmutable($row['retention_due_at'],new DateTimeZone('UTC'))
                : $minimum;
            $due=$existing>$minimum?$existing:$minimum;
            $update=$this->pdo->prepare(
                'UPDATE safeguarding_cases
                 SET retention_due_at=:due,retention_policy_version=:version,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status="closed" AND legal_hold=0 AND retention_applied_at IS NULL'
            );
            $update->execute(['due'=>$due->format('Y-m-d H:i:s'),'version'=>$policyVersion,'id'=>$row['id']]);
        }
    }

    private function eraseIfDue(string $caseId,int $backupDays,string $policyVersion): bool
    {
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,category,status,legal_hold,encrypted_case_key,retention_due_at,closed_at,
                        retention_applied_at,retention_policy_version,encryption_provider
                 FROM safeguarding_cases WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$caseId]);
            $case=$query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($case)||$case['status']!=='closed'||(int)$case['legal_hold']===1||
                $case['retention_applied_at']!==null||!is_string($case['encrypted_case_key'])||
                $case['encryption_provider']!==$this->crypto->providerName()||
                !is_string($case['retention_due_at'])||!is_string($case['closed_at'])||$case['closed_at']===''||strtotime($case['retention_due_at'].' UTC')>time()||
                !hash_equals($policyVersion,(string)$case['retention_policy_version'])) {
                $this->pdo->commit();return false;
            }
            $policy=$this->pdo->prepare(
                'SELECT retention_days,policy_version,legal_basis_reference,enabled
                 FROM safeguarding_retention_policies WHERE category=:category FOR UPDATE'
            );
            $policy->execute(['category'=>$case['category']]);
            $row=$policy->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)||(int)$row['enabled']!==1||(int)$row['retention_days']<1||
                !hash_equals($policyVersion,(string)$row['policy_version'])||trim((string)$row['legal_basis_reference'])==='') {
                $this->pdo->commit();return false;
            }
            $minimum=(new DateTimeImmutable((string)$case['closed_at'],new DateTimeZone('UTC')))
                ->modify('+'.((int)$row['retention_days']+$backupDays).' days');
            $scheduled=new DateTimeImmutable((string)$case['retention_due_at'],new DateTimeZone('UTC'));
            if ($scheduled<$minimum) { $this->pdo->commit();return false; }

            // Destroy the external per-case key before removing the local envelope.
            // A retry is safe because an already-absent Vault key is treated as erased.
            $this->crypto->destroyCaseKey($caseId,$case['encrypted_case_key']);
            $event=$this->pdo->prepare(
                'INSERT INTO safeguarding_case_events
                 (id,case_id,actor_user_id,actor_kind,event_key,from_status,to_status,encrypted_note,created_at)
                 VALUES (:id,:case,NULL,"system","case.retention.erased","closed","closed",NULL,UTC_TIMESTAMP())'
            );
            $event->execute(['id'=>self::uuid(),'case'=>$caseId]);
            $update=$this->pdo->prepare(
                'UPDATE safeguarding_cases
                 SET encrypted_case_key=NULL,summary_ciphertext=NULL,details_ciphertext=NULL,
                     subject_reference_ciphertext=NULL,legal_hold_reason_ciphertext=NULL,
                     retention_applied_at=UTC_TIMESTAMP(),retention_policy_version=:version,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND legal_hold=0 AND retention_applied_at IS NULL'
            );
            $update->execute(['version'=>$policyVersion,'id'=>$caseId]);
            if ($update->rowCount()!==1) { throw new RuntimeException('Safeguarding case changed during retention erasure.'); }
            $this->pdo->commit();return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
