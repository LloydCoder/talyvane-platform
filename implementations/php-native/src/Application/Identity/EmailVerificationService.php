<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Infrastructure\Security\RateLimiter;

final class EmailVerificationService
{
    private const PURPOSES = ['account_primary','admin_primary','admin_recovery','privacy_rights'];

    public function __construct(
        private PDO $pdo,
        private Mailer $mailer,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private string $pepper
    ) {
        if (strlen($pepper) < 32) {
            throw new \InvalidArgumentException('Email verification key material must contain at least 32 bytes.');
        }
    }

    public function issue(string $userId, string $email, string $purpose): void
    {
        $email = strtolower(trim($email));
        if (!in_array($purpose, self::PURPOSES, true) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new \InvalidArgumentException('Email verification request is invalid.');
        }
        if (!$this->limiter->consume('email.issue', $userId . '|' . $purpose, 3, 3600, 3600)) {
            throw new \DomainException('Email verification delivery is temporarily rate limited.');
        }
        $userQuery = $this->pdo->prepare('SELECT email FROM users WHERE id = :user LIMIT 1');
        $userQuery->execute(['user'=>$userId]);
        $primary = $userQuery->fetchColumn();
        if (!is_string($primary)) {
            throw new \DomainException('Account does not exist.');
        }
        $primary = strtolower($primary);
        if (in_array($purpose, ['account_primary','admin_primary','privacy_rights'], true) && !hash_equals($primary, $email)) {
            throw new \DomainException('Primary verification address does not match the account.');
        }
        if ($purpose === 'admin_recovery' && hash_equals($primary, $email)) {
            throw new \DomainException('Super Admin recovery email must be distinct from the primary address.');
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challengeId = self::uuid();
        $hash = hash_hmac('sha256', $code, $this->pepper);
        $this->pdo->beginTransaction();
        try {
            $consume = $this->pdo->prepare('UPDATE email_verification_challenges SET consumed_at = UTC_TIMESTAMP() WHERE user_id = :user AND purpose = :purpose AND consumed_at IS NULL');
            $consume->execute(['user'=>$userId,'purpose'=>$purpose]);
            $insert = $this->pdo->prepare(
                'INSERT INTO email_verification_challenges (id,user_id,email_address,purpose,code_hash,expires_at,attempts,verified_at,consumed_at,created_at)
                 VALUES (:id,:user,:email,:purpose,:hash,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE),0,NULL,NULL,UTC_TIMESTAMP())'
            );
            $insert->execute(['id'=>$challengeId,'user'=>$userId,'email'=>$email,'purpose'=>$purpose,'hash'=>$hash]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        try {
            $this->mailer->send($email, 'Your Talyvane verification code', "Your verification code is: {$code}\n\nIt expires in 10 minutes. If you did not request this code, ignore this message.");
        } catch (\Throwable $error) {
            $invalidate = $this->pdo->prepare('UPDATE email_verification_challenges SET consumed_at = UTC_TIMESTAMP() WHERE id = :id AND consumed_at IS NULL');
            $invalidate->execute(['id'=>$challengeId]);
            throw new \RuntimeException('Verification email could not be delivered. Check the configured SMTP service.', 0, $error);
        }
    }

    public function verify(string $userId, string $email, string $purpose, string $code): bool
    {
        $email = strtolower(trim($email));
        if (!in_array($purpose, self::PURPOSES, true) || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !preg_match('/^[0-9]{6}$/D', $code)) {
            return false;
        }
        if (!$this->limiter->consume('email.verify', $userId . '|' . $purpose, 5, 300, 900)) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT id,code_hash,expires_at,attempts FROM email_verification_challenges
                 WHERE user_id = :user AND email_address = :email AND purpose = :purpose
                 AND consumed_at IS NULL AND verified_at IS NULL
                 ORDER BY created_at DESC LIMIT 1 FOR UPDATE'
            );
            $query->execute(['user'=>$userId,'email'=>$email,'purpose'=>$purpose]);
            $challenge = $query->fetch();
            if (!is_array($challenge) || (int) $challenge['attempts'] >= 5 ||
                strtotime((string) $challenge['expires_at'] . ' UTC') <= time()) {
                $this->pdo->commit();
                return false;
            }
            $submittedHash = hash_hmac('sha256', $code, $this->pepper);
            if (!hash_equals((string) $challenge['code_hash'], $submittedHash)) {
                $attempt = $this->pdo->prepare('UPDATE email_verification_challenges SET attempts = attempts + 1 WHERE id = :id AND consumed_at IS NULL');
                $attempt->execute(['id'=>$challenge['id']]);
                $this->pdo->commit();
                return false;
            }
            $update = $this->pdo->prepare('UPDATE email_verification_challenges SET verified_at = UTC_TIMESTAMP(), consumed_at = UTC_TIMESTAMP() WHERE id = :id AND consumed_at IS NULL');
            $update->execute(['id'=>$challenge['id']]);
            if ($purpose === 'account_primary' || $purpose === 'admin_primary') {
                $userUpdate = $this->pdo->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :user AND LOWER(email) = :email');
                $userUpdate->execute(['user'=>$userId,'email'=>$email]);
                if ($userUpdate->rowCount() !== 1) {
                    throw new \DomainException('Account email changed during verification.');
                }
            }
            $this->pdo->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function bothAdminAddressesVerified(string $userId, string $primaryEmail, string $recoveryEmail): bool
    {
        $primaryEmail = strtolower(trim($primaryEmail));
        $recoveryEmail = strtolower(trim($recoveryEmail));
        if (!filter_var($primaryEmail, FILTER_VALIDATE_EMAIL) || !filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL) ||
            hash_equals($primaryEmail, $recoveryEmail)) {
            return false;
        }
        return $this->isVerified($userId,$primaryEmail,'admin_primary')
            && $this->isVerified($userId,$recoveryEmail,'admin_recovery');
    }

    public function activateSuperAdmin(string $userId, string $primaryEmail, string $recoveryEmail, string $offlineRecoveryKey, string $requestId): void
    {
        if (!$this->bothAdminAddressesVerified($userId,$primaryEmail,$recoveryEmail) || strlen($offlineRecoveryKey) < 32) {
            throw new \DomainException('Both distinct Super Admin email addresses must be verified before activation.');
        }
        $this->pdo->beginTransaction();
        try {
            $userQuery = $this->pdo->prepare('SELECT email,status FROM users WHERE id = :user FOR UPDATE');
            $userQuery->execute(['user'=>$userId]);
            $user = $userQuery->fetch();
            if (!is_array($user) || strtolower((string) $user['email']) !== strtolower($primaryEmail) || $user['status'] !== 'pending') {
                throw new \DomainException('Super Admin bootstrap account is not eligible for activation.');
            }
            $activate = $this->pdo->prepare('UPDATE users SET status = "active", email_verified_at = UTC_TIMESTAMP(), first_login_required = 1, updated_at = UTC_TIMESTAMP() WHERE id = :user AND status = "pending"');
            $activate->execute(['user'=>$userId]);
            if ($activate->rowCount() !== 1) {
                throw new \DomainException('Super Admin account state changed during activation.');
            }
            $role = $this->pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:user,"super_admin",NULL,UTC_TIMESTAMP())');
            $role->execute(['user'=>$userId]);
            $recovery = $this->pdo->prepare('INSERT INTO super_admin_recovery (singleton_id,super_admin_user_id,recovery_email,offline_recovery_key_hash,recovery_version,enrolled_at,updated_at) VALUES (1,:user,:email,:hash,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $recovery->execute([
                'user'=>$userId,
                'email'=>strtolower($recoveryEmail),
                'hash'=>password_hash($offlineRecoveryKey, PASSWORD_DEFAULT),
            ]);
            $this->audit->record($userId,'super_admin.bootstrap.activated','user',$userId,'Both Super Admin email addresses verified',null,['status'=>'active','primary_verified'=>true,'recovery_verified'=>true],$requestId,'super-admin-bootstrap-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function isVerified(string $userId, string $email, string $purpose): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM email_verification_challenges WHERE user_id = :user AND email_address = :email AND purpose = :purpose AND verified_at IS NOT NULL ORDER BY verified_at DESC LIMIT 1');
        $statement->execute(['user'=>$userId,'email'=>strtolower($email),'purpose'=>$purpose]);
        return $statement->fetchColumn() !== false;
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
