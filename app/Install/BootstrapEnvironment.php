<?php

namespace App\Install;

use RuntimeException;

final class BootstrapEnvironment
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Prepare the minimum safe environment required for the first Laravel request.
     *
     * This method deliberately does not configure a database, run migrations, or
     * provision an administrator. Those operations belong to the authenticated,
     * CSRF-protected installer workflow.
     */
    public function ensure(): bool
    {
        $basePath = realpath($this->basePath);

        if ($basePath === false || ! is_dir($basePath)) {
            throw new RuntimeException('The application root is unavailable.');
        }

        $privateDirectory = $basePath.'/storage/app/private';
        if (is_link($privateDirectory)) {
            throw new RuntimeException('The private installer state directory must not be a symbolic link.');
        }

        $lockPath = $privateDirectory.'/talyvane.install.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('The installation lock must not be a symbolic link.');
        }

        if (is_file($lockPath)) {
            return false;
        }

        $environmentPath = $basePath.'/.env';
        if (is_link($environmentPath)) {
            throw new RuntimeException('The environment file must not be a symbolic link.');
        }

        $environmentExists = file_exists($environmentPath);
        if ($environmentExists && ! is_file($environmentPath)) {
            throw new RuntimeException('The environment path is not a regular file.');
        }

        $contents = $environmentExists
            ? file_get_contents($environmentPath)
            : $this->readExampleEnvironment($basePath);

        if (! is_string($contents)) {
            throw new RuntimeException('The environment file could not be read.');
        }

        if (preg_match('/^APP_KEY=(?!\s*$).+$/m', $contents) === 1) {
            return false;
        }

        $contents = $this->setEnvironmentValue(
            $contents,
            'APP_KEY',
            'base64:'.base64_encode(random_bytes(32)),
        );

        // These safe local drivers allow the installer to render its first
        // session-protected page before database credentials have been supplied.
        $contents = $this->setEnvironmentValue($contents, 'APP_ENV', 'production');
        $contents = $this->setEnvironmentValue($contents, 'APP_DEBUG', 'false');
        $contents = $this->setEnvironmentValue($contents, 'SESSION_DRIVER', 'file');
        $contents = $this->setEnvironmentValue($contents, 'SESSION_ENCRYPT', 'true');
        $contents = $this->setEnvironmentValue($contents, 'SESSION_SECURE_COOKIE', 'true');
        $contents = $this->setEnvironmentValue($contents, 'CACHE_STORE', 'file');
        $contents = $this->setEnvironmentValue($contents, 'QUEUE_CONNECTION', 'sync');
        $contents = $this->setEnvironmentValue($contents, 'TALYVANE_INSTALLER_ENFORCE', 'true');

        $temporaryPath = tempnam($basePath, '.talyvane-env-');
        if ($temporaryPath === false || dirname($temporaryPath) !== $basePath) {
            throw new RuntimeException('A safe temporary environment file could not be created.');
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('The bootstrap environment could not be written securely.');
            }

            if (! rename($temporaryPath, $environmentPath)) {
                throw new RuntimeException('The bootstrap environment could not be committed atomically.');
            }

            @chmod($environmentPath, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return true;
    }

    private function readExampleEnvironment(string $basePath): string
    {
        $examplePath = $basePath.'/.env.example';

        if (is_link($examplePath) || ! is_file($examplePath) || ! is_readable($examplePath)) {
            throw new RuntimeException('A readable, regular environment example is required for first installation.');
        }

        $contents = file_get_contents($examplePath);

        if (! is_string($contents)) {
            throw new RuntimeException('The environment example could not be read.');
        }

        return $contents;
    }

    private function setEnvironmentValue(string $contents, string $key, string $value): string
    {
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $replacement = $key.'='.$value;
        $updated = preg_replace($pattern, $replacement, $contents, 1, $count);

        if (! is_string($updated)) {
            throw new RuntimeException('The bootstrap environment could not be normalized.');
        }

        if ($count === 0) {
            $updated = rtrim($updated, "\r\n")."\n".$replacement."\n";
        }

        return $updated;
    }
}
