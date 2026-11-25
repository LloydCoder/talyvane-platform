<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Storage;

use RuntimeException;

final class PrivateMediaStorage
{
    private string $root;

    public function __construct(string $privateRoot,string $publicRoot)
    {
        $public = realpath($publicRoot);
        if ($public === false || is_link($privateRoot)) {
            throw new RuntimeException('Private media storage path is invalid.');
        }
        if (!is_dir($privateRoot) && !@mkdir($privateRoot,0700,true) && !is_dir($privateRoot)) {
            throw new RuntimeException('Private media storage could not be created.');
        }
        $root = realpath($privateRoot);
        if ($root === false || self::isWithin($root,$public) || is_link($privateRoot) || !is_writable($root)) {
            throw new RuntimeException('Private media storage must be writable, outside the public root and not a symlink.');
        }
        @chmod($root,0700);
        $this->root = $root;
    }

    /**
     * Decode and re-encode an uploaded raster image as WebP, removing embedded metadata.
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $upload
     * @return array{key:string,mime:string,size:int,sha256:string,width:int,height:int}
     */
    public function storeImage(array $upload,string $category,int $maxBytes=8_388_608): array
    {
        $categories = ['profile-media','destination-covers','model-portfolio'];
        if (!in_array($category,$categories,true)) {
            throw new \InvalidArgumentException('Media category is not allowed.');
        }
        $temporary = $upload['tmp_name'] ?? '';
        $size = (int) ($upload['size'] ?? 0);
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !is_string($temporary) || $temporary === '' || !is_uploaded_file($temporary) ||
            $size < 1 || $size > $maxBytes || !is_file($temporary)) {
            throw new \InvalidArgumentException('Image upload is missing, invalid, or exceeds the size limit.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporary);
        $allowed = ['image/jpeg','image/png','image/webp'];
        if (!is_string($mime) || !in_array($mime,$allowed,true)) {
            throw new \InvalidArgumentException('Only JPEG, PNG and WebP images are accepted.');
        }
        $imageInfo = @getimagesize($temporary);
        if (!is_array($imageInfo) || !isset($imageInfo[0],$imageInfo[1],$imageInfo['mime']) || $imageInfo['mime'] !== $mime) {
            throw new \InvalidArgumentException('Image content does not match its detected format.');
        }
        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        if ($width < 320 || $height < 320 || $width > 6000 || $height > 6000 || ($width * $height) > 16_000_000) {
            throw new \InvalidArgumentException('Images must be at least 320px and no larger than 6000px per side or 16 megapixels.');
        }
        self::assertMemoryBudget($width,$height);
        if (!function_exists('imagewebp') || !function_exists('imagecreatefromjpeg') ||
            !function_exists('imagecreatefrompng') || !function_exists('imagecreatefromwebp')) {
            throw new RuntimeException('GD with WebP support is required for safe image processing.');
        }
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($temporary),
            'image/png' => @imagecreatefrompng($temporary),
            'image/webp' => @imagecreatefromwebp($temporary),
        };
        if ($image === false) {
            throw new \InvalidArgumentException('Image decoder rejected the uploaded file.');
        }

        $directory = $this->root . DIRECTORY_SEPARATOR . $category;
        if (is_link($directory)) {
            imagedestroy($image);
            throw new RuntimeException('Private media category must not be a symlink.');
        }
        if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) {
            imagedestroy($image);
            throw new RuntimeException('Private media category could not be created.');
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || $resolvedDirectory !== $directory || !self::isWithin($resolvedDirectory,$this->root)) {
            imagedestroy($image);
            throw new RuntimeException('Private media category resolved outside the private storage root.');
        }
        @chmod($resolvedDirectory,0700);

        $filename = bin2hex(random_bytes(16)) . '.webp';
        $temporaryTarget = $resolvedDirectory . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(16)) . '.tmp';
        $target = $resolvedDirectory . DIRECTORY_SEPARATOR . $filename;
        try {
            if (file_exists($temporaryTarget) || file_exists($target) ||
                !imagewebp($image,$temporaryTarget,84) || !is_file($temporaryTarget) || is_link($temporaryTarget)) {
                throw new RuntimeException('Image re-encoding failed.');
            }
        } finally {
            imagedestroy($image);
        }
        @chmod($temporaryTarget,0600);
        $storedMime = $finfo->file($temporaryTarget);
        $storedSize = filesize($temporaryTarget);
        $hash = hash_file('sha256',$temporaryTarget);
        if ($storedMime !== 'image/webp' || !is_int($storedSize) || $storedSize < 1 ||
            $storedSize > $maxBytes || !is_string($hash) || !@rename($temporaryTarget,$target)) {
            @unlink($temporaryTarget);
            throw new RuntimeException('Sanitized image could not be verified and committed.');
        }
        @chmod($target,0600);
        return ['key'=>$category . '/' . $filename,'mime'=>'image/webp','size'=>$storedSize,'sha256'=>$hash,'width'=>$width,'height'=>$height];
    }

    public function read(string $key,int $maxBytes=8_388_608): string
    {
        $path = $this->resolveKey($key);
        if (!is_file($path) || !is_readable($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Private media file is unavailable.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $maxBytes) {
            throw new RuntimeException('Private media file exceeds its storage limit.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        if ($finfo->file($path) !== 'image/webp') {
            throw new RuntimeException('Private media file content is invalid.');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('Private media file could not be read.');
        }
        return $bytes;
    }

    public function delete(string $key): void
    {
        $path = $this->resolveKey($key);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Private media could not be deleted.');
        }
    }

    public static function validKey(string $key): bool
    {
        return preg_match('#^(profile-media|destination-covers|model-portfolio)/[a-f0-9]{32}\.webp$#D',$key) === 1;
    }

    private function resolveKey(string $key): string
    {
        if (!self::validKey($key)) {
            throw new \InvalidArgumentException('Private media key is invalid.');
        }
        $parts = explode('/',$key,2);
        $directory = $this->root . DIRECTORY_SEPARATOR . $parts[0];
        if (is_link($directory)) {
            throw new RuntimeException('Private media directory must not be a symlink.');
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || $resolvedDirectory !== $directory || !self::isWithin($resolvedDirectory,$this->root)) {
            throw new RuntimeException('Private media directory is unavailable.');
        }
        $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $parts[1];
        if (is_link($path)) {
            throw new RuntimeException('Private media file must not be a symlink.');
        }
        $resolvedPath = realpath($path);
        if ($resolvedPath !== false && $resolvedPath !== $path) {
            throw new RuntimeException('Private media path is not canonical.');
        }
        return $path;
    }

    private static function assertMemoryBudget(int $width,int $height): void
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return;
        }
        $unit = strtolower(substr($limit,-1));
        $value = (float) $limit;
        $bytes = match ($unit) {
            'g' => (int) ($value * 1024 * 1024 * 1024),
            'm' => (int) ($value * 1024 * 1024),
            'k' => (int) ($value * 1024),
            default => (int) $value,
        };
        $required = ($width * $height * 5) + 16_777_216;
        if ($bytes > 0 && $bytes < $required) {
            throw new RuntimeException('PHP memory_limit is too low to safely decode this image.');
        }
    }

    private static function isWithin(string $path,string $parent): bool
    {
        $path = rtrim($path,DIRECTORY_SEPARATOR);
        $parent = rtrim($parent,DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR,$parent . DIRECTORY_SEPARATOR);
    }
}
