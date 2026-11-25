<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Logging;

use Throwable;

/** Minimal private error log: correlation metadata only, never exception messages or request data. */
final class PrivateErrorLogger
{
    public static function record(Throwable $error,string $logDirectory,string $publicRoot,string $reference): void
    {
        try {
            $logs = realpath($logDirectory);
            $public = realpath($publicRoot);
            if ($logs === false || $public === false || !is_dir($logs) || is_link($logDirectory) ||
                self::isWithin($logs,$public) || !is_writable($logs) ||
                !preg_match('/^[a-f0-9]{16,64}$/D',$reference)) {
                throw new \RuntimeException('Private error log destination is unavailable.');
            }
            $file = $logs . DIRECTORY_SEPARATOR . 'application-error.log';
            if (is_link($file) || (file_exists($file) && !is_file($file))) {
                throw new \RuntimeException('Private error log file path is invalid.');
            }
            $entry = json_encode([
                'timestamp'=>gmdate('Y-m-d\TH:i:s\Z'),
                'reference'=>$reference,
                'exception_class'=>$error::class,
                'exception_code'=>(string) $error->getCode(),
            ],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            $previousUmask = umask(0077);
            $handle = @fopen($file,'ab');
            umask($previousUmask);
            if ($handle === false) {
                throw new \RuntimeException('Private error log could not be opened.');
            }
            try {
                if (!flock($handle,LOCK_EX)) {
                    throw new \RuntimeException('Private error log could not be locked.');
                }
                if (fwrite($handle,$entry) !== strlen($entry) || !fflush($handle)) {
                    throw new \RuntimeException('Private error log write was incomplete.');
                }
                flock($handle,LOCK_UN);
            } finally {
                fclose($handle);
                @chmod($file,0600);
            }
        } catch (Throwable) {
            // Keep the public response generic and preserve only a non-sensitive host error-log signal.
            error_log('[talyvane:' . $reference . '] private error log unavailable; exception=' . $error::class);
        }
    }

    private static function isWithin(string $path,string $parent): bool
    {
        $path = rtrim($path,DIRECTORY_SEPARATOR);
        $parent = rtrim($parent,DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR,$parent . DIRECTORY_SEPARATOR);
    }
}
