<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\CompanionProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanionBookingController extends Controller
{
    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'decline'])],
        ]);

        abort_unless(
            $booking->profile()->where('user_id', auth()->id())->exists(),
            403,
        );

        DB::transaction(function () use ($booking, $validated): void {
            $profile = CompanionProfile::query()
                ->publiclyDiscoverable()
                ->lockForUpdate()
                ->find($booking->companion_profile_id);
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! $profile || $lockedBooking->status !== 'pending_review') {
                throw ValidationException::withMessages([
                    'decision' => 'This booking is no longer available for a decision.',
                ]);
            }

            if ($validated['decision'] === 'decline') {
                $lockedBooking->forceFill(['status' => 'declined'])->save();

                return;
            }

            $settings = BookingSetting::current();
            if ($settings->auto_requires_verified_deposit && $lockedBooking->deposit_status !== 'verified') {
                throw ValidationException::withMessages([
                    'decision' => 'A verified deposit is required before accepting this booking.',
                ]);
            }

            $conflict = Booking::query()
                ->where('companion_profile_id', $profile->id)
                ->where('id', '!=', $lockedBooking->id)
                ->whereIn('status', ['confirmed', 'pending_review'])
                ->where('starts_at', '<', $lockedBooking->ends_at)
                ->where('ends_at', '>', $lockedBooking->starts_at)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'decision' => 'Another booking request overlaps this time. Ask the team to resolve the conflict.',
                ]);
            }

            $lockedBooking->forceFill([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ])->save();
        });

        return redirect()->route('companion-portal.dashboard')->with('status', 'Booking decision saved.');
    }
}
