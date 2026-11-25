<?php
declare(strict_types=1);

namespace Talyvane\Installer;

final class PrivatePathResolver
{
    /**
     * Resolve a stable private application root under the cPanel account home.
     * The caller must pass canonical paths obtained from the web SAPI.
     *
     * @return array{home:string,document_root:string,application:string,config:string,logs:string,storage:string,backups:string}
     */
    public static function resolve(string $documentRoot, string $accountHome, string $applicationKey = 'talyvane'): array
    {
        $public = realpath($documentRoot);
        $home = realpath($accountHome);
        if ($public === false || $home === false || !is_dir($public) || !is_dir($home) || !is_writable($home)) {
            throw new \RuntimeException('The account home and document root must exist and the account home must be writable.');
        }
        if (is_link($documentRoot) || is_link($accountHome) || !self::isWithin($public, $home) || $public === $home) {
            throw new \RuntimeException('The document root must be a real directory beneath the account home.');
        }
        if (!preg_match('/^[a-z][a-z0-9-]{2,40}$/D', $applicationKey)) {
            throw new \InvalidArgumentException('Application key is invalid.');
        }
        $fingerprint = substr(hash('sha256', $public), 0, 24);
        $root = $home . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . $applicationKey . DIRECTORY_SEPARATOR . $fingerprint;
        if (self::isWithin($root, $public)) {
            throw new \RuntimeException('Private application path would be inside the public document root.');
        }
        $paths = [
            'application' => $root . DIRECTORY_SEPARATOR . 'app',
            'config' => $root . DIRECTORY_SEPARATOR . 'config',
            'logs' => $root . DIRECTORY_SEPARATOR . 'logs',
            'storage' => $root . DIRECTORY_SEPARATOR . 'storage',
            'backups' => $root . DIRECTORY_SEPARATOR . 'backups',
        ];
        self::ensurePrivateDirectory($home, dirname($root));
        foreach ($paths as $path) {
            self::ensurePrivateDirectory($home, $path);
        }
        foreach ($paths as $path) {
            $resolved = realpath($path);
            if ($resolved === false || self::isWithin($resolved, $public) || !self::isWithin($resolved, $home)) {
                throw new \RuntimeException('A private application directory resolved outside the permitted account boundary.');
            }
        }
        return [
            'home'=>$home,
            'document_root'=>$public,
            'application'=>realpath($paths['application']),
            'config'=>realpath($paths['config']),
            'logs'=>realpath($paths['logs']),
            'storage'=>realpath($paths['storage']),
            'backups'=>realpath($paths['backups']),
        ];
    }

    private static function ensurePrivateDirectory(string $home, string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('Private directory path must not contain a symlink at its final component.');
        }
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Could not create a private application directory.');
        }
        $resolved = realpath($path);
        if ($resolved === false || !self::isWithin($resolved, $home) || is_link($path)) {
            throw new \RuntimeException('Private directory escaped the account home boundary.');
        }
        @chmod($resolved, 0700);
    }

    private static function isWithin(string $path, string $parent): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $parent = rtrim($parent, DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR);
    }
}
