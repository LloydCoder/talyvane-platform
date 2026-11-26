<?php
declare(strict_types=1);

namespace Talyvane\Installer;

/**
 * Loads the owner-only native CLI configuration and proves it is outside
 * the canonical public document root before any secret is consumed.
 */
final class PrivateCliConfig
{
    /** @return array<string,mixed> */
    public static function load(string $configPath,string $documentRoot): array
    {
        if ($configPath==='' || $documentRoot==='' || is_link($configPath) || is_link($documentRoot) ||
            !is_file($configPath) || !is_readable($configPath) || !is_dir($documentRoot)) {
            throw new \RuntimeException('Private CLI configuration paths are unavailable.');
        }
        $configFile=realpath($configPath);
        $publicRoot=realpath($documentRoot);
        $mode=$configFile===false?false:fileperms($configFile);
        if ($configFile===false || $publicRoot===false || $mode===false || ($mode & 0077)!==0 ||
            self::isWithin($configFile,$publicRoot)) {
            throw new \RuntimeException('CLI configuration must be owner-only and outside the public document root.');
        }
        $config=require $configFile;
        if (!is_array($config) || !is_string($config['app_key']??null) || strlen($config['app_key'])<32 ||
            !is_array($config['database']??null)) {
            throw new \RuntimeException('Private CLI configuration is incomplete.');
        }
        return $config;
    }

    private static function isWithin(string $path,string $parent): bool
    {
        $path=rtrim($path,DIRECTORY_SEPARATOR);
        $parent=rtrim($parent,DIRECTORY_SEPARATOR);
        return $path===$parent || str_starts_with($path.DIRECTORY_SEPARATOR,$parent.DIRECTORY_SEPARATOR);
    }
}
