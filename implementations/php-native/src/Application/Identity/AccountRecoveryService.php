<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\PasswordHasher;

/** Email-verified account recovery with one-time codes and global session revocation. */
final class AccountRecoveryService
{
    public function __construct(
        private PDO $pdo,
        private EmailVerificationService $verification,
        private RateLimiter $limiter,
        private AuditLogger $audit
    ) {}

    /** Always returns without revealing whether an account exists. */
    public function request(string $email): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return;
        }
        if (!$this->limiter->consume('account.recovery.request', $email, 3, 3600, 3600)) {
            return;
        }
        $query = $this->pdo->prepare('SELECT u.id,u.email FROM users u
             WHERE u.email = :email AND u.status = "active" AND u.email_verified_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM user_roles portal_role WHERE portal_role.user_id = u.id AND portal_role.role_key IN ("client","companion"))
               AND NOT EXISTS (SELECT 1 FROM user_roles privileged_role WHERE privileged_role.user_id = u.id AND privileged_role.role_key IN ("super_admin","owner","admin","finance","verifier","safeguarding"))
             LIMIT 1');
        $query->execute(['email'=>$email]);
        $user = $query->fetch();
        if (is_array($user)) {
            $this->verification->issue((string) $user['id'], (string) $user['email'], 'account_recovery');
        }
    }

    public function complete(string $email, string $code, string $newPassword, string $requestId): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
            !preg_match('/^[0-9]{6}$/D', $code) || strlen($newPassword) < 14 || strlen($newPassword) > 1024) {
            return false;
        }
        if (!$this->limiter->consume('account.recovery.complete', $email, 5, 900, 1800)) {
            return false;
        }
        $query = $this->pdo->prepare('SELECT u.id FROM users u
             WHERE u.email = :email AND u.status = "active" AND u.email_verified_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM user_roles portal_role WHERE portal_role.user_id = u.id AND portal_role.role_key IN ("client","companion"))
               AND NOT EXISTS (SELECT 1 FROM user_roles privileged_role WHERE privileged_role.user_id = u.id AND privileged_role.role_key IN ("super_admin","owner","admin","finance","verifier","safeguarding"))
             LIMIT 1');
        $query->execute(['email'=>$email]);
        $userId = $query->fetchColumn();
        if (!is_string($userId) || !$this->verification->verify($userId, $email, 'account_recovery', $code)) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM users WHERE id = :user AND email = :email AND status = "active" AND email_verified_at IS NOT NULL FOR UPDATE');
            $lock->execute(['user'=>$userId,'email'=>$email]);
            if ($lock->fetchColumn() === false) {
                $this->pdo->rollBack();
                return false;
            }
            $update = $this->pdo->prepare('UPDATE users SET password_hash = :hash, admin_session_version = admin_session_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = :user AND email = :email AND status = "active"');
            $update->execute(['hash'=>PasswordHasher::hash($newPassword),'user'=>$userId,'email'=>$email]);
            if ($update->rowCount() !== 1) {
                throw new \DomainException('Account state changed during recovery.');
            }
            $version = $this->pdo->prepare('SELECT admin_session_version FROM users WHERE id = :user');
            $version->execute(['user'=>$userId]);
            $newVersion = (int) $version->fetchColumn();
            $this->audit->record($userId,'identity.password.recovered','user',$userId,'Email-verified account recovery completed',null,['session_version'=>$newVersion],$requestId,'identity-recovery-v1');
            $this->pdo->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
