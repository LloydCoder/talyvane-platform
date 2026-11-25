<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\PasswordHasher;

final class PasswordChangeService
{
    public function __construct(
        private PDO $pdo,
        private RateLimiter $limiter,
        private AuditLogger $audit
    ) {}

    /** Completes the mandatory first-login password replacement and revokes all prior sessions. */
    public function changeFirstLoginPassword(string $userId, string $currentPassword, string $newPassword, string $requestId): int
    {
        if (strlen($newPassword) < 14 || strlen($newPassword) > 1024 || $newPassword === $currentPassword) {
            throw new \InvalidArgumentException('New password must be at least 14 characters and differ from the initial password.');
        }
        if (!$this->limiter->consume('auth.password', $userId, 5, 900, 1800)) {
            throw new \DomainException('Password change is temporarily rate limited.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT password_hash,status,email_verified_at,first_login_required,admin_session_version FROM users WHERE id = :user FOR UPDATE');
            $query->execute(['user'=>$userId]);
            $user = $query->fetch();
            if (!is_array($user) || $user['status'] !== 'active' || $user['email_verified_at'] === null ||
                !(bool) $user['first_login_required'] || !password_verify($currentPassword,(string) $user['password_hash'])) {
                throw new \DomainException('Initial credentials are invalid or the first-login password change is not required.');
            }
            $newHash = PasswordHasher::hash($newPassword);
            $version = (int) $user['admin_session_version'] + 1;
            $update = $this->pdo->prepare('UPDATE users SET password_hash = :hash, first_login_required = 0, admin_session_version = :version, updated_at = UTC_TIMESTAMP() WHERE id = :user AND first_login_required = 1');
            $update->execute(['hash'=>$newHash,'version'=>$version,'user'=>$userId]);
            if ($update->rowCount() !== 1) {
                throw new \DomainException('Account state changed during password replacement.');
            }
            $this->audit->record($userId,'admin.password.initial_replaced','user',$userId,'Mandatory first-login password replacement completed',null,['first_login_required'=>false,'session_version'=>$version],$requestId,'identity-lifecycle-v1');
            $this->pdo->commit();
            return $version;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Changes a verified client/companion password and revokes every other session. */
    public function changePortalPassword(string $userId,string $currentPassword,string $newPassword,string $requestId): int
    {
        if (strlen($newPassword) < 14 || strlen($newPassword) > 1024 || $newPassword === $currentPassword) {
            throw new \InvalidArgumentException('New password must be at least 14 characters and differ from the current password.');
        }
        if (!$this->limiter->consume('auth.password',$userId,5,900,1800)) {
            throw new \DomainException('Password change is temporarily rate limited.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT password_hash,status,email_verified_at,first_login_required,admin_session_version FROM users WHERE id=:user FOR UPDATE');
            $query->execute(['user'=>$userId]);
            $user = $query->fetch();
            if (!is_array($user) || $user['status'] !== 'active' || $user['email_verified_at'] === null ||
                (bool) $user['first_login_required'] || !password_verify($currentPassword,(string) $user['password_hash'])) {
                throw new \DomainException('Current credentials are invalid or the account is not eligible for portal password self-service.');
            }
            $rolesQuery = $this->pdo->prepare('SELECT role_key FROM user_roles WHERE user_id=:user');
            $rolesQuery->execute(['user'=>$userId]);
            $roles = array_map(static fn(array $row): string => (string) $row['role_key'],$rolesQuery->fetchAll());
            if (!in_array('client',$roles,true) && !in_array('companion',$roles,true)) {
                throw new \DomainException('Portal password self-service is restricted to client and companion accounts.');
            }
            if (array_intersect($roles,['super_admin','owner','admin','finance','verifier','safeguarding']) !== []) {
                throw new \DomainException('Privileged administrator credentials must be changed through the administrator security flow.');
            }
            $version = (int) $user['admin_session_version'] + 1;
            $update = $this->pdo->prepare('UPDATE users SET password_hash=:hash,admin_session_version=:version,updated_at=UTC_TIMESTAMP() WHERE id=:user AND admin_session_version=:previous');
            $update->execute([
                'hash'=>PasswordHasher::hash($newPassword),
                'version'=>$version,
                'user'=>$userId,
                'previous'=>(int) $user['admin_session_version'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new \DomainException('Account state changed during password replacement.');
            }
            $this->audit->record($userId,'portal.password.changed','user',$userId,'Portal password changed; prior sessions revoked',null,
                ['session_version'=>$version],$requestId,'identity-lifecycle-v1');
            $this->pdo->commit();
            return $version;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
