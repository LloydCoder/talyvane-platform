<?php
declare(strict_types=1);

namespace Talyvane\Security;

final class SessionManager
{
    public static function start(bool $requireHttps = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        if ($requireHttps && !$https) {
            throw new \RuntimeException('HTTPS is required before an authenticated session can start.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('talyvane_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new \RuntimeException('Could not start a secure session.');
        }
    }

    public static function rotateAfterAuthentication(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) {
            throw new \RuntimeException('Could not rotate authenticated session.');
        }
        Csrf::rotate();
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
    }
}
