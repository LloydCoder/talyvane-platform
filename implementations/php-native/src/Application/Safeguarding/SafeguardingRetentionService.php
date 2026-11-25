<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use PDO;
use RuntimeException;
use Throwable;

/** Applies approved retention only when external per-case key destruction is available. */
final class SafeguardingRetentionService
{
    public function __construct(private PDO $pdo,private SafeguardingCryptoService $crypto) {}

    public function apply(int $limit=50): int
    {
        if ($limit<1 || $limit>200) { throw new \InvalidArgumentException('Retention batch size must be between 1 and 200.'); }
        if (!$this->crypto->supportsDestruction()) {
            throw new RuntimeException('Safeguarding retention is blocked: local application-key custody cannot guarantee cryptographic erasure across backups. Configure and verify external Vault Transit key custody.');
        }
        if (getenv('SAFEGUARDING_CASE_RETENTION_SCHEDULE_APPROVED')!=='true' ||
            getenv('SAFEGUARDING_CASE_BACKUP_POLICY_APPROVED')!=='true') {
            throw new RuntimeException('Safeguarding retention is disabled until both the retention schedule and encrypted-backup expiry policy are approved.');
        }
        $days=filter_var(getenv('SAFEGUARDING_CASE_RETENTION_DAYS'),FILTER_VALIDATE_INT);
        $backupDays=filter_var(getenv('SAFEGUARDING_CASE_BACKUP_EXPIRY_DAYS'),FILTER_VALIDATE_INT);
        $version=trim((string)getenv('SAFEGUARDING_CASE_RETENTION_POLICY_VERSION'));
        if (!is_int($days)||$days<1||!is_int($backupDays)||$backupDays<1||$version===''||strlen($version)>64) {
            throw new RuntimeException('Safeguarding retention requires positive retention and backup-expiry periods and a named policy version.');
        }
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
        $ids=$query->fetchAll(PDO::FETCH_COLUMN);
        $erased=0;
        foreach ($ids as $id) {
            if ($this->eraseIfDue((string)$id,$days,$backupDays,$version)) { $erased++; }
        }
        return $erased;
    }

    private function eraseIfDue(string $caseId,int $days,int $backupDays,string $policyVersion): bool
    {
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,category,status,legal_hold,encrypted_case_key,retention_due_at,
                        retention_applied_at,retention_policy_version,encryption_provider
                 FROM safeguarding_cases WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$caseId]);
            $case=$query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($case) || $case['status']!=='closed' || (int)$case['legal_hold']===1 ||
                $case['retention_applied_at']!==null || !is_string($case['encrypted_case_key']) ||
                $case['encryption_provider']!==$this->crypto->providerName() ||
                !is_string($case['retention_due_at']) || strtotime($case['retention_due_at'].' UTC')>time() ||
                !hash_equals($policyVersion,(string)$case['retention_policy_version'])) {
                $this->pdo->commit();
                return false;
            }
            $policy=$this->pdo->prepare(
                'SELECT retention_days,policy_version,legal_basis_reference,enabled
                 FROM safeguarding_retention_policies WHERE category=:category FOR UPDATE'
            );
            $policy->execute(['category'=>$case['category']]);
            $row=$policy->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (int)$row['enabled']!==1 || (int)$row['retention_days']!==$days ||
                !hash_equals($policyVersion,(string)$row['policy_version']) ||
                trim((string)$row['legal_basis_reference'])==='') {
                $this->pdo->commit();
                return false;
            }

            // Remote key destruction happens before local ciphertext deletion. A retry
            // is safe because the Vault adapter treats an already-absent key as erased.
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
                     retention_applied_at=UTC_TIMESTAMP(),retention_policy_version=:version,
                     updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND legal_hold=0 AND retention_applied_at IS NULL'
            );
            $update->execute(['version'=>$policyVersion,'id'=>$caseId]);
            if ($update->rowCount()!==1) { throw new RuntimeException('Safeguarding case changed during retention erasure.'); }
            $this->pdo->commit();
            return true;
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
