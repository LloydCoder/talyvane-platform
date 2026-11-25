<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Logging;

final class StructuredLogger
{
    private string $file;

    public function __construct(string $logDirectory, string $publicRoot)
    {
        $public = realpath($publicRoot);
        if ($public === false) {
            throw new \RuntimeException('Public root cannot be resolved.');
        }
        if (!is_dir($logDirectory)) {
            if (!@mkdir($logDirectory, 0700, true) && !is_dir($logDirectory)) {
                throw new \RuntimeException('Private log directory could not be created.');
            }
        }
        @chmod($logDirectory, 0700);
        $directory = realpath($logDirectory);
        if ($directory === false || self::isWithin($directory, $public) || is_link($logDirectory)) {
            throw new \RuntimeException('Log directory must be outside the public document root and must not be a symlink.');
        }
        $this->file = $directory . DIRECTORY_SEPARATOR . 'application.log';
        if (is_link($this->file)) {
            throw new \RuntimeException('Log file must not be a symlink.');
        }
    }

    /** @param array<string,mixed> $context */
    public function write(string $level, string $event, string $requestId, array $context = []): void
    {
        if (!in_array($level, ['debug','info','notice','warning','error','critical'], true) ||
            $event === '' || strlen($event) > 120 ||
            !preg_match('/^[a-f0-9-]{8,64}$/iD', $requestId)) {
            throw new \InvalidArgumentException('Log metadata is invalid.');
        }
        $record = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'event' => $event,
            'request_id' => $requestId,
            'context' => self::redact($context),
        ];
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $handle = @fopen($this->file, 'ab');
        if ($handle === false) {
            error_log('[talyvane] structured log write failed');
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Could not lock private log file.');
            }
            if (fwrite($handle, $json) === false) {
                throw new \RuntimeException('Could not write private log record.');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
            @chmod($this->file, 0600);
        }
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
            if (preg_match('/(password|secret|token|apikey|privatekey|seedphrase|credential|authorization|identitydocument|totp|recoverycode|email|phone|address)/', $normalized) === 1) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            } elseif (is_object($value)) {
                $context[$key] = '[OBJECT OMITTED]';
            }
        }
        return $context;
    }

    private static function isWithin(string $path, string $parent): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $parent = rtrim($parent, DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR);
    }
}
