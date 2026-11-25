<?php
declare(strict_types=1);

namespace Talyvane\Installer;

use RuntimeException;
use Talyvane\Security\SecretBox;

/**
 * Encrypted, private, one-time CLI handoff for the deployment recovery key.
 * The browser installer never renders this credential.
 */
final class OneTimeRecoveryKeyHandoff
{
    private PrivateInstallerState $state;
    private string $stateFile;

    public function __construct(string $stateFile,string $publicRoot,SecretBox $box)
    {
        $this->stateFile = $stateFile;
        $this->state = new PrivateInstallerState(
            $stateFile,$publicRoot,$box,'native-installer-recovery-v1'
        );
        if (is_link($stateFile . '.consume.lock')) {
            throw new RuntimeException('Recovery handoff lock must not be a symlink.');
        }
    }

    public function store(string $recoveryKey,int $expiresAt): void
    {
        if (strlen($recoveryKey) !== 43 || !preg_match('/^[A-Za-z0-9_-]{43}$/D',$recoveryKey) ||
            $expiresAt <= time() || $expiresAt > time()+86400) {
            throw new RuntimeException('Offline recovery-key handoff is invalid.');
        }
        if (file_exists($this->stateFile) || is_link($this->stateFile)) {
            throw new RuntimeException('Offline recovery-key handoff already exists.');
        }
        $this->state->write([
            'offline_recovery_key'=>$recoveryKey,
            'expires_at'=>$expiresAt,
            'created_at'=>time(),
        ]);
    }

    /** Returns the key once, deleting its encrypted handoff before the caller can print it. */
    public function consume(): string
    {
        $lockPath = $this->stateFile . '.consume.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Recovery handoff lock must not be a symlink.');
        }
        $handle = @fopen($lockPath,'c+b');
        if ($handle === false) {
            throw new RuntimeException('Recovery handoff lock is unavailable.');
        }
        @chmod($lockPath,0600);
        try {
            if (!flock($handle,LOCK_EX)) {
                throw new RuntimeException('Recovery handoff could not be locked.');
            }
            $state = $this->state->read();
            if (!is_array($state)) {
                throw new RuntimeException('Offline recovery-key handoff is unavailable or already consumed.');
            }
            $expiresAt = $state['expires_at'] ?? null;
            $recoveryKey = $state['offline_recovery_key'] ?? null;
            if (!is_int($expiresAt) || $expiresAt <= time() ||
                !is_string($recoveryKey) || strlen($recoveryKey) !== 43 ||
                !preg_match('/^[A-Za-z0-9_-]{43}$/D',$recoveryKey)) {
                $this->state->delete();
                throw new RuntimeException('Offline recovery-key handoff is invalid or expired.');
            }
            $this->state->delete();
            return $recoveryKey;
        } finally {
            @flock($handle,LOCK_UN);
            fclose($handle);
        }
    }

}
