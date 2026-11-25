<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Storage;

final class PrivateMediaStorage
{
    private string $root;

    public function __construct(string $privateRoot, string $publicRoot)
    {
        $public = realpath($publicRoot);
        if ($public === false) {
            throw new \RuntimeException('Public root cannot be resolved.');
        }
        if (!is_dir($privateRoot)) {
            if (!@mkdir($privateRoot, 0700, true) && !is_dir($privateRoot)) {
                throw new \RuntimeException('Private media storage could not be created.');
            }
        }
        @chmod($privateRoot, 0700);
        $root = realpath($privateRoot);
        if ($root === false || self::isWithin($root, $public) || is_link($privateRoot)) {
            throw new \RuntimeException('Private media storage must be outside the public root and must not be a symlink.');
        }
        $this->root = $root;
    }

    /**
     * Re-encodes accepted raster uploads to WebP, stripping metadata.
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $upload
     * @return array{key:string,mime:string,size:int,sha256:string,width:int,height:int}
     */
    public function storeImage(array $upload, string $category, int $maxBytes = 10485760): array
    {
        $categories = ['profile-media','destination-covers','model-portfolio'];
        if (!in_array($category, $categories, true)) {
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
        if (!is_string($mime) || !in_array($mime, $allowed, true)) {
            throw new \InvalidArgumentException('Only JPEG, PNG and WebP images are accepted.');
        }
        $imageInfo = @getimagesize($temporary);
        if (!is_array($imageInfo) || !isset($imageInfo[0], $imageInfo[1], $imageInfo['mime']) || $imageInfo['mime'] !== $mime) {
            throw new \InvalidArgumentException('Image content does not match its declared format.');
        }
        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000 || ($width * $height) > 30000000) {
            throw new \InvalidArgumentException('Image dimensions exceed the supported limit.');
        }
        if (!function_exists('imagewebp') || !function_exists('imagecreatefromjpeg') ||
            !function_exists('imagecreatefrompng') || !function_exists('imagecreatefromwebp')) {
            throw new \RuntimeException('GD with WebP support is required for safe image processing.');
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
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            imagedestroy($image);
            throw new \RuntimeException('Private media category could not be created.');
        }
        @chmod($directory, 0700);
        $filename = bin2hex(random_bytes(16)) . '.webp';
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        try {
            if (!imagewebp($image, $target, 82) || !is_file($target)) {
                throw new \RuntimeException('Image re-encoding failed.');
            }
        } finally {
            imagedestroy($image);
        }
        @chmod($target, 0600);
        $storedSize = filesize($target);
        $hash = hash_file('sha256', $target);
        if (!is_int($storedSize) || !is_string($hash)) {
            @unlink($target);
            throw new \RuntimeException('Stored image could not be verified.');
        }
        return ['key'=>$category . '/' . $filename,'mime'=>'image/webp','size'=>$storedSize,'sha256'=>$hash,'width'=>$width,'height'=>$height];
    }

    public function delete(string $key): void
    {
        if (!self::validKey($key)) {
            throw new \InvalidArgumentException('Private media key is invalid.');
        }
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
        if (is_link($path)) {
            throw new \RuntimeException('Refusing to delete a symlink from private media storage.');
        }
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException('Private media could not be deleted.');
        }
    }

    public static function validKey(string $key): bool
    {
        return preg_match('#^(profile-media|destination-covers|model-portfolio)/[a-f0-9]{32}\.webp$#D', $key) === 1;
    }

    private static function isWithin(string $path, string $parent): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $parent = rtrim($parent, DIRECTORY_SEPARATOR);
        return $path === $parent || str_starts_with($path . DIRECTORY_SEPARATOR, $parent . DIRECTORY_SEPARATOR);
    }
}
