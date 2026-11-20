<?php

namespace App\Http\Controllers;

use App\Models\ProfileMedia;
use App\Services\MembershipEntitlementCatalog;
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
            $currentConsentVersion = config('talyvane.privacy.private_gallery_consent_version');
            $expectedStatement = MembershipEntitlementCatalog::privateGalleryConsentStatement();
            $grantEvent = $media->consentEvents()
                ->where('action', 'members_only_consent.granted')
                ->oldest('id')
                ->first();
            abort_unless(
                $media->members_only_consent_at
                && ! $media->members_only_consent_withdrawn_at
                && config('talyvane.privacy.private_gallery_consent_approved')
                && filled($currentConsentVersion)
                && hash_equals((string) $currentConsentVersion, (string) $media->members_only_consent_version)
                && $grantEvent
                && hash_equals((string) $expectedStatement, (string) $grantEvent->consent_statement)
                && hash_equals(hash('sha256', $expectedStatement), (string) $grantEvent->statement_sha256)
                && hash_equals((string) $currentConsentVersion, (string) $grantEvent->consent_policy_version),
                404,
            );
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
