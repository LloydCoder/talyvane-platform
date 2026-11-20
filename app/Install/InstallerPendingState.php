<?php

namespace App\Install;

use RuntimeException;

final class InstallerPendingState
{
    private readonly string $path;

    public function __construct(private readonly string $basePath)
    {
        $this->path = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'storage/app/private/talyvane.install.pending.json';
    }

    public function acquire(int $lifetimeSeconds = 900): string
    {
        $this->assertSafeDirectory();

        if (is_link($this->path) || (file_exists($this->path) && ! is_file($this->path))) {
            throw new RuntimeException('The pending installation state path is unsafe.');
        }

        if (is_file($this->path)) {
            $existing = $this->readState();
            if ($existing['expires_at'] > time()) {
                throw new RuntimeException('An installation verification is already in progress.');
            }

            if (! @unlink($this->path)) {
                throw new RuntimeException('The expired installation state could not be cleared safely.');
            }
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $state = json_encode([
            'schema_version' => 1,
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => time() + $lifetimeSeconds,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $temporaryPath = tempnam(dirname($this->path), '.installer-pending-');
        if (! is_string($temporaryPath)) {
            throw new RuntimeException('The pending installation state could not be prepared.');
        }

        try {
            if (file_put_contents($temporaryPath, $state, LOCK_EX) !== strlen($state)
                || ! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('The pending installation state could not be prepared securely.');
            }

            // Publish only a complete state document; never expose a partial
            // marker to a concurrent installer request.
            if (! @link($temporaryPath, $this->path)) {
                if (is_link($this->path) || ! is_file($this->path)) {
                    throw new RuntimeException('Another installation request acquired the pending state.');
                }

                $existing = $this->readState();
                if ($existing['expires_at'] > time()) {
                    throw new RuntimeException('An installation verification is already in progress.');
                }

                throw new RuntimeException('The expired installation state changed during acquisition; retry safely.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return $nonce;
    }

    public function verify(string $nonce): bool
    {
        try {
            $this->assertSafeDirectory();
            $state = $this->readState();
        } catch (RuntimeException) {
            return false;
        }

        return $state['expires_at'] > time()
            && hash_equals($state['nonce_hash'], hash('sha256', $nonce));
    }

    public function release(string $nonce): bool
    {
        try {
            $this->assertSafeDirectory();
            $state = $this->readState();
        } catch (RuntimeException) {
            return false;
        }

        if (! hash_equals($state['nonce_hash'], hash('sha256', $nonce))) {
            return false;
        }

        return @unlink($this->path) && ! file_exists($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array{schema_version: int, nonce_hash: string, expires_at: int}
     */
    private function readState(): array
    {
        if (is_link($this->path) || ! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException('The pending installation state is unavailable.');
        }

        $state = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($state)
            || ($state['schema_version'] ?? null) !== 1
            || ! is_string($state['nonce_hash'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $state['nonce_hash']) !== 1
            || ! is_int($state['expires_at'] ?? null)) {
            throw new RuntimeException('The pending installation state is malformed.');
        }

        return $state;
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

        if (! is_dir($directory) || realpath($directory) !== $directory || ! is_writable($directory)) {
            throw new RuntimeException('The private installer state directory is unavailable.');
        }
    }
}
