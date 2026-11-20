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

        $this->ensureRuntimeDirectories($basePath);

        $privateDirectory = $basePath.'/storage/app/private';
        if (is_link($privateDirectory)) {
            throw new RuntimeException('The private installer state directory must not be a symbolic link.');
        }

        $lockPath = $privateDirectory.'/talyvane.install.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('The installation lock must not be a symbolic link.');
        }
        $lockExists = file_exists($lockPath);
        if ($lockExists && ! is_file($lockPath)) {
            throw new RuntimeException('The installation lock is not a regular file.');
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

        preg_match_all('/^APP_KEY=/m', $contents, $appKeyLines);
        if (count($appKeyLines[0]) > 1) {
            throw new RuntimeException('The environment file contains duplicate application key settings.');
        }

        $hasApplicationKey = preg_match('/^APP_KEY=(?!\s*$).+$/m', $contents) === 1;
        if ($lockExists && ! $hasApplicationKey) {
            throw new RuntimeException('A completed installation requires its original application key.');
        }

        if ($hasApplicationKey) {
            preg_match('/^APP_KEY=(.*)$/m', $contents, $keyMatch);
            $configuredKey = trim((string) ($keyMatch[1] ?? ''));
            if (strlen($configuredKey) >= 2
                && (($configuredKey[0] === '"' && str_ends_with($configuredKey, '"'))
                    || ($configuredKey[0] === "'" && str_ends_with($configuredKey, "'")))) {
                $configuredKey = substr($configuredKey, 1, -1);
            }

            $decodedKey = str_starts_with($configuredKey, 'base64:')
                ? base64_decode(substr($configuredKey, 7), true)
                : $configuredKey;

            if (! is_string($decodedKey) || strlen($decodedKey) !== 32) {
                throw new RuntimeException('The configured application key is invalid for AES-256-CBC.');
            }

            if (! chmod($environmentPath, 0600)) {
                throw new RuntimeException('The private environment file permissions could not be secured.');
            }

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


    private function ensureRuntimeDirectories(string $basePath): void
    {
        $directories = [
            'storage/app/private',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ];

        foreach ($directories as $relativePath) {
            $path = $basePath;
            foreach (explode('/', $relativePath) as $segment) {
                $path .= DIRECTORY_SEPARATOR.$segment;

                if (is_link($path)) {
                    throw new RuntimeException('A required private runtime directory must not be a symbolic link.');
                }

                if (! file_exists($path) && ! @mkdir($path, 0700)) {
                    throw new RuntimeException('A required private runtime directory could not be created.');
                }

                if (! is_dir($path)) {
                    throw new RuntimeException('A required private runtime path is not a directory.');
                }
            }

            if (realpath($path) !== $path || ! chmod($path, 0700) || ! is_writable($path)) {
                throw new RuntimeException('A required private runtime directory is unsafe or not writable.');
            }
        }
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
        $updated = preg_replace($pattern, $replacement, $contents, -1, $count);

        if (! is_string($updated) || $count > 1) {
            throw new RuntimeException('The bootstrap environment could not be normalized.');
        }

        if ($count === 0) {
            $updated = rtrim($updated, "\r\n")."\n".$replacement."\n";
        }

        return $updated;
    }
}
