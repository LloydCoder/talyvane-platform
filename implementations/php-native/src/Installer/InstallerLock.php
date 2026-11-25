<?php
declare(strict_types=1);

namespace Talyvane\Installer;

final class InstallerLock
{
    public function __construct(private string $lockFile)
    {
        if (is_link($lockFile)) {
            throw new \RuntimeException('Installer lock must not be a symlink.');
        }
    }

    public function isLocked(): bool
    {
        if (!file_exists($this->lockFile)) {
            return false;
        }
        if (!is_file($this->lockFile) || is_link($this->lockFile)) {
            throw new \RuntimeException('Installer lock state is invalid; refusing to continue.');
        }
        $raw = file_get_contents($this->lockFile);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || ($state['state'] ?? null) !== 'installed' ||
            !is_string($state['installation_id'] ?? null) || !is_string($state['completed_at'] ?? null)) {
            throw new \RuntimeException('Installer lock is corrupt; refusing to reopen installation.');
        }
        return true;
    }

    public function lock(string $installationId): void
    {
        if (!preg_match('/^[a-f0-9-]{36}$/iD', $installationId)) {
            throw new \InvalidArgumentException('Installation identifier is invalid.');
        }
        $directory = dirname($this->lockFile);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('Private installer state directory is unavailable.');
        }
        $state = json_encode([
            'state'=>'installed',
            'installation_id'=>$installationId,
            'completed_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'format'=>1,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $previousUmask = umask(0077);
        $handle = @fopen($this->lockFile, 'x');
        umask($previousUmask);
        if ($handle === false) {
            throw new \RuntimeException('Installer lock already exists or could not be created.');
        }
        try {
            if (fwrite($handle, $state . PHP_EOL) === false || !fflush($handle)) {
                throw new \RuntimeException('Permanent installer lock could not be written.');
            }
        } finally {
            fclose($handle);
            @chmod($this->lockFile, 0600);
        }
        if (!$this->isLocked()) {
            throw new \RuntimeException('Permanent installer lock could not be verified.');
        }
    }
}
