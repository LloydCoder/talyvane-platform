<?php
declare(strict_types=1);

namespace Talyvane\Security;

final class Csrf
{
    public static function issue(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('An active session is required.');
        }
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(?string $submitted): bool
    {
        $known = $_SESSION['_csrf'] ?? null;
        return is_string($known) && is_string($submitted) && hash_equals($known, $submitted);
    }

    public static function rotate(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('An active session is required.');
        }
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }
}
