<?php

namespace App\Http\Controllers\Admin;

use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\BookingSetting;
use App\Models\CompanionProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingAdminController
{
    public function index(): View
    {
        return view('admin.bookings.index', [
            'bookings' => Booking::query()
                ->with(['client', 'profile', 'destination', 'depositVerifier', 'events.actor'])
                ->latest()
                ->paginate(20),
            'settings' => BookingSetting::current(),
        ]);
    }

    public function verifyDeposit(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'transaction_reference' => ['required', 'string', 'min:8', 'max:255'],
            'verified' => ['required', 'accepted'],
        ]);

        if ($booking->deposit_status === 'verified') {
            return back()->with('status', 'This deposit is already marked as verified.');
        }

        $referenceHash = hash_hmac('sha256', $validated['transaction_reference'], (string) config('app.key'));
        $booking->forceFill([
            'deposit_status' => 'verified',
            'deposit_verified_at' => now(),
            'deposit_verified_by' => auth()->id(),
            'deposit_reference_hash' => $referenceHash,
        ])->save();

        BookingEvent::query()->create([
            'booking_id' => $booking->id,
            'actor_id' => auth()->id(),
            'event_type' => 'deposit_verified',
            'from_status' => $booking->status,
            'to_status' => $booking->status,
            'metadata' => ['reference_hash' => $referenceHash],
            'created_at' => now(),
        ]);

        return redirect()->route('admin.bookings.index')->with('status', 'Deposit verification recorded. The transaction reference was stored as a keyed hash.');
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'declined', 'cancelled', 'completed'])],
        ]);

        DB::transaction(function () use ($booking, $validated): void {
            $profile = CompanionProfile::query()
                ->publiclyDiscoverable()
                ->lockForUpdate()
                ->find($booking->companion_profile_id);
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $settings = BookingSetting::current();
            $previousStatus = $lockedBooking->status;

            if ($validated['status'] === 'confirmed') {

                if (! $profile || $lockedBooking->starts_at->isPast()) {
                    throw ValidationException::withMessages([
                        'status' => 'This profile or booking time is no longer eligible for confirmation.',
                    ]);
                }

                if ($settings->auto_requires_verified_deposit && $lockedBooking->deposit_status !== 'verified') {
                    throw ValidationException::withMessages([
                        'status' => 'A verified deposit is required before this booking can be confirmed.',
                    ]);
                }

                $conflict = Booking::query()
                    ->where('companion_profile_id', $lockedBooking->companion_profile_id)
                    ->where('id', '!=', $lockedBooking->id)
                    ->whereIn('status', ['confirmed', 'pending_review'])
                    ->where('starts_at', '<', $lockedBooking->ends_at)
                    ->where('ends_at', '>', $lockedBooking->starts_at)
                    ->exists();

                if ($conflict) {
                    throw ValidationException::withMessages([
                        'status' => 'Another confirmed booking overlaps this time.',
                    ]);
                }

                $lockedBooking->confirmed_at ??= now();
                $lockedBooking->cancelled_at = null;
            } elseif ($validated['status'] === 'cancelled') {
                $lockedBooking->cancelled_at = now();
                $lockedBooking->confirmed_at = null;
            } else {
                $lockedBooking->cancelled_at = null;
                if ($validated['status'] === 'declined') {
                    $lockedBooking->confirmed_at = null;
                }
            }

            $lockedBooking->status = $validated['status'];
            $lockedBooking->save();

            BookingEvent::query()->create([
                'booking_id' => $lockedBooking->id,
                'actor_id' => auth()->id(),
                'event_type' => 'status_changed',
                'from_status' => $previousStatus,
                'to_status' => $lockedBooking->status,
                'metadata' => ['actor_role' => auth()->user()->role],
                'created_at' => now(),
            ]);
        });

        return redirect()->route('admin.bookings.index')->with('status', 'Booking status updated.');
    }
}
