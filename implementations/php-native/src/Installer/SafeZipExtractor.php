<?php
declare(strict_types=1);

namespace Talyvane\Installer;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use ZipArchive;

final class SafeZipExtractor
{
    /** @return array{files:int,bytes:int} */
    public static function extract(string $archivePath, string $destination, int $maxEntries = 10000, int $maxBytes = 104857600): array
    {
        if (!class_exists(ZipArchive::class) || !is_file($archivePath) || is_link($archivePath)) {
            throw new \RuntimeException('A readable ZIP archive and PHP ZipArchive extension are required.');
        }
        $parent = realpath(dirname($destination));
        if ($parent === false || !is_writable($parent) || file_exists($destination) || is_link(dirname($destination))) {
            throw new \RuntimeException('Extraction destination must be new and have a writable, trusted parent directory.');
        }
        $baseName = basename($destination);
        if ($baseName === '' || $baseName === '.' || $baseName === '..' || str_contains($baseName, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Extraction destination name is invalid.');
        }
        $staging = $parent . DIRECTORY_SEPARATOR . '.' . $baseName . '.extract-' . bin2hex(random_bytes(8));
        if (!@mkdir($staging, 0700)) {
            throw new \RuntimeException('Private extraction staging directory could not be created.');
        }
        $zip = new ZipArchive();
        $opened = $zip->open($archivePath, ZipArchive::RDONLY);
        if ($opened !== true) {
            @rmdir($staging);
            throw new \RuntimeException('ZIP archive could not be opened.');
        }
        $seen = [];
        $totalBytes = 0;
        $fileCount = 0;
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > $maxEntries) {
                throw new \RuntimeException('ZIP archive contains an unsupported number of entries.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name'],$stat['size'],$stat['comp_size'])) {
                    throw new \RuntimeException('ZIP entry metadata is invalid.');
                }
                $name = (string) $stat['name'];
                self::validateEntryName($name);
                $normalized = rtrim($name, '/');
                if (isset($seen[$normalized])) {
                    throw new \RuntimeException('ZIP archive contains duplicate paths.');
                }
                $seen[$normalized] = true;
                $size = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];
                if ($size < 0 || $size > 20971520 || ($size > 0 && ($compressed < 1 || $size > $compressed * 100))) {
                    throw new \RuntimeException('ZIP entry exceeds the permitted size or compression ratio.');
                }
                $totalBytes += $size;
                if ($totalBytes > $maxBytes) {
                    throw new \RuntimeException('ZIP archive exceeds the total extracted size limit.');
                }
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                    $mode = ($attributes >> 16) & 0170000;
                    if ($opsys === ZipArchive::OPSYS_UNIX && $mode !== 0 && !in_array($mode, [0100000,0040000], true)) {
                        throw new \RuntimeException('ZIP archive contains a link or special filesystem entry.');
                    }
                }
                $target = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
                if (str_ends_with($name, '/')) {
                    self::ensureDirectory($staging, $target);
                    continue;
                }
                $directory = dirname($target);
                self::ensureDirectory($staging, $directory);
                if (file_exists($target) || is_link($target)) {
                    throw new \RuntimeException('ZIP archive would overwrite an existing path.');
                }
                $input = $zip->getStream($name);
                if ($input === false) {
                    throw new \RuntimeException('ZIP entry stream could not be opened.');
                }
                $output = @fopen($target, 'xb');
                if ($output === false) {
                    fclose($input);
                    throw new \RuntimeException('ZIP entry could not be created safely.');
                }
                try {
                    $copied = stream_copy_to_stream($input, $output);
                    if ($copied !== $size || !fflush($output)) {
                        throw new \RuntimeException('ZIP entry extraction was incomplete.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                @chmod($target, 0600);
                $fileCount++;
            }
            $zip->close();
            if (!@rename($staging, $destination)) {
                throw new \RuntimeException('Extracted payload could not be committed atomically.');
            }
            @chmod($destination, 0700);
            return ['files'=>$fileCount,'bytes'=>$totalBytes];
        } catch (\Throwable $error) {
            $zip->close();
            self::removeTree($staging);
            throw $error;
        }
    }

    private static function validateEntryName(string $name): void
    {
        if ($name === '' || strlen($name) > 240 || str_contains($name, "\0") ||
            str_contains($name, '\\') || str_starts_with($name, '/') ||
            preg_match('/^[a-zA-Z]:/', $name) === 1) {
            throw new \RuntimeException('ZIP entry path is invalid.');
        }
        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('ZIP entry path traversal is not allowed.');
            }
        }
    }

    private static function ensureDirectory(string $root, string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('ZIP extraction directory could not be created.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !self::isWithin($resolved, $root) || is_link($directory)) {
            throw new \RuntimeException('ZIP extraction escaped its staging directory.');
        }
        @chmod($resolved, 0700);
    }

    private static function isWithin(string $path, string $parent): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $parent = rtrim($parent, DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
