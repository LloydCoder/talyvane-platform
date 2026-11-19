<?php

namespace App\Http\Controllers;

use App\Models\CompanionProfile;
use App\Models\ProfileMedia;
use App\Services\ProfileMediaProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CompanionMediaController extends Controller
{
    public function store(Request $request, CompanionProfile $profile, ProfileMediaProcessor $processor): RedirectResponse
    {
        abort_unless((int) $profile->user_id === (int) $request->user()->id, 403);

        if ($profile->publication_status !== 'published' || ! $profile->age_verified_at || ! $profile->publication_consent_at || $profile->consent_withdrawn_at || ! $profile->destination()->operationallyAvailable()->exists()) {
            throw ValidationException::withMessages(['media' => 'An actively published profile with current consent is required before submitting media.']);
        }

        $validated = $request->validate([
            'media' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'rights_confirmed' => ['required', 'accepted'],
            'publication_confirmed' => ['required', 'accepted'],
        ]);

        $image = $processor->storeSanitized($validated['media']);

        try {
            DB::transaction(function () use ($profile, $request, $image): void {
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

                ProfileMedia::query()->create([
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
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($image['path']);
            throw $exception;
        }

        return redirect()->route('companion-portal.dashboard')->with('status', 'Image received privately. It will not appear publicly unless the agency approves it.');
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

        return redirect()->route('companion-portal.dashboard')->with('status', 'Image removed from your profile submissions.');
    }
}
