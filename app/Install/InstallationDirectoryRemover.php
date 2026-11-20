<?php

namespace App\Install;

final class InstallationDirectoryRemover
{
    private const RELEASE_OWNED_FILES = [
        'bootstrap.php',
        '.htaccess',
        'README.md',
        'RELEASE-MANIFEST.json',
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    public function remove(): bool
    {
        $basePath = realpath($this->basePath);
        $directory = rtrim($this->basePath, DIRECTORY_SEPARATOR).'/install';

        if ($basePath === false || is_link($directory) || ! is_dir($directory)) {
            return false;
        }

        $realDirectory = realpath($directory);
        if ($realDirectory !== $basePath.'/install') {
            return false;
        }

        $entries = array_values(array_diff(scandir($realDirectory) ?: [], ['.', '..']));
        sort($entries);
        $expected = self::RELEASE_OWNED_FILES;
        sort($expected);

        if ($entries !== $expected) {
            return false;
        }

        foreach (self::RELEASE_OWNED_FILES as $file) {
            $path = $realDirectory.'/'.$file;
            if (is_link($path) || ! is_file($path) || ! @unlink($path)) {
                return false;
            }
        }

        return @rmdir($realDirectory);
    }
}
