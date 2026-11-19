<?php

namespace App\Http\Controllers;

use App\Models\CompanionProfile;
use App\Models\ProfilePublicationReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompanionPortalController extends Controller
{
    public function index(): View
    {
        $profiles = CompanionProfile::query()
            ->with('destination')
            ->where('user_id', auth()->id())
            ->get();

        $bookings = \App\Models\Booking::query()
            ->with(['profile', 'destination', 'client'])
            ->whereHas('profile', fn ($query) => $query->where('user_id', auth()->id()))
            ->latest()
            ->paginate(10);

        return view('portal.companion-dashboard', compact('profiles', 'bookings'));
    }

    public function updateAutomaticBooking(Request $request, CompanionProfile $profile): RedirectResponse
    {
        $this->assertOwnership($profile);
        $enabled = $request->boolean('enabled');

        if ($enabled) {
            $request->validate([
                'consent_confirmed' => ['required', 'accepted'],
            ]);

            if ($profile->publication_status !== 'published' || ! $profile->publication_consent_at || $profile->consent_withdrawn_at) {
                throw ValidationException::withMessages([
                    'enabled' => 'The profile must be actively published with current consent before enabling automatic booking.',
                ]);
            }
        }

        DB::transaction(function () use ($profile, $enabled): void {
            $profile->forceFill([
                'accepts_automatic_bookings' => $enabled,
                'automatic_booking_consent_at' => $enabled ? now() : null,
            ])->save();

            ProfilePublicationReview::query()->create([
                'companion_profile_id' => $profile->id,
                'reviewer_id' => auth()->id(),
                'verification_provider' => 'companion-portal',
                'verification_reference_hash' => null,
                'consent_version' => config('talyvane.privacy.profile_consent_version', 'not-set'),
                'automatic_booking_opt_in' => $enabled,
                'decision' => $enabled ? 'automatic_booking_opt_in' : 'automatic_booking_opt_out',
                'review_note' => 'Companion changed automatic booking preference in their authenticated portal.',
                'reviewed_at' => now(),
            ]);
        });

        return redirect()->route('companion-portal.dashboard')->with('status', 'Automatic booking preference updated.');
    }

    public function withdrawPublicationConsent(Request $request, CompanionProfile $profile): RedirectResponse
    {
        $this->assertOwnership($profile);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        DB::transaction(function () use ($profile, $validated): void {
            $profile->forceFill([
                'publication_status' => 'suspended',
                'publication_consent_at' => null,
                'consent_withdrawn_at' => now(),
                'published_at' => null,
                'accepts_automatic_bookings' => false,
                'automatic_booking_consent_at' => null,
            ])->save();

            ProfilePublicationReview::query()->create([
                'companion_profile_id' => $profile->id,
                'reviewer_id' => auth()->id(),
                'verification_provider' => 'companion-portal',
                'verification_reference_hash' => null,
                'consent_version' => config('talyvane.privacy.profile_consent_version', 'not-set'),
                'automatic_booking_opt_in' => false,
                'decision' => 'publication_consent_withdrawn',
                'review_note' => $validated['reason'],
                'reviewed_at' => now(),
            ]);
        });

        return redirect()->route('companion-portal.dashboard')->with('status', 'Publication consent withdrawn. The profile has been hidden immediately.');
    }

    private function assertOwnership(CompanionProfile $profile): void
    {
        abort_unless((int) $profile->user_id === (int) auth()->id(), 403);
    }
}
