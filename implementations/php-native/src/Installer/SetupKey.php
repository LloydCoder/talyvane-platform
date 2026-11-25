<?php
declare(strict_types=1);

namespace Talyvane\Installer;

final class SetupKey
{
    public function __construct(private string $privateKeyFile)
    {
        if (is_link($privateKeyFile)) {
            throw new \RuntimeException('Installer key file must not be a symlink.');
        }
    }

    /** Creates the cPanel-only plaintext key file once; never overwrites an existing key. */
    public function create(): string
    {
        $directory = dirname($this->privateKeyFile);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('Private installer key directory is unavailable.');
        }
        $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $previousUmask = umask(0077);
        $handle = @fopen($this->privateKeyFile, 'x');
        umask($previousUmask);
        if ($handle === false) {
            throw new \RuntimeException('Installer key already exists or could not be created.');
        }
        try {
            $payload = $key . PHP_EOL;
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
                throw new \RuntimeException('Installer key could not be written.');
            }
        } finally {
            fclose($handle);
            @chmod($this->privateKeyFile, 0600);
        }
        return $key;
    }

    public function verify(string $submitted): bool
    {
        if (strlen($submitted) !== 43 || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $submitted) ||
            !is_file($this->privateKeyFile) || is_link($this->privateKeyFile)) {
            return false;
        }
        $stored = @file_get_contents($this->privateKeyFile);
        return is_string($stored) && hash_equals(trim($stored), $submitted);
    }

    /** Call only after the permanent installation lock has been written and verified. */
    public function consume(): void
    {
        if (is_link($this->privateKeyFile)) {
            throw new \RuntimeException('Refusing to consume a symlink installer key.');
        }
        if (is_file($this->privateKeyFile) && !@unlink($this->privateKeyFile)) {
            throw new \RuntimeException('Installer key could not be consumed.');
        }
    }
}
