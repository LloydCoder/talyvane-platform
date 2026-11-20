<?php

namespace App\Http\Controllers;

use App\Models\CompanionProfile;
use App\Models\ProfileMedia;
use App\Models\ProfileMediaConsentEvent;
use App\Services\MembershipEntitlementCatalog;
use App\Services\BrandSettings;
use App\Services\ProfileMediaProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanionMediaController extends Controller
{
    public function store(Request $request, CompanionProfile $profile, ProfileMediaProcessor $processor, BrandSettings $brandSettings): RedirectResponse
    {
        abort_unless((int) $profile->user_id === (int) $request->user()->id, 403);

        if ($profile->publication_status !== 'published' || ! $profile->age_verified_at || ! $profile->publication_consent_at || $profile->consent_withdrawn_at || ! $profile->destination()->operationallyAvailable()->exists()) {
            throw ValidationException::withMessages(['media' => 'An actively published profile with current consent is required before submitting media.']);
        }

        $validated = $request->validate([
            'media' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'rights_confirmed' => ['required', 'accepted'],
            'publication_confirmed' => ['required', 'accepted'],
            'members_only_confirmed' => ['sometimes', 'accepted'],
        ]);

        $membersOnlyConsentVersion = config('talyvane.privacy.private_gallery_consent_version');
        $agencyName = trim((string) ($brandSettings->publicBrand()['brand_name'] ?? ''));
        $consentStatement = MembershipEntitlementCatalog::privateGalleryConsentStatement($agencyName);
        $membersOnlyConsentConfirmed = isset($validated['members_only_confirmed']);
        if ($membersOnlyConsentConfirmed && (
            ! filled($membersOnlyConsentVersion)
            || ! config('talyvane.privacy.private_gallery_consent_approved')
            || ! filled($agencyName)
        )) {
            throw ValidationException::withMessages([
                'members_only_confirmed' => 'The separate private-gallery consent policy is not currently approved.',
            ]);
        }

        $image = $processor->storeSanitized($validated['media']);

        try {
            DB::transaction(function () use ($profile, $request, $image, $membersOnlyConsentConfirmed, $membersOnlyConsentVersion): void {
                $lockedProfile = CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);
                abort_unless((int) $lockedProfile->user_id === (int) $request->user()->id, 403);

                if (
                    $lockedProfile->publication_status !== 'published'
                    || ! $lockedProfile->age_verified_at
                    || ! $lockedProfile->publication_consent_at
                    || $lockedProfile->consent_withdrawn_at
                    || ! $lockedProfile->destination()->first()?->lockAndCheckOperationalAvailability()
                ) {
                    throw ValidationException::withMessages(['media' => 'Profile publication consent changed while the image was being processed. Please review your profile status and try again.']);
                }

                $media = ProfileMedia::query()->create([
                    'companion_profile_id' => $lockedProfile->id,
                    'uploaded_by' => $request->user()->id,
                    'storage_path' => $image['path'],
                    'mime_type' => 'image/webp',
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'file_size' => $image['size'],
                    'status' => 'pending_review',
                    'rights_confirmed_at' => now(),
                    'consent_version' => config('talyvane.privacy.profile_consent_version'),
                    'members_only_consent_at' => $membersOnlyConsentConfirmed ? now() : null,
                    'members_only_consent_version' => $membersOnlyConsentConfirmed ? $membersOnlyConsentVersion : null,
                ]);

                if ($membersOnlyConsentConfirmed) {
                    ProfileMediaConsentEvent::query()->create([
                        'profile_media_id' => $media->id,
                        'companion_profile_id' => $lockedProfile->id,
                        'media_reference' => 'profile-media:'.$media->id,
                        'actor_id' => $request->user()->id,
                        'actor_email' => $request->user()->email,
                        'action' => 'members_only_consent.granted',
                        'capture_method' => 'authenticated_companion_portal_checkbox',
                        'consent_policy_version' => $membersOnlyConsentVersion,
                        'consent_statement' => $consentStatement,
                        'statement_sha256' => hash('sha256', $consentStatement),
                        'correlation_id' => (string) Str::uuid(),
                        'created_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($image['path']);
            throw $exception;
        }

        return redirect()->route('companion-portal.dashboard')->with('status', $membersOnlyConsentConfirmed ? 'Image received privately with a separate members-only access opt-in recorded.' : 'Image received privately. It will not appear publicly unless the agency approves it.');
    }

    public function withdrawMembersOnlyConsent(
        Request $request,
        CompanionProfile $profile,
        ProfileMedia $media,
    ): RedirectResponse {
        abort_unless((int) $profile->user_id === (int) $request->user()->id, 403);
        abort_unless((int) $media->companion_profile_id === (int) $profile->id, 404);

        DB::transaction(function () use ($profile, $media, $request): void {
            CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $lockedMedia = ProfileMedia::query()->lockForUpdate()->findOrFail($media->id);
            abort_unless(
                (int) $lockedMedia->companion_profile_id === (int) $profile->id
                && $lockedMedia->members_only_consent_at
                && ! $lockedMedia->members_only_consent_withdrawn_at,
                404,
            );

            $grantEvent = ProfileMediaConsentEvent::query()
                ->where('profile_media_id', $lockedMedia->id)
                ->where('action', 'members_only_consent.granted')
                ->oldest('id')
                ->firstOrFail();

            $lockedMedia->forceFill([
                'members_only_consent_withdrawn_at' => now(),
                'members_only_consent_withdrawn_by' => $request->user()->id,
            ])->save();

            ProfileMediaConsentEvent::query()->create([
                'profile_media_id' => $lockedMedia->id,
                'companion_profile_id' => $profile->id,
                'media_reference' => 'profile-media:'.$lockedMedia->id,
                'actor_id' => $request->user()->id,
                'actor_email' => $request->user()->email,
                'action' => 'members_only_consent.withdrawn',
                'capture_method' => 'authenticated_companion_portal_withdrawal',
                'consent_policy_version' => $grantEvent->consent_policy_version,
                'consent_statement' => $grantEvent->consent_statement,
                'statement_sha256' => $grantEvent->statement_sha256,
                'correlation_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);
        });

        return redirect()->route('companion-portal.dashboard')->with(
            'status',
            'Members-only gallery consent withdrawn. This image is no longer available to members; its separate public-publication consent is unchanged.',
        );
    }

    public function destroy(Request $request, CompanionProfile $profile, ProfileMedia $media): RedirectResponse
    {
        abort_unless((int) $profile->user_id === (int) $request->user()->id, 403);
        abort_unless((int) $media->companion_profile_id === (int) $profile->id, 404);

        $path = DB::transaction(function () use ($profile, $media, $request): string {
            CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $locked = ProfileMedia::query()->lockForUpdate()->findOrFail($media->id);
            abort_unless((int) $locked->companion_profile_id === (int) $profile->id, 404);
            $locked->forceFill([
                'status' => 'withdrawn',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => 'Image submission removed by profile owner.',
            ])->save();

            return $locked->storage_path;
        });

        Storage::disk('local')->delete($path);

        return redirect()->route('companion-portal.privacy-status')->with('status', 'Image removed from your profile submissions.');
    }
}
