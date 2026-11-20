<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class EnvFileSecretWriter
{
    private const ALLOWED_KEYS = [
        'SAFEGUARDING_VAULT_TOKEN',
        'SAFEGUARDING_VAULT_RETENTION_TOKEN',
    ];

    public function __construct(private readonly ?string $path = null)
    {
    }

    public function assertWritable(): void
    {
        $path = $this->environmentPath();

        if (is_link($path) || ! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new RuntimeException('The project environment file is unavailable or unsafe to modify. Use the deployment secret manager instead.');
        }

        $directory = realpath(dirname($path));
        $public = realpath(public_path());
        $resolved = realpath($path);

        if ($directory === false || $resolved === false
            || ($public !== false && ($resolved === $public || str_starts_with($resolved, $public.DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('The environment file path is not safe for credential updates.');
        }
    }

    public function assertRuntimeMatches(array $values): void
    {
        $this->assertWritable();
        $contents = file_get_contents($this->environmentPath());

        if (! is_string($contents)) {
            throw new RuntimeException('The project environment file could not be read for source verification.');
        }

        try {
            $parsed = \Dotenv\Dotenv::parse($contents);
        } catch (\Throwable) {
            throw new RuntimeException('The project environment file could not be parsed safely.');
        }

        foreach ($values as $key => $runtimeValue) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new InvalidArgumentException('Only safeguarding Vault credential keys may be checked by this writer.');
            }

            $fileValue = (string) ($parsed[$key] ?? '');
            $runtimeValue = (string) $runtimeValue;

            if (! hash_equals($fileValue, $runtimeValue)) {
                throw new RuntimeException('Runtime Vault credentials do not match the project environment file. Update the deployment secret manager instead of writing local environment values.');
            }
        }
    }

    public function putMany(array $values): void
    {
        $this->write($values);
    }

    public function clearMany(array $keys): void
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = '';
        }

        $this->write($values);
    }

    private function write(array $values): void
    {
        if ($values === []) {
            throw new InvalidArgumentException('At least one environment key is required.');
        }

        foreach ($values as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new InvalidArgumentException('Only safeguarding Vault credential keys may be changed by this writer.');
            }

            if (! is_string($value) || preg_match('/[\r\n\x00]/', $value)) {
                throw new InvalidArgumentException('Environment secret values must be single-line strings.');
            }
        }

        $this->assertWritable();
        $path = $this->environmentPath();
        $lockPath = $path.'.secret-update.lock';

        if (is_link($lockPath)) {
            throw new RuntimeException('The environment update lock path is unsafe.');
        }

        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('The environment update lock could not be opened.');
        }

        chmod($lockPath, 0600);

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('The environment file could not be locked for update.');
            }

            $contents = file_get_contents($path);
            if (! is_string($contents)) {
                throw new RuntimeException('The environment file could not be read.');
            }

            foreach ($values as $key => $value) {
                $pattern = '/^(?:export\s+)?'.preg_quote($key, '/').'=.*/m';
                $count = preg_match_all($pattern, $contents);

                if ($count === false || $count > 1) {
                    throw new RuntimeException('The environment file contains duplicate credential keys; resolve them manually before rotation.');
                }

                $line = $key.'='.$this->encodeValue($value);

                if ($count === 1) {
                    $updated = preg_replace($pattern, $line, $contents, 1);
                    if (! is_string($updated)) {
                        throw new RuntimeException('The environment file could not be updated safely.');
                    }
                    $contents = $updated;
                } else {
                    $contents = rtrim($contents, "\r\n")."\n".$line."\n";
                }
            }

            $temporary = tempnam(dirname($path), '.talyvane-env-');
            if (! is_string($temporary)) {
                throw new RuntimeException('A temporary environment file could not be created.');
            }

            try {
                if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! chmod($temporary, 0600)) {
                    throw new RuntimeException('The updated environment file could not be written securely.');
                }

                if (! rename($temporary, $path)) {
                    throw new RuntimeException('The updated environment file could not be installed atomically.');
                }

                chmod($path, 0600);
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function encodeValue(string $value): string
    {
        $escaped = str_replace(
            ['\\', '"', '$'],
            ['\\\\', '\\"', '\\$'],
            $value,
        );

        return '"'.$escaped.'"';
    }

    private function environmentPath(): string
    {
        return $this->path ?? base_path('.env');
    }
}
