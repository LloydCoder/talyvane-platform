<?php

namespace App\Install;

use RuntimeException;

final class InstallerAccessToken
{
    private readonly string $path;

    public function __construct(private readonly string $basePath)
    {
        $this->path = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'storage/app/private/INSTALLER-ACCESS-KEY.txt';
    }

    public function ensure(): void
    {
        $directory = dirname($this->path);
        $lockPath = $directory.DIRECTORY_SEPARATOR.'talyvane.install.lock';

        if (is_link($directory) || ! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The private installer state directory is unavailable.');
        }

        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('The installation lock path is unsafe.');
        }

        if (is_file($lockPath)) {
            return;
        }

        if (is_link($this->path) || (file_exists($this->path) && ! is_file($this->path))) {
            throw new RuntimeException('The installer access key path is unsafe.');
        }

        if (is_file($this->path)) {
            $existing = file_get_contents($this->path);
            if (! is_string($existing) || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', trim($existing)) !== 1) {
                throw new RuntimeException('The existing installer access key is malformed.');
            }

            if (! chmod($this->path, 0600)) {
                throw new RuntimeException('The installer access key permissions could not be secured.');
            }

            return;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $temporaryPath = tempnam($directory, '.installer-key-');

        if (! is_string($temporaryPath)) {
            throw new RuntimeException('A private installer access key could not be created.');
        }

        try {
            if (file_put_contents($temporaryPath, $token, LOCK_EX) !== strlen($token)
                || ! chmod($temporaryPath, 0600)
                || ! rename($temporaryPath, $this->path)) {
                throw new RuntimeException('The private installer access key could not be committed.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function isAvailable(): bool
    {
        if (is_link($this->path) || ! is_file($this->path)) {
            return false;
        }

        $token = file_get_contents($this->path);

        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', trim($token)) === 1;
    }

    public function verify(string $candidate): bool
    {
        if (! $this->isAvailable() || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $candidate) !== 1) {
            return false;
        }

        $stored = file_get_contents($this->path);

        return is_string($stored) && hash_equals(trim($stored), $candidate);
    }

    public function consume(): bool
    {
        if (! file_exists($this->path)) {
            return true;
        }

        if (is_link($this->path) || ! is_file($this->path)) {
            return false;
        }

        return @unlink($this->path) && ! file_exists($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }
}
