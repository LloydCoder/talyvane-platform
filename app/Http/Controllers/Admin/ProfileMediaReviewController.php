<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProfileMedia;
use App\Models\CompanionProfile;
use App\Services\FeatureRegistry;
use App\Services\MembershipEntitlementCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileMediaReviewController
{
    public function update(Request $request, ProfileMedia $media): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:1000'],
            'access_level' => ['sometimes', 'required', 'in:public,members_only'],
        ]);

        $accessLevel = $validated['access_level'] ?? 'public';
        if ($validated['decision'] === 'approved' && $accessLevel === 'members_only') {
            app(FeatureRegistry::class)->assertOperationallyEnabled('memberships.entitlements');
        }

        DB::transaction(function () use ($media, $request, $validated, $accessLevel): void {
            $profile = CompanionProfile::query()->lockForUpdate()->findOrFail($media->companion_profile_id);
            $lockedMedia = ProfileMedia::query()->lockForUpdate()->findOrFail($media->id);
            if ($lockedMedia->status !== 'pending_review') {
                throw ValidationException::withMessages(['media' => 'This media item has already been reviewed.']);
            }

            if ($validated['decision'] === 'approved') {
                if ($accessLevel === 'members_only') {
                    $requiredConsentVersion = config('talyvane.privacy.private_gallery_consent_version');
                    $expectedConsentStatement = MembershipEntitlementCatalog::privateGalleryConsentStatement();
                    $grantEvent = $lockedMedia->consentEvents()->where('action', 'members_only_consent.granted')->oldest('id')->first();
                    if (
                        ! config('talyvane.privacy.private_gallery_consent_approved')
                        || ! filled($requiredConsentVersion)
                        || ! $lockedMedia->members_only_consent_at
                        || $lockedMedia->members_only_consent_withdrawn_at
                        || ! hash_equals((string) $requiredConsentVersion, (string) $lockedMedia->members_only_consent_version)
                        || ! $grantEvent
                        || ! hash_equals((string) $expectedConsentStatement, (string) $grantEvent->consent_statement)
                        || ! hash_equals(hash('sha256', $expectedConsentStatement), (string) $grantEvent->statement_sha256)
                        || ! hash_equals((string) $requiredConsentVersion, (string) $grantEvent->consent_policy_version)
                    ) {
                        throw ValidationException::withMessages([
                            'access_level' => 'The companion has not provided current, separate consent for members-only distribution.',
                        ]);
                    }
                }

                if (
                    $profile->publication_status !== 'published'
                    || ! $profile->age_verified_at
                    || ! $profile->publication_consent_at
                    || $profile->consent_withdrawn_at
                    || ! $profile->destination()->first()?->lockAndCheckOperationalAvailability()
                ) {
                    throw ValidationException::withMessages(['media' => 'The profile must remain age-verified, active and consented before media can be approved.']);
                }
                if (! Storage::disk('local')->exists($lockedMedia->storage_path)) {
                    throw ValidationException::withMessages(['media' => 'The private image file is missing and cannot be approved.']);
                }
            }

            $lockedMedia->forceFill([
                'status' => $validated['decision'],
                'access_level' => $validated['decision'] === 'approved' ? $accessLevel : $lockedMedia->access_level,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $validated['review_note'] ?? null,
            ])->save();
        });

        return redirect()->route('admin.profiles.index')->with('status', 'Media review recorded. Approved images remain delivered through a consent-checked route.');
    }
}
