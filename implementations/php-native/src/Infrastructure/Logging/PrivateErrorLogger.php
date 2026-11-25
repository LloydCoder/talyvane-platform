<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Logging;

use Throwable;

/** Private bounded error log: correlation metadata only, never exception messages or request data. */
final class PrivateErrorLogger
{
    private const MAX_BYTES = 5_242_880;
    private const ROTATIONS = 5;

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
            $lockPath = $logs . DIRECTORY_SEPARATOR . '.application-error.lock';
            if (is_link($file) || (file_exists($file) && !is_file($file)) ||
                is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
                throw new \RuntimeException('Private error log path is invalid.');
            }
            $diagnostic = [
                'timestamp'=>gmdate('Y-m-d\\TH:i:s\\Z'),
                'reference'=>$reference,
                'exception_class'=>$error::class,
                'exception_code'=>(string) $error->getCode(),
            ];
            if ($error instanceof \\PDOException && is_array($error->errorInfo ?? null)) {
                $sqlState = $error->errorInfo[0] ?? null;
                $driverCode = $error->errorInfo[1] ?? null;
                if (is_string($sqlState) && preg_match('/^[A-Z0-9]{5}$/D',$sqlState)) {
                    $diagnostic['sql_state'] = $sqlState;
                }
                if ((is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode))) &&
                    (int) $driverCode >= 0 && (int) $driverCode <= 999999) {
                    $diagnostic['driver_code'] = (int) $driverCode;
                }
            }
            $entry = json_encode($diagnostic,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;

            $previousUmask = umask(0077);
            $lock = @fopen($lockPath,'c+b');
            umask($previousUmask);
            if ($lock === false) {
                throw new \RuntimeException('Private error log lock could not be opened.');
            }
            @chmod($lockPath,0600);
            try {
                if (!flock($lock,LOCK_EX)) {
                    throw new \RuntimeException('Private error log could not be locked.');
                }
                self::rotateIfNeeded($file,strlen($entry));
                $previousUmask = umask(0077);
                $handle = @fopen($file,'ab');
                umask($previousUmask);
                if ($handle === false) {
                    throw new \RuntimeException('Private error log could not be opened.');
                }
                try {
                    if (fwrite($handle,$entry) !== strlen($entry) || !fflush($handle)) {
                        throw new \RuntimeException('Private error log write was incomplete.');
                    }
                } finally {
                    fclose($handle);
                    @chmod($file,0600);
                }
                flock($lock,LOCK_UN);
            } finally {
                if (is_resource($lock)) {
                    @flock($lock,LOCK_UN);
                    fclose($lock);
                }
            }
        } catch (Throwable) {
            // Keep the public response generic and preserve only a non-sensitive host error-log signal.
            error_log('[talyvane:' . $reference . '] private error log unavailable; exception=' . $error::class);
        }
    }

    private static function rotateIfNeeded(string $file,int $incomingBytes): void
    {
        if (!is_file($file)) {
            return;
        }
        $size = filesize($file);
        if (!is_int($size) || $size + $incomingBytes <= self::MAX_BYTES) {
            return;
        }
        for ($index=self::ROTATIONS; $index>=1; $index--) {
            $source = $index === 1 ? $file : $file . '.' . ($index-1);
            $target = $file . '.' . $index;
            if (is_link($source) || is_link($target) ||
                (file_exists($source) && !is_file($source)) ||
                (file_exists($target) && !is_file($target))) {
                throw new \RuntimeException('Private error log rotation encountered an invalid path.');
            }
            if (is_file($target) && !@unlink($target)) {
                throw new \RuntimeException('Private error log rotation could not remove an expired backup.');
            }
            if (is_file($source) && !@rename($source,$target)) {
                throw new \RuntimeException('Private error log rotation could not archive the current log.');
            }
            if (is_file($target)) {
                @chmod($target,0600);
            }
        }
    }

    private static function isWithin(string $path,string $parent): bool
    {
        $path = rtrim($path,DIRECTORY_SEPARATOR);
        $parent = rtrim($parent,DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR,$parent . DIRECTORY_SEPARATOR);
    }
}
