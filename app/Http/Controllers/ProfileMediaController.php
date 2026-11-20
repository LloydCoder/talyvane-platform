<?php

namespace App\Http\Controllers;

use App\Models\ProfileMedia;
use App\Services\FeatureRegistry;
use App\Services\MembershipEntitlementService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ProfileMediaController extends Controller
{
    public function preview(ProfileMedia $media): Response
    {
        abort_unless(auth()->check() && in_array(auth()->user()->role, ['owner', 'admin'], true), 403);
        abort_unless($media->status === 'pending_review', 404);
        abort_unless(Storage::disk('local')->exists($media->storage_path), 404);

        return response(Storage::disk('local')->get($media->storage_path), 200, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline; filename="pending-profile-image.webp"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function show(
        ProfileMedia $media,
        FeatureRegistry $features,
        MembershipEntitlementService $entitlements,
    ): Response {
        abort_unless($media->status === 'approved', 404);
        if ($media->access_level === 'members_only') {
            $client = auth()->user();
            abort_unless(
                $client
                && $features->isOperationallyEnabled('memberships.entitlements')
                && $entitlements->has($client, 'media.private_gallery'),
                404,
            );
        } elseif ($media->access_level !== 'public') {
            abort(404);
        }
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
