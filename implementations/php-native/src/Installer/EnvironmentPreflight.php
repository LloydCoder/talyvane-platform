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
            $available = extension_loaded($extension);
            $checks['extension_' . $extension] = [
                'ok' => $available,
                'detail' => $available ? $extension . ' is available.' : $extension . ' extension is required.',
            ];
        }
        $mysqlAvailable = class_exists(\PDO::class) && in_array('mysql', \PDO::getAvailableDrivers(), true);
        $checks['mysql_driver'] = [
            'ok' => $mysqlAvailable,
            'detail' => $mysqlAvailable ? 'PDO MySQL driver is available.' : 'PDO MySQL driver is unavailable.',
        ];
        $webpAvailable = function_exists('imagewebp') && function_exists('imagecreatefromwebp');
        $checks['gd_webp'] = [
            'ok' => $webpAvailable,
            'detail' => $webpAvailable ? 'GD WebP support is available.' : 'GD must support WebP image decoding and encoding.',
        ];
        $sessionsAvailable = function_exists('session_start') && (bool) ini_get('session.use_cookies');
        $checks['sessions'] = [
            'ok' => $sessionsAvailable,
            'detail' => $sessionsAvailable ? 'Cookie-backed PHP sessions are available.' : 'Cookie-backed PHP sessions are required.',
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
