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
        $this->assertSafeDirectory();
        $lockPath = $directory.DIRECTORY_SEPARATOR.'talyvane.install.lock';

        if (! is_writable($directory)) {
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
                || ! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('The private installer access key could not be prepared securely.');
            }

            // A hard link commits the fully written key atomically without
            // replacing a key created by a concurrent first request.
            if (! @link($temporaryPath, $this->path)) {
                if (is_link($this->path) || ! is_file($this->path)) {
                    throw new RuntimeException('The private installer access key could not be committed.');
                }

                $existing = file_get_contents($this->path);
                if (! is_string($existing)
                    || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', trim($existing)) !== 1
                    || ! chmod($this->path, 0600)) {
                    throw new RuntimeException('The concurrent installer access key is malformed or unsafe.');
                }
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function isAvailable(): bool
    {
        try {
            $this->assertSafeDirectory();
        } catch (RuntimeException) {
            return false;
        }

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
        try {
            $this->assertSafeDirectory();
        } catch (RuntimeException) {
            return false;
        }

        if (! file_exists($this->path)) {
            return true;
        }

        if (is_link($this->path) || ! is_file($this->path)) {
            return false;
        }

        return @unlink($this->path) && ! file_exists($this->path);
    }


    private function assertSafeDirectory(): void
    {
        $configuredRoot = rtrim($this->basePath, DIRECTORY_SEPARATOR);
        $basePath = realpath($configuredRoot);

        if ($basePath === false || $basePath !== $configuredRoot) {
            throw new RuntimeException('The private installer application root is unsafe.');
        }

        $storage = $basePath.'/storage';
        $applicationStorage = $storage.'/app';
        $directory = $applicationStorage.'/private';

        if (is_link($storage) || is_link($applicationStorage) || is_link($directory)) {
            throw new RuntimeException('The private installer state directory must not traverse symbolic links.');
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false || $realDirectory !== $directory || ! is_dir($realDirectory)) {
            throw new RuntimeException('The private installer state directory is unavailable or escapes its expected path.');
        }
    }

    public function path(): string
    {
        return $this->path;
    }
}
