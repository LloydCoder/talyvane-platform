<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class SafeguardingRestoreVerificationService
{
    public function __construct(private PDO $pdo,private SafeguardingCryptoService $crypto,private AuditLogger $audit) {}

    /** @return array{active_keys_verified:int,orphan_keys_destroyed:int} */
    public function verify(Principal $actor,string $backupReference,string $manifestSha256,string $policyVersion,string $requestId): array
    {
        Authorization::require($actor,'safeguarding.governance',true);
        if (!$actor->hasRole('owner')&&!$actor->hasRole('super_admin')) {
            throw new DomainException('Only an MFA-enrolled Owner or Super Admin may verify a safeguarding restore.');
        }
        $backupReference=trim($backupReference);$policyVersion=trim($policyVersion);
        if (strlen($backupReference)<8||strlen($backupReference)>256||preg_match('/[\r\n\0]/',$backupReference)||
            !preg_match('/^[a-f0-9]{64}$/D',$manifestSha256)||
            !preg_match('/^[a-zA-Z0-9._-]{3,64}$/D',$policyVersion)||
            !preg_match('/^[a-f0-9]{16,64}$/D',$requestId)) {
            throw new InvalidArgumentException('Backup reference, manifest SHA-256, policy version or request reference is invalid.');
        }
        $this->assertLiveActor($actor);
        $policy=$this->pdo->query(
            'SELECT policy_version,expiry_days,encrypted_backups,enabled,approved_by
             FROM safeguarding_backup_retention_policies WHERE singleton_id=1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($policy)||(int)$policy['enabled']!==1||(int)$policy['encrypted_backups']!==1||
            !hash_equals($policyVersion,(string)$policy['policy_version'])||$policy['approved_by']===$actor->id) {
            throw new DomainException('A distinct Owner or Super Admin must approve the encrypted-backup policy before restore verification.');
        }
        $keys=$this->pdo->query(
            'SELECT id,encrypted_case_key FROM safeguarding_cases
             WHERE encryption_provider="vault-transit" AND encrypted_case_key IS NOT NULL'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $result=$this->crypto->reconcileVault($keys);
        $id=self::uuid();
        $insert=$this->pdo->prepare(
            'INSERT INTO safeguarding_restore_verifications
             (id,backup_reference_hash,manifest_sha256,policy_version,verification_status,
              active_keys_verified,orphan_keys_destroyed,verified_by,verified_at,created_at)
             VALUES (:id,:backup,:manifest,:version,"passed",:active,:orphans,:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        $insert->execute([
            'id'=>$id,'backup'=>hash('sha256',$backupReference),'manifest'=>$manifestSha256,
            'version'=>$policyVersion,'active'=>$result['active_keys_verified'],
            'orphans'=>$result['orphan_keys_destroyed'],'actor'=>$actor->id,
        ]);
        $this->audit->record($actor->id,'safeguarding.restore.verified','safeguarding_restore_verification',$id,
            'Independent restore manifest recorded and Vault active-key reconciliation completed.',
            null,['policy_version'=>$policyVersion,'active_keys_verified'=>$result['active_keys_verified'],
                'orphan_keys_destroyed'=>$result['orphan_keys_destroyed'],'manifest_sha256'=>$manifestSha256],
            $requestId,'safeguarding-restore-v1');
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function recent(): array
    {
        $query=$this->pdo->query(
            'SELECT v.backup_reference_hash,v.manifest_sha256,v.policy_version,v.active_keys_verified,
                    v.orphan_keys_destroyed,v.verified_at,u.email AS verifier_email
             FROM safeguarding_restore_verifications v JOIN users u ON u.id=v.verified_by
             ORDER BY v.verified_at DESC LIMIT 10'
        );
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertLiveActor(Principal $actor): void
    {
        $query=$this->pdo->prepare(
            'SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_key IN ("owner","super_admin")
             JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
             WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1'
        );
        $query->execute(['id'=>$actor->id]);
        if ($query->fetchColumn()===false) { throw new DomainException('Restore verification requires an active, verified account with current MFA.'); }
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$h=bin2hex($bytes);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
