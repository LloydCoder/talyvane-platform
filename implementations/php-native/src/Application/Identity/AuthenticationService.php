<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\MfaService as MissingMfaService;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

/** Authentication decisions are distinct from authorization and privileged MFA completion. */
final class AuthenticationService
{
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    private const ADMIN_ROLES = ['super_admin','owner','admin','finance','verifier','safeguarding'];
    private const PORTAL_ROLES = ['client','companion'];

    public function __construct(
        private PDO $pdo,
        private RateLimiter $rateLimiter,
        private MfaService $mfa
    ) {}

    /** @return array{status:string,user_id?:string,session_version?:int,roles?:list<string>} */
    public function verifyPassword(string $email, string $password, string $context, string $ipAddress): array
    {
        if (!in_array($context, ['admin','portal'], true) || strlen($password) > 1024) {
            return ['status'=>'invalid_credentials'];
        }
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            password_verify($password, self::DUMMY_HASH);
            return ['status'=>'invalid_credentials'];
        }
        $scope = $context === 'admin' ? 'auth.admin' : 'auth.portal';
        if (!$this->rateLimiter->consume($scope, $email . '|' . $ipAddress, 10, 60, 900)) {
            password_verify($password, self::DUMMY_HASH);
            return ['status'=>'rate_limited'];
        }
        $statement = $this->pdo->prepare('SELECT id, email, password_hash, status, email_verified_at, first_login_required, admin_session_version FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email'=>$email]);
        $user = $statement->fetch();
        $hash = is_array($user) ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $passwordValid = password_verify($password, $hash);
        if (!is_array($user) || !$passwordValid || $user['status'] !== 'active' || $user['email_verified_at'] === null) {
            return ['status'=>'invalid_credentials'];
        }
        $roles = $this->roles((string) $user['id']);
        $allowed = $context === 'admin' ? self::ADMIN_ROLES : self::PORTAL_ROLES;
        if (array_intersect($roles, $allowed) === []) {
            return ['status'=>'invalid_credentials'];
        }
        $decision = [
            'user_id'=>(string) $user['id'],
            'session_version'=>(int) $user['admin_session_version'],
            'roles'=>$roles,
        ];
        if ($context === 'portal') {
            return ['status'=>'authenticated'] + $decision;
        }
        if ((bool) $user['first_login_required']) {
            return ['status'=>'first_login_required'] + $decision;
        }
        $mfa = $this->pdo->prepare('SELECT enabled_at FROM admin_mfa_credentials WHERE user_id = :user LIMIT 1');
        $mfa->execute(['user'=>$user['id']]);
        $mfaEnabledAt = $mfa->fetchColumn();
        if ($mfaEnabledAt === false || $mfaEnabledAt === null) {
            return ['status'=>'mfa_setup_required'] + $decision;
        }
        return ['status'=>'mfa_required'] + $decision;
    }

    /** @param array{status:string,user_id?:string,session_version?:int,roles?:list<string>} $decision */
    public function establishPortalSession(array $decision): void
    {
        if (($decision['status'] ?? '') !== 'authenticated' || !isset($decision['user_id'],$decision['session_version'],$decision['roles'])) {
            throw new \DomainException('Portal authentication decision is invalid.');
        }
        SessionManager::start();
        SessionManager::rotateAfterAuthentication();
        $_SESSION['user_id'] = $decision['user_id'];
        $_SESSION['session_version'] = $decision['session_version'];
        $_SESSION['roles'] = $decision['roles'];
        $_SESSION['mfa_verified'] = false;
        $_SESSION['authenticated_at'] = time();
    }

    /** @param array{status:string,user_id?:string,session_version?:int} $decision */
    public function beginAdminMfaChallenge(array $decision): void
    {
        if (($decision['status'] ?? '') !== 'mfa_required' || !isset($decision['user_id'],$decision['session_version'])) {
            throw new \DomainException('Administrator MFA challenge is not available.');
        }
        SessionManager::start();
        SessionManager::rotateAfterAuthentication();
        $_SESSION['_admin_mfa_pending'] = [
            'user_id'=>$decision['user_id'],
            'session_version'=>$decision['session_version'],
            'expires_at'=>time() + 300,
        ];
    }

    /** @param array{status:string,user_id?:string,session_version?:int} $decision */
    public function beginFirstLoginPasswordChange(array $decision): void
    {
        if (($decision['status'] ?? '') !== 'first_login_required' || !isset($decision['user_id'],$decision['session_version'])) {
            throw new \\DomainException('First-login password replacement is not available.');
        }
        SessionManager::start();
        SessionManager::rotateAfterAuthentication();
        $_SESSION['_admin_password_change_pending'] = [
            'user_id'=>$decision['user_id'],
            'session_version'=>$decision['session_version'],
            'expires_at'=>time() + 600,
        ];
    }

    public function completeFirstLoginPasswordChange(PasswordChangeService $passwords, string $currentPassword, string $newPassword, string $requestId): string
    {
        $pending = $_SESSION['_admin_password_change_pending'] ?? null;
        if (!is_array($pending) || !isset($pending['user_id'],$pending['session_version'],$pending['expires_at']) ||
            (int) $pending['expires_at'] < time()) {
            unset($_SESSION['_admin_password_change_pending']);
            throw new \\DomainException('First-login password replacement session has expired.');
        }
        $userId = (string) $pending['user_id'];
        $user = $this->activeUser($userId);
        if ($user === null || (int) $user['admin_session_version'] !== (int) $pending['session_version']) {
            unset($_SESSION['_admin_password_change_pending']);
            throw new \\DomainException('First-login account state changed; authenticate again.');
        }
        $newVersion = $passwords->changeFirstLoginPassword($userId,$currentPassword,$newPassword,$requestId);
        $roles = $this->roles($userId);
        unset($_SESSION['_admin_password_change_pending']);
        SessionManager::rotateAfterAuthentication();
        $_SESSION['_admin_mfa_setup_pending'] = [
            'user_id'=>$userId,
            'session_version'=>$newVersion,
            'expires_at'=>time() + 600,
        ];
        return $this->mfa->beginEnrollment(new Principal($userId,$roles,false));
    }

    /** @param array{status:string,user_id?:string,session_version?:int,roles?:list<string>} $decision */
    public function beginAdminMfaSetup(array $decision): string
    {
        if (($decision['status'] ?? '') !== 'mfa_setup_required' || !isset($decision['user_id'],$decision['session_version'],$decision['roles'])) {
            throw new \\DomainException('Administrator MFA setup is not available.');
        }
        SessionManager::start();
        SessionManager::rotateAfterAuthentication();
        $_SESSION['_admin_mfa_setup_pending'] = [
            'user_id'=>$decision['user_id'],
            'session_version'=>$decision['session_version'],
            'expires_at'=>time() + 600,
        ];
        return $this->mfa->beginEnrollment(new Principal($decision['user_id'],$decision['roles'],false));
    }

    /** Returns recovery codes exactly once after successful MFA enrollment. */
    public function completeAdminMfaSetup(string $code, string $requestId): array
    {
        $pending = $_SESSION['_admin_mfa_setup_pending'] ?? null;
        if (!is_array($pending) || !isset($pending['user_id'],$pending['session_version'],$pending['expires_at']) ||
            (int) $pending['expires_at'] < time()) {
            unset($_SESSION['_admin_mfa_setup_pending']);
            throw new \\DomainException('Administrator MFA setup session has expired.');
        }
        $userId = (string) $pending['user_id'];
        $user = $this->activeUser($userId);
        if ($user === null || (int) $user['admin_session_version'] !== (int) $pending['session_version']) {
            unset($_SESSION['_admin_mfa_setup_pending']);
            throw new \\DomainException('Administrator account state changed during MFA setup.');
        }
        $roles = $this->roles($userId);
        $codes = $this->mfa->confirmEnrollment(new Principal($userId,$roles,false),$code,$requestId);
        unset($_SESSION['_admin_mfa_setup_pending']);
        $this->finalizeAdminSession($userId,(int) $user['admin_session_version']);
        return $codes;
    }

    public function completeAdminMfa(string $code): bool
    {
        $pending = $_SESSION['_admin_mfa_pending'] ?? null;
        if (!is_array($pending) || !isset($pending['user_id'],$pending['session_version'],$pending['expires_at']) ||
            (int) $pending['expires_at'] < time()) {
            unset($_SESSION['_admin_mfa_pending']);
            return false;
        }
        $user = $this->activeUser((string) $pending['user_id']);
        if ($user === null || (int) $user['admin_session_version'] !== (int) $pending['session_version']) {
            unset($_SESSION['_admin_mfa_pending']);
            return false;
        }
        if (!$this->rateLimiter->consume('auth.mfa', (string) $pending['user_id'], 5, 300, 900)) {
            return false;
        }
        if (!$this->mfa->verifyChallenge((string) $pending['user_id'], $code)) {
            return false;
        }
        $this->finalizeAdminSession((string) $user['id'], (int) $user['admin_session_version']);
        return true;
    }

    public function completeAdminRecoveryCode(string $code, string $requestId): bool
    {
        $pending = $_SESSION['_admin_mfa_pending'] ?? null;
        if (!is_array($pending) || !isset($pending['user_id'],$pending['session_version'],$pending['expires_at']) ||
            (int) $pending['expires_at'] < time()) {
            unset($_SESSION['_admin_mfa_pending']);
            return false;
        }
        $user = $this->activeUser((string) $pending['user_id']);
        if ($user === null || (int) $user['admin_session_version'] !== (int) $pending['session_version']) {
            unset($_SESSION['_admin_mfa_pending']);
            return false;
        }
        if (!$this->rateLimiter->consume('auth.recovery', (string) $pending['user_id'], 5, 300, 1800)) {
            return false;
        }
        if (!$this->mfa->consumeRecoveryCode((string) $pending['user_id'], $code, $requestId)) {
            return false;
        }
        $this->finalizeAdminSession((string) $user['id'], (int) $user['admin_session_version']);
        return true;
    }

    public function currentPrincipal(): ?Principal
    {
        $now = time();
        $authenticatedAt = (int) ($_SESSION['authenticated_at'] ?? 0);
        $lastActivity = (int) ($_SESSION['last_activity_at'] ?? $authenticatedAt);
        if ($authenticatedAt < 1 || $now - $lastActivity > 1800 || $now - $authenticatedAt > 43200) {
            SessionManager::destroy();
            return null;
        }
        $_SESSION['last_activity_at'] = $now;
        $userId = $_SESSION['user_id'] ?? null;
        $sessionVersion = $_SESSION['session_version'] ?? null;
        if (!is_string($userId) || !is_int($sessionVersion)) {
            return null;
        }
        $user = $this->activeUser($userId);
        if ($user === null || (int) $user['admin_session_version'] !== $sessionVersion) {
            SessionManager::destroy();
            return null;
        }
        $roles = $this->roles($userId);
        $privileged = array_intersect($roles, self::ADMIN_ROLES) !== [];
        $mfaVerified = (bool) ($_SESSION['mfa_verified'] ?? false);
        if ($privileged && !$mfaVerified) {
            return null;
        }
        return new Principal($userId, $roles, $mfaVerified);
    }

    public function logout(): void
    {
        SessionManager::destroy();
    }

    private function finalizeAdminSession(string $userId, int $sessionVersion): void
    {
        $roles = $this->roles($userId);
        unset($_SESSION['_admin_mfa_pending']);
        SessionManager::rotateAfterAuthentication();
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_version'] = $sessionVersion;
        $_SESSION['roles'] = $roles;
        $_SESSION['mfa_verified'] = true;
        $_SESSION['authenticated_at'] = time();
    }

    /** @return list<string> */
    private function roles(string $userId): array
    {
        $statement = $this->pdo->prepare('SELECT role_key FROM user_roles WHERE user_id = :user ORDER BY role_key');
        $statement->execute(['user'=>$userId]);
        return array_values(array_map(static fn(array $row): string => (string) $row['role_key'], $statement->fetchAll()));
    }

    /** @return array<string,mixed>|null */
    private function activeUser(string $userId): ?array
    {
        $statement = $this->pdo->prepare("SELECT id, status, email_verified_at, admin_session_version FROM users WHERE id = :id LIMIT 1");
        $statement->execute(['id'=>$userId]);
        $user = $statement->fetch();
        return is_array($user) && $user['status'] === 'active' && $user['email_verified_at'] !== null ? $user : null;
    }
}
