<?php

namespace App\\Http\\Controllers;

use App\\Models\\ProfileMedia;
use Illuminate\\Http\\Response;
use Illuminate\\Support\\Facades\\Storage;

class ProfileMediaController extends Controller
{
    public function show(ProfileMedia $media): Response
    {
        abort_unless($media->status === 'approved', 404);
        abort_unless($media->profile()->publiclyDiscoverable()->exists(), 404);
        abort_unless(Storage::disk('local')->exists($media->storage_path), 404);

        return response(Storage::disk('local')->get($media->storage_path), 200, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline; filename="profile-image.webp"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
