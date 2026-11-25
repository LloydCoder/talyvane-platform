<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Installer\SetupKey;

final class EmailVerificationService
{
    private const PURPOSES = ['account_primary','account_recovery','admin_primary','admin_recovery','privacy_rights'];

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
        if (in_array($purpose, ['account_primary','account_recovery','admin_primary','privacy_rights'], true) && !hash_equals($primary, $email)) {
            throw new \DomainException('Primary verification address does not match the account.');
        }
        if ($purpose === 'admin_recovery' && hash_equals($primary, $email)) {
            throw new \DomainException('Super Admin recovery email must be distinct from the primary address.');
        }
        if ($purpose === 'admin_primary' || $purpose === 'admin_recovery') {
            $bootstrap = $this->pdo->prepare('SELECT 1 FROM super_admin_bootstrap_challenges WHERE singleton_id = 1 AND user_id = :user AND ((primary_email = :primary_email) OR (recovery_email = :recovery_email)) AND activated_at IS NULL AND expires_at > UTC_TIMESTAMP()');
            $bootstrap->execute([
                'user'=>$userId,
                'primary_email'=>$purpose === 'admin_primary' ? $email : null,
                'recovery_email'=>$purpose === 'admin_recovery' ? $email : null,
            ]);
            $bootstrapPending = $bootstrap->fetchColumn() !== false;
            $existingRecovery = false;
            if ($purpose === 'admin_recovery') {
                $existing = $this->pdo->prepare('SELECT 1 FROM super_admin_recovery WHERE super_admin_user_id = :user AND recovery_email = :email');
                $existing->execute(['user'=>$userId,'email'=>$email]);
                $existingRecovery = $existing->fetchColumn() !== false;
            }
            if (!$bootstrapPending && !$existingRecovery) {
                throw new \DomainException('Administrative email verification requires an active Super Admin bootstrap or recovery record.');
            }
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
            if ($purpose === 'account_primary') {
                $userUpdate = $this->pdo->prepare('UPDATE users SET status = "active", email_verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :user AND LOWER(email) = :email AND status = "pending"');
                $userUpdate->execute(['user'=>$userId,'email'=>$email]);
                if ($userUpdate->rowCount() !== 1) {
                    throw new \DomainException('Pending account email changed or was already activated.');
                }
            } elseif ($purpose === 'admin_primary') {
                $userUpdate = $this->pdo->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :user AND LOWER(email) = :email AND status = "pending"');
                $userUpdate->execute(['user'=>$userId,'email'=>$email]);
                if ($userUpdate->rowCount() !== 1) {
                    throw new \DomainException('Pending administrator email changed or was already activated.');
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

    /** @return array{user_id:string,bootstrap_proof:string,primary_email:string,recovery_email:string} */
    public function prepareSuperAdminBootstrap(SetupKey $setupKey, string $submittedSetupKey, string $primaryEmail, string $recoveryEmail): array
    {
        if (!$setupKey->verify($submittedSetupKey)) {
            throw new \DomainException('Installer setup key is invalid.');
        }
        $primaryEmail = strtolower(trim($primaryEmail));
        $recoveryEmail = strtolower(trim($recoveryEmail));
        if (!filter_var($primaryEmail,FILTER_VALIDATE_EMAIL) || !filter_var($recoveryEmail,FILTER_VALIDATE_EMAIL) ||
            hash_equals($primaryEmail,$recoveryEmail)) {
            throw new \InvalidArgumentException('Super Admin requires two distinct valid email addresses.');
        }
        $existingBootstrap = $this->pdo->query('SELECT user_id,activated_at,expires_at FROM super_admin_bootstrap_challenges WHERE singleton_id = 1')->fetch();
        if (is_array($existingBootstrap)) {
            if ($existingBootstrap['activated_at'] !== null || strtotime((string) $existingBootstrap['expires_at'] . ' UTC') > time()) {
                throw new \DomainException('A Super Admin bootstrap is already active or has been completed.');
            }
            $this->pdo->beginTransaction();
            try {
                $oldUser = (string) $existingBootstrap['user_id'];
                $deleteChallenges = $this->pdo->prepare('DELETE FROM email_verification_challenges WHERE user_id = :user');
                $deleteChallenges->execute(['user'=>$oldUser]);
                $deleteBootstrap = $this->pdo->prepare('DELETE FROM super_admin_bootstrap_challenges WHERE singleton_id = 1 AND activated_at IS NULL');
                $deleteBootstrap->execute();
                $deleteUser = $this->pdo->prepare('DELETE FROM users WHERE id = :user AND status = \'pending\'');
                $deleteUser->execute(['user'=>$oldUser]);
                $this->pdo->commit();
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                throw $error;
            }
        }
        if ($this->pdo->query('SELECT COUNT(*) FROM super_admin_recovery')->fetchColumn() > 0 ||
            $this->pdo->query('SELECT COUNT(*) FROM super_admin_bootstrap_challenges WHERE activated_at IS NULL AND expires_at > UTC_TIMESTAMP()')->fetchColumn() > 0) {
            throw new \DomainException('A Super Admin bootstrap is already active or has been completed.');
        }
        $userId = self::uuid();
        $bootstrapProof = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $temporaryPassword = bin2hex(random_bytes(32));
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = :email');
            $existing->execute(['email'=>$primaryEmail]);
            if ((int) $existing->fetchColumn() > 0) {
                throw new \DomainException('Primary Super Admin email already belongs to an account.');
            }
            $create = $this->pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at) VALUES (:id,:email,:hash,"pending",NULL,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $create->execute(['id'=>$userId,'email'=>$primaryEmail,'hash'=>password_hash($temporaryPassword,PASSWORD_DEFAULT)]);
            $bootstrap = $this->pdo->prepare('INSERT INTO super_admin_bootstrap_challenges (singleton_id,user_id,primary_email,recovery_email,proof_hash,expires_at,activated_at,created_at) VALUES (1,:user,:primary,:recovery,:proof,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE),NULL,UTC_TIMESTAMP())');
            $bootstrap->execute([
                'user'=>$userId,
                'primary'=>$primaryEmail,
                'recovery'=>$recoveryEmail,
                'proof'=>hash_hmac('sha256',$bootstrapProof,$this->pepper),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        try {
            $this->issue($userId,$primaryEmail,'admin_primary');
            $this->issue($userId,$recoveryEmail,'admin_recovery');
        } catch (\Throwable $error) {
            $this->pdo->beginTransaction();
            try {
                $deleteChallenges = $this->pdo->prepare('DELETE FROM email_verification_challenges WHERE user_id = :user');
                $deleteChallenges->execute(['user'=>$userId]);
                $deleteBootstrap = $this->pdo->prepare('DELETE FROM super_admin_bootstrap_challenges WHERE singleton_id = 1 AND user_id = :user AND activated_at IS NULL');
                $deleteBootstrap->execute(['user'=>$userId]);
                $deleteUser = $this->pdo->prepare('DELETE FROM users WHERE id = :user AND status = \'pending\'');
                $deleteUser->execute(['user'=>$userId]);
                $this->pdo->commit();
            } catch (\Throwable $cleanupError) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                throw new \RuntimeException('Email delivery failed and pending bootstrap cleanup also failed.',0,$cleanupError);
            }
            throw $error;
        }
        return ['user_id'=>$userId,'bootstrap_proof'=>$bootstrapProof,'primary_email'=>$primaryEmail,'recovery_email'=>$recoveryEmail];
    }

    public function resendSuperAdminVerification(SetupKey $setupKey, string $submittedSetupKey, string $bootstrapProof): void
    {
        if (!$setupKey->verify($submittedSetupKey)) {
            throw new \DomainException('Installer setup key is invalid.');
        }
        $query = $this->pdo->prepare('SELECT user_id,primary_email,recovery_email,proof_hash,expires_at,activated_at FROM super_admin_bootstrap_challenges WHERE singleton_id = 1 LIMIT 1');
        $query->execute();
        $row = $query->fetch();
        if (!is_array($row) || $row['activated_at'] !== null ||
            strtotime((string) $row['expires_at'] . ' UTC') <= time() ||
            !hash_equals((string) $row['proof_hash'],hash_hmac('sha256',$bootstrapProof,$this->pepper))) {
            throw new \DomainException('Super Admin bootstrap challenge is invalid or expired.');
        }
        $this->issue((string) $row['user_id'],(string) $row['primary_email'],'admin_primary');
        $this->issue((string) $row['user_id'],(string) $row['recovery_email'],'admin_recovery');
    }

    /** @return array{initial_password:string,offline_recovery_key:string} */
    public function activateSuperAdmin(string $userId, string $primaryEmail, string $recoveryEmail, string $bootstrapProof, string $requestId): array
    {
        if (!$this->bothAdminAddressesVerified($userId,$primaryEmail,$recoveryEmail) || strlen($bootstrapProof) !== 43) {
            throw new \DomainException('Both distinct Super Admin email addresses and the installer bootstrap proof are required.');
        }
        $initialPassword = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $offlineRecoveryKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->beginTransaction();
        try {
            $bootstrapQuery = $this->pdo->prepare('SELECT user_id,primary_email,recovery_email,proof_hash,expires_at,activated_at FROM super_admin_bootstrap_challenges WHERE singleton_id = 1 FOR UPDATE');
            $bootstrapQuery->execute();
            $bootstrap = $bootstrapQuery->fetch();
            if (!is_array($bootstrap) || $bootstrap['activated_at'] !== null ||
                (string) $bootstrap['user_id'] !== $userId ||
                strtolower((string) $bootstrap['primary_email']) !== strtolower($primaryEmail) ||
                strtolower((string) $bootstrap['recovery_email']) !== strtolower($recoveryEmail) ||
                strtotime((string) $bootstrap['expires_at'] . ' UTC') <= time() ||
                !hash_equals((string) $bootstrap['proof_hash'],hash_hmac('sha256',$bootstrapProof,$this->pepper))) {
                throw new \DomainException('Super Admin bootstrap proof is invalid, expired or already consumed.');
            }
            $userQuery = $this->pdo->prepare('SELECT email,status FROM users WHERE id = :user FOR UPDATE');
            $userQuery->execute(['user'=>$userId]);
            $user = $userQuery->fetch();
            if (!is_array($user) || strtolower((string) $user['email']) !== strtolower($primaryEmail) || $user['status'] !== 'pending') {
                throw new \DomainException('Super Admin bootstrap account is not eligible for activation.');
            }
            $activate = $this->pdo->prepare('UPDATE users SET password_hash = :password,status = "active",email_verified_at = UTC_TIMESTAMP(),first_login_required = 1,updated_at = UTC_TIMESTAMP() WHERE id = :user AND status = "pending"');
            $activate->execute(['password'=>password_hash($initialPassword,PASSWORD_DEFAULT),'user'=>$userId]);
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
            $consume = $this->pdo->prepare('UPDATE super_admin_bootstrap_challenges SET activated_at = UTC_TIMESTAMP() WHERE singleton_id = 1 AND activated_at IS NULL');
            $consume->execute();
            $this->audit->record($userId,'super_admin.bootstrap.activated','user',$userId,'Both Super Admin email addresses verified',null,['status'=>'active','primary_verified'=>true,'recovery_verified'=>true],$requestId,'super-admin-bootstrap-v1');
            $this->pdo->commit();
            return ['initial_password'=>$initialPassword,'offline_recovery_key'=>$offlineRecoveryKey];
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
