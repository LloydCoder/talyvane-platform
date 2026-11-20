<?php

namespace App\Install;

use RuntimeException;

final class EnvironmentWriter
{
    private const ALLOWED_KEYS = [
        'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'MAIL_MAILER', 'MAIL_SCHEME', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
        'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        'SESSION_DRIVER', 'SESSION_ENCRYPT', 'SESSION_SECURE_COOKIE',
        'CACHE_STORE', 'QUEUE_CONNECTION', 'TALYVANE_INSTALLER_ENFORCE',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<string, string|int|bool> $values
     */
    public function update(array $values): void
    {
        $basePath = realpath($this->basePath);
        $environmentPath = rtrim($this->basePath, DIRECTORY_SEPARATOR).'/.env';

        if ($basePath === false || is_link($environmentPath) || ! is_file($environmentPath) || ! is_readable($environmentPath)) {
            throw new RuntimeException('A readable, regular private environment file is required.');
        }

        $contents = file_get_contents($environmentPath);
        if (! is_string($contents)) {
            throw new RuntimeException('The private environment file could not be read.');
        }

        foreach ($values as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true) || (! is_string($value) && ! is_int($value) && ! is_bool($value))) {
                throw new RuntimeException('An unsupported installer environment setting was supplied.');
            }

            $contents = $this->replaceValue($contents, $key, (string) (is_bool($value) ? ($value ? 'true' : 'false') : $value));
        }

        $temporaryPath = tempnam($basePath, '.talyvane-env-');
        if ($temporaryPath === false || dirname($temporaryPath) !== $basePath) {
            throw new RuntimeException('A safe temporary environment file could not be created.');
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporaryPath, 0600)
                || ! rename($temporaryPath, $environmentPath)) {
                throw new RuntimeException('The environment configuration could not be committed securely.');
            }

            @chmod($environmentPath, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function replaceValue(string $contents, string $key, string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('Environment values must not contain control characters.');
        }

        // Dotenv double-quoted values support escaping quotes, backslashes and
        // dollar signs. Escaping dollars prevents accidental variable expansion.
        $encoded = '"'.str_replace(
            ['\\', '"', '$'],
            ['\\\\', '\\"', '\\$'],
            $value,
        ).'"';

        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $updated = preg_replace_callback($pattern, static fn (): string => $key.'='.$encoded, $contents, -1, $count);

        if (! is_string($updated) || $count > 1) {
            throw new RuntimeException('The environment file contains an ambiguous setting.');
        }

        if ($count === 0) {
            $updated = rtrim($updated, "\r\n")."\n".$key.'='.$encoded."\n";
        }

        return $updated;
    }
}
