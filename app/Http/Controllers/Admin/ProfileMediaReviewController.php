<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProfileMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileMediaReviewController
{
    public function update(Request $request, ProfileMedia $media): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($media, $request, $validated): void {
            $lockedMedia = ProfileMedia::query()->lockForUpdate()->findOrFail($media->id);
            if ($lockedMedia->status !== 'pending_review') {
                throw ValidationException::withMessages(['media' => 'This media item has already been reviewed.']);
            }

            if ($validated['decision'] === 'approved') {
                $profile = $lockedMedia->profile()->lockForUpdate()->firstOrFail();
                if (
                    $profile->publication_status !== 'published'
                    || ! $profile->age_verified_at
                    || ! $profile->publication_consent_at
                    || $profile->consent_withdrawn_at
                    || ! $profile->destination()->where('is_active', true)->exists()
                ) {
                    throw ValidationException::withMessages(['media' => 'The profile must remain age-verified, active and consented before media can be approved.']);
                }
                if (! Storage::disk('local')->exists($lockedMedia->storage_path)) {
                    throw ValidationException::withMessages(['media' => 'The private image file is missing and cannot be approved.']);
                }
            }

            $lockedMedia->forceFill([
                'status' => $validated['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $validated['review_note'] ?? null,
            ])->save();
        });

        return redirect()->route('admin.profiles.index')->with('status', 'Media review recorded. Approved images remain delivered through a consent-checked route.');
    }
}
