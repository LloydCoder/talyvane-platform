<?php

namespace App\\Services;

use Illuminate\\Http\\UploadedFile;
use Illuminate\\Support\\Facades\\Storage;
use Illuminate\\Validation\\ValidationException;
use RuntimeException;

class ProfileMediaProcessor
{
    /**
     * Decode and re-encode the upload as WebP. Re-encoding discards EXIF,
     * GPS, camera metadata, embedded comments and the original filename.
     *
     * @return array{path:string,width:int,height:int,size:int}
     */
    public function storeSanitized(UploadedFile $file): array
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages([
                'media' => 'Secure image processing is unavailable. Please contact the agency administrator.',
            ]);
        }

        $bytes = file_get_contents($file->getRealPath());
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);

        if (! $image) {
            throw ValidationException::withMessages(['media' => 'The uploaded image could not be decoded.']);
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 320 || $height < 320 || $width > 6000 || $height > 6000 || ($width * $height) > 24000000) {
            imagedestroy($image);
            throw ValidationException::withMessages(['media' => 'Images must be at least 320px and no larger than 6000px per side or 24 megapixels.']);
        }

        ob_start();
        $encoded = imagewebp($image, null, 84);
        $sanitized = ob_get_clean();
        imagedestroy($image);

        if (! $encoded || ! is_string($sanitized) || $sanitized === '') {
            throw new RuntimeException('The image could not be safely re-encoded.');
        }

        $path = 'profile-media/'.bin2hex(random_bytes(24)).'.webp';
        Storage::disk('local')->put($path, $sanitized);

        return [
            'path' => $path,
            'width' => $width,
            'height' => $height,
            'size' => strlen($sanitized),
        ];
    }
}
