<?php
declare(strict_types=1);

namespace Talyvane\Security;

final class PasswordHasher
{
    public static function hash(string $password): string
    {
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new \InvalidArgumentException('Password must contain between 12 and 1024 bytes.');
        }
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algo);
        if (!is_string($hash)) {
            throw new \RuntimeException('Password hashing failed.');
        }
        return $hash;
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
