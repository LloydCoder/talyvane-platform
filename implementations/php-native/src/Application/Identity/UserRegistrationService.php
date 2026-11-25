<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\PasswordHasher;

final class UserRegistrationService
{
    public function __construct(
        private PDO $pdo,
        private EmailVerificationService $emailVerification,
        private RateLimiter $limiter,
        private AuditLogger $audit
    ) {}

    /** Public self-registration is limited to client and companion identities. */
    public function register(string $email, string $password, string $requestedRole, string $ipAddress, string $requestId): string
    {
        $email = strtolower(trim($email));
        if (!in_array($requestedRole,['client','companion'],true) ||
            !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
            strlen($password) < 14 || strlen($password) > 1024) {
            throw new \InvalidArgumentException('Registration details do not meet the account requirements.');
        }
        $scope = $requestedRole === 'client' ? 'register.client' : 'register.companion';
        if (!$this->limiter->consume($scope,$email . '|' . $ipAddress,3,3600,3600)) {
            throw new \DomainException('Registration is temporarily rate limited.');
        }
        $userId = self::uuid();
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT 1 FROM users WHERE LOWER(email) = :email LIMIT 1');
            $existing->execute(['email'=>$email]);
            if ($existing->fetchColumn() !== false) {
                throw new \DomainException('Registration could not be completed with those details.');
            }
            $create = $this->pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at) VALUES (:id,:email,:hash,"pending",NULL,0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $create->execute(['id'=>$userId,'email'=>$email,'hash'=>PasswordHasher::hash($password)]);
            $role = $this->pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:user,:role,NULL,UTC_TIMESTAMP())');
            $role->execute(['user'=>$userId,'role'=>$requestedRole]);
            $this->audit->record(null,'identity.registration.created','user',$userId,'Public self-registration',null,['role'=>$requestedRole,'status'=>'pending'], $requestId,'identity-registration-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        $this->emailVerification->issue($userId,$email,'account_primary');
        return $userId;
    }

    public function resendPrimaryVerification(string $userId): void
    {
        $statement = $this->pdo->prepare('SELECT email,status,email_verified_at FROM users WHERE id = :user LIMIT 1');
        $statement->execute(['user'=>$userId]);
        $user = $statement->fetch();
        if (!is_array($user) || $user['status'] !== 'pending' || $user['email_verified_at'] !== null) {
            throw new \DomainException('Account is not awaiting primary email verification.');
        }
        $this->emailVerification->issue($userId,(string) $user['email'],'account_primary');
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
