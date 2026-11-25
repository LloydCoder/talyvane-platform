<?php
declare(strict_types=1);

namespace Talyvane\Installer;

final class PrivateConfigStore
{
    public function __construct(private string $configFile, private string $publicRoot)
    {
        $public = realpath($publicRoot);
        if ($public === false || is_link($configFile)) {
            throw new \RuntimeException('Private configuration path is invalid.');
        }
        $parent = realpath(dirname($configFile));
        if ($parent === false || self::isWithin($parent . DIRECTORY_SEPARATOR . basename($configFile), $public)) {
            throw new \RuntimeException('Application configuration must be stored outside the public document root.');
        }
        @chmod($parent, 0700);
    }

    /** @param array<string,mixed> $config */
    public function create(array $config): void
    {
        if (is_file($this->configFile) || file_exists($this->configFile) || is_link($this->configFile)) {
            throw new \RuntimeException('Private application configuration already exists.');
        }
        if (!isset($config['app_key']) || !is_string($config['app_key']) || strlen($config['app_key']) < 32 ||
            !isset($config['database']) || !is_array($config['database'])) {
            throw new \InvalidArgumentException('Required application and database configuration is missing.');
        }
        $payload = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        $temporary = dirname($this->configFile) . DIRECTORY_SEPARATOR . '.config-' . bin2hex(random_bytes(12)) . '.tmp';
        $previousUmask = umask(0077);
        $handle = @fopen($temporary, 'x');
        umask($previousUmask);
        if ($handle === false) {
            throw new \RuntimeException('Private configuration temporary file could not be created.');
        }
        try {
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
                throw new \RuntimeException('Private configuration could not be written completely.');
            }
        } finally {
            fclose($handle);
            @chmod($temporary, 0600);
        }
        $target = @fopen($this->configFile, 'x');
        if ($target === false) {
            @unlink($temporary);
            throw new \RuntimeException('Private configuration already exists or cannot be created.');
        }
        try {
            $source = fopen($temporary, 'rb');
            if ($source === false) {
                throw new \RuntimeException('Private configuration staging file is unavailable.');
            }
            try {
                if (stream_copy_to_stream($source, $target) === false || !fflush($target)) {
                    throw new \RuntimeException('Private configuration could not be committed.');
                }
            } finally {
                fclose($source);
            }
        } catch (\Throwable $error) {
            fclose($target);
            @unlink($this->configFile);
            @unlink($temporary);
            throw $error;
        }
        fclose($target);
        @chmod($this->configFile, 0600);
        @unlink($temporary);
    }

    /** @return array<string,mixed> */
    public function load(): array
    {
        if (!is_file($this->configFile) || is_link($this->configFile)) {
            throw new \RuntimeException('Private application configuration is unavailable.');
        }
        $config = require $this->configFile;
        if (!is_array($config) || !isset($config['app_key'],$config['database']) || !is_array($config['database'])) {
            throw new \RuntimeException('Private application configuration is corrupt.');
        }
        return $config;
    }

    private static function isWithin(string $path, string $parent): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $parent = rtrim($parent, DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR);
    }
}
