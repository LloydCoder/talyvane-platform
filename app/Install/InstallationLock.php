<?php

namespace App\Install;

use RuntimeException;

final class InstallationLock
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $applicationKey,
    ) {
        if ($this->applicationKey === '') {
            throw new RuntimeException('A deployment application key is required for the installation lock.');
        }
    }

    public function path(): string
    {
        return rtrim($this->basePath, DIRECTORY_SEPARATOR).'/storage/app/private/talyvane.install.lock';
    }

    public function exists(): bool
    {
        $path = $this->path();

        if (is_link($path)) {
            throw new RuntimeException('The installation lock must not be a symbolic link.');
        }

        if (file_exists($path) && ! is_file($path)) {
            throw new RuntimeException('The installation lock is not a regular file.');
        }

        return is_file($path);
    }

    /**
     * Create the signed lock exclusively. The caller must commit the returned
     * installation ID and lock hash to platform_control in the same workflow.
     *
     * @return array{installation_id: string, installed_at: string, lock_hash: string}
     */
    public function create(): array
    {
        $directory = dirname($this->path());
        $this->ensurePrivateDirectory($directory);

        $path = $this->path();
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('An installation lock already exists.');
        }

        $payload = [
            'schema_version' => 1,
            'installation_id' => $this->uuid(),
            'installed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $document = json_encode([
            'payload' => $payload,
            'signature' => hash_hmac('sha256', $canonical, $this->applicationKey),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('The installation lock could not be created exclusively.');
        }

        try {
            if (! chmod($path, 0600)) {
                throw new RuntimeException('The installation lock permissions could not be restricted.');
            }

            $written = 0;
            $length = strlen($document);
            while ($written < $length) {
                $count = fwrite($handle, substr($document, $written));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('The installation lock could not be written completely.');
                }
                $written += $count;
            }

            if (! fflush($handle)) {
                throw new RuntimeException('The installation lock could not be flushed.');
            }

            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('The installation lock could not be synchronized.');
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        }

        fclose($handle);

        return [
            'installation_id' => $payload['installation_id'],
            'installed_at' => $payload['installed_at'],
            'lock_hash' => hash('sha256', $canonical),
        ];
    }

    public function verify(string $installationId, string $expectedHash): bool
    {
        $path = $this->path();
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $document = json_decode((string) file_get_contents($path), true);
        if (! is_array($document)
            || ! is_array($document['payload'] ?? null)
            || ! is_string($document['signature'] ?? null)) {
            return false;
        }

        $payload = $document['payload'];
        if (($payload['schema_version'] ?? null) !== 1
            || ! is_string($payload['installation_id'] ?? null)
            || ! is_string($payload['installed_at'] ?? null)
            || ! hash_equals($installationId, $payload['installation_id'])) {
            return false;
        }

        try {
            $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        $signature = hash_hmac('sha256', $canonical, $this->applicationKey);

        return hash_equals($expectedHash, hash('sha256', $canonical))
            && hash_equals($signature, $document['signature']);
    }

    public function removeIfMatches(string $installationId, string $expectedHash): bool
    {
        if (! $this->verify($installationId, $expectedHash)) {
            return false;
        }

        return @unlink($this->path());
    }

    private function ensurePrivateDirectory(string $directory): void
    {
        $basePath = realpath($this->basePath);
        if ($basePath === false || is_link($directory)) {
            throw new RuntimeException('The private installation state directory is unsafe.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The private installation state directory could not be created.');
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false || ! str_starts_with($realDirectory, $basePath.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The private installation state directory escapes the application root.');
        }

        @chmod($realDirectory, 0700);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
