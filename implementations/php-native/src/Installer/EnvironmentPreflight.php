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
        $uploadsEnabled = (bool) ini_get('file_uploads');
        $checks['uploads_enabled'] = [
            'ok'=>$uploadsEnabled,
            'detail'=>$uploadsEnabled ? 'PHP file uploads are enabled.' : 'PHP file uploads must be enabled for private profile media.',
        ];
        $uploadBytes = self::iniBytes((string) ini_get('upload_max_filesize'));
        $checks['upload_size'] = [
            'ok'=>$uploadBytes >= 8_388_608,
            'detail'=>$uploadBytes >= 8_388_608 ? 'PHP upload_max_filesize supports the 8 MiB media limit.' : 'PHP upload_max_filesize must be at least 8M.',
        ];
        $postBytes = self::iniBytes((string) ini_get('post_max_size'));
        $checks['post_size'] = [
            'ok'=>$postBytes >= 10_485_760,
            'detail'=>$postBytes >= 10_485_760 ? 'PHP post_max_size supports multipart media requests.' : 'PHP post_max_size must be at least 10M.',
        ];
        $memoryBytes = self::iniBytes((string) ini_get('memory_limit'));
        $checks['memory_limit'] = [
            'ok'=>$memoryBytes === -1 || $memoryBytes >= 134_217_728,
            'detail'=>$memoryBytes === -1 || $memoryBytes >= 134_217_728 ? 'PHP memory_limit supports bounded image sanitization.' : 'PHP memory_limit must be at least 128M for safe image sanitization.',
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
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        if (!preg_match('/^([0-9]+)\s*([KMG]?)$/iD',$value,$match)) {
            return 0;
        }
        $number = (int) $match[1];
        $unit = strtoupper($match[2] ?? '');
        return match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }

    }
}
