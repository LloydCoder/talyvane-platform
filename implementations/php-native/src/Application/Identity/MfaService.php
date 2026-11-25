<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Principal;
use Talyvane\Security\RecoveryCodes;
use Talyvane\Security\SecretBox;
use Talyvane\Security\Totp;

final class MfaService
{
    public function __construct(
        private PDO $pdo,
        private SecretBox $secrets,
        private AuditLogger $audit
    ) {}

    /** Returns a Base32 secret once; caller must display it only over a no-store HTTPS response. */
    public function beginEnrollment(Principal $actor): string
    {
        $this->assertPrivilegedRole($actor);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT enabled_at FROM admin_mfa_credentials WHERE user_id = :user FOR UPDATE');
            $query->execute(['user'=>$actor->id]);
            $row = $query->fetch();
            if (is_array($row) && $row['enabled_at'] !== null) {
                throw new \DomainException('MFA is already enabled; use the governed reset process.');
            }
            $secret = Totp::generateSecret();
            $encrypted = $this->secrets->encrypt($secret, 'totp:' . $actor->id);
            $statement = $this->pdo->prepare(
                'INSERT INTO admin_mfa_credentials (user_id, encrypted_totp_secret, encryption_key_version, enabled_at, last_accepted_counter, created_at, updated_at)
                 VALUES (:user, :secret, :version, NULL, -1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE encrypted_totp_secret = VALUES(encrypted_totp_secret), encryption_key_version = VALUES(encryption_key_version), updated_at = UTC_TIMESTAMP()'
            );
            $statement->execute(['user'=>$actor->id,'secret'=>$encrypted,'version'=>'app-key-v1']);
            $this->pdo->commit();
            return $secret;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Returns one-time plaintext recovery codes after enrollment is committed. */
    public function confirmEnrollment(Principal $actor, string $code, string $requestId): array
    {
        $this->assertPrivilegedRole($actor);
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT encrypted_totp_secret, enabled_at, last_accepted_counter FROM admin_mfa_credentials WHERE user_id = :user FOR UPDATE');
            $query->execute(['user'=>$actor->id]);
            $row = $query->fetch();
            if (!is_array($row) || $row['enabled_at'] !== null) {
                throw new \DomainException('MFA enrollment is not pending.');
            }
            $secret = $this->secrets->decrypt((string) $row['encrypted_totp_secret'], 'totp:' . $actor->id);
            $counter = Totp::verify($secret, $code, time(), (int) $row['last_accepted_counter']);
            if ($counter === null) {
                throw new \DomainException('Authenticator code is invalid or has already been used.');
            }
            $update = $this->pdo->prepare('UPDATE admin_mfa_credentials SET enabled_at = UTC_TIMESTAMP(), last_accepted_counter = :counter, updated_at = UTC_TIMESTAMP() WHERE user_id = :user AND enabled_at IS NULL');
            $update->execute(['counter'=>$counter,'user'=>$actor->id]);
            if ($update->rowCount() !== 1) {
                throw new \DomainException('MFA enrollment state changed; retry the process.');
            }
            $issued = RecoveryCodes::issue(10);
            $insert = $this->pdo->prepare('INSERT INTO admin_mfa_recovery_codes (id, user_id, code_hash, used_at, created_at) VALUES (:id, :user, :hash, NULL, UTC_TIMESTAMP())');
            foreach ($issued['hashes'] as $hash) {
                $insert->execute(['id'=>self::uuid(),'user'=>$actor->id,'hash'=>$hash]);
            }
            $this->audit->record($actor->id,'admin.mfa.enrolled','user',$actor->id,'MFA enrollment completed',null,['mfa_enabled'=>true,'recovery_codes_issued'=>count($issued['hashes'])],$requestId,'admin-mfa-v1');
            $this->pdo->commit();
            return $issued['plain'];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function verifyChallenge(string $userId, string $code): bool
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT encrypted_totp_secret, enabled_at, last_accepted_counter FROM admin_mfa_credentials WHERE user_id = :user FOR UPDATE');
            $query->execute(['user'=>$userId]);
            $row = $query->fetch();
            if (!is_array($row) || $row['enabled_at'] === null) {
                throw new \DomainException('MFA is not enrolled for this account.');
            }
            $secret = $this->secrets->decrypt((string) $row['encrypted_totp_secret'], 'totp:' . $userId);
            $counter = Totp::verify($secret, $code, time(), (int) $row['last_accepted_counter']);
            if ($counter === null) {
                $this->pdo->commit();
                return false;
            }
            $update = $this->pdo->prepare('UPDATE admin_mfa_credentials SET last_accepted_counter = :counter, updated_at = UTC_TIMESTAMP() WHERE user_id = :user AND last_accepted_counter < :counter');
            $update->execute(['counter'=>$counter,'user'=>$userId]);
            $valid = $update->rowCount() === 1;
            $this->pdo->commit();
            return $valid;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function consumeRecoveryCode(string $userId, string $submitted, string $requestId): bool
    {
        if (!preg_match('/^[A-Fa-f0-9]{20}$/D', $submitted)) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT id, code_hash FROM admin_mfa_recovery_codes WHERE user_id = :user AND used_at IS NULL FOR UPDATE');
            $query->execute(['user'=>$userId]);
            foreach ($query->fetchAll() as $row) {
                if (password_verify(strtoupper($submitted), (string) $row['code_hash'])) {
                    $update = $this->pdo->prepare('UPDATE admin_mfa_recovery_codes SET used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL');
                    $update->execute(['id'=>$row['id']]);
                    if ($update->rowCount() !== 1) {
                        throw new \DomainException('Recovery code was already consumed.');
                    }
                    $this->audit->record($userId,'admin.mfa.recovery_code.used','user',$userId,'One-time MFA recovery code used',null,['recovery_code_consumed'=>true],$requestId,'admin-mfa-v1');
                    $this->pdo->commit();
                    return true;
                }
            }
            $this->pdo->commit();
            return false;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertPrivilegedRole(Principal $actor): void
    {
        if (array_intersect($actor->roles, ['super_admin','owner','admin','finance','verifier','safeguarding']) === []) {
            throw new \DomainException('MFA enrollment is restricted to privileged accounts.');
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
