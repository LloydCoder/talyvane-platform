<?php
declare(strict_types=1);

namespace Talyvane\Installer;

final class EnvironmentPreflight
{
    /** @return array{ok:bool,checks:array<string,array{ok:bool,detail:string}>} */
    public static function inspect(?string $accountHome = null): array
    {
        $checks = [];
        $checks['php_version'] = [
            'ok' => PHP_VERSION_ID >= 80300,
            'detail' => 'PHP ' . PHP_VERSION . ' detected; PHP 8.3 or newer is required.',
        ];
        foreach (['pdo','pdo_mysql','openssl','mbstring','fileinfo','json','zip','gd'] as $extension) {
            $checks['extension_' . $extension] = [
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? $extension . ' is available.' : $extension . ' extension is required.',
            ];
        }
        $checks['mysql_driver'] = [
            'ok' => in_array('mysql', \PDO::getAvailableDrivers(), true),
            'detail' => in_array('mysql', \PDO::getAvailableDrivers(), true) ? 'PDO MySQL driver is available.' : 'PDO MySQL driver is unavailable.',
        ];
        $checks['gd_webp'] = [
            'ok' => function_exists('imagewebp') && function_exists('imagecreatefromwebp'),
            'detail' => function_exists('imagewebp') && function_exists('imagecreatefromwebp') ? 'GD WebP support is available.' : 'GD must support WebP image decoding and encoding.',
        ];
        $checks['sessions'] = [
            'ok' => function_exists('session_start') && (bool) ini_get('session.use_cookies'),
            'detail' => function_exists('session_start') && (bool) ini_get('session.use_cookies') ? 'Cookie-backed PHP sessions are available.' : 'Cookie-backed PHP sessions are required.',
        ];
        $privateWritable = is_string($accountHome) && is_dir($accountHome) && is_writable($accountHome);
        $checks['private_writable'] = [
            'ok' => $privateWritable,
            'detail' => $privateWritable ? 'Account home is writable for private application storage.' : 'A private writable account home path must be supplied and accessible.',
        ];
        $ok = true;
        foreach ($checks as $check) {
            $ok = $ok && $check['ok'];
        }
        return ['ok'=>$ok,'checks'=>$checks];
    }
}
