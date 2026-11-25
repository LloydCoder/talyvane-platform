<?php
declare(strict_types=1);

namespace Talyvane\Installer;

use RuntimeException;
use Talyvane\Security\SecretBox;
use Throwable;

/** Small encrypted, atomic, private state file for the browser installer. */
final class PrivateInstallerState
{
    public function __construct(
        private string $stateFile,
        private string $publicRoot,
        private SecretBox $box,
        private string $context
    ) {
        $public = realpath($publicRoot);
        $directory = realpath(dirname($stateFile));
        if ($public === false || $directory === false || is_link($stateFile) ||
            self::isWithin($directory,$public) || !is_writable($directory) ||
            !preg_match('/^[a-z0-9._-]{8,80}$/D',$context)) {
            throw new RuntimeException('Private installer state path is invalid.');
        }
    }

    /** @param array<string,mixed> $state */
    public function write(array $state): void
    {
        if (is_link($this->stateFile) || (file_exists($this->stateFile) && !is_file($this->stateFile))) {
            throw new RuntimeException('Private installer state file is invalid.');
        }
        $json = json_encode($state,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 250_000) {
            throw new RuntimeException('Installer state exceeds the private state limit.');
        }
        $envelope = $this->box->encrypt($json,$this->context) . PHP_EOL;
        $temporary = dirname($this->stateFile) . DIRECTORY_SEPARATOR . '.installer-' . bin2hex(random_bytes(12)) . '.tmp';
        $previousUmask = umask(0077);
        $handle = @fopen($temporary,'x');
        umask($previousUmask);
        if ($handle === false) {
            throw new RuntimeException('Private installer state could not be staged.');
        }
        try {
            if (fwrite($handle,$envelope) !== strlen($envelope) || !fflush($handle)) {
                throw new RuntimeException('Private installer state could not be written completely.');
            }
        } finally {
            fclose($handle);
            @chmod($temporary,0600);
        }
        if (!@rename($temporary,$this->stateFile)) {
            @unlink($temporary);
            throw new RuntimeException('Private installer state could not be committed.');
        }
        @chmod($this->stateFile,0600);
    }

    /** @return array<string,mixed>|null */
    public function read(): ?array
    {
        if (!file_exists($this->stateFile)) {
            return null;
        }
        if (!is_file($this->stateFile) || is_link($this->stateFile) || !is_readable($this->stateFile)) {
            throw new RuntimeException('Private installer state is unavailable.');
        }
        $raw = file_get_contents($this->stateFile);
        if (!is_string($raw) || strlen($raw) > 400_000) {
            throw new RuntimeException('Private installer state is corrupt or too large.');
        }
        $plain = $this->box->decrypt(trim($raw),$this->context);
        try {
            $state = json_decode($plain,true,32,JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Private installer state payload is corrupt.');
        }
        if (!is_array($state)) {
            throw new RuntimeException('Private installer state payload is invalid.');
        }
        return $state;
    }

    public function delete(): void
    {
        if (is_link($this->stateFile)) {
            throw new RuntimeException('Refusing to delete a symlink installer state.');
        }
        if (is_file($this->stateFile) && !@unlink($this->stateFile)) {
            throw new RuntimeException('Private installer state could not be removed.');
        }
    }

    private static function isWithin(string $path,string $parent): bool
    {
        $path = rtrim($path,DIRECTORY_SEPARATOR);
        $parent = rtrim($parent,DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR,$parent . DIRECTORY_SEPARATOR);
    }
}
