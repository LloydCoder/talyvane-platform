<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\BookingSetting;
use App\Models\CompanionProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function create(string $slug): View
    {
        $profile = CompanionProfile::query()
            ->publiclyDiscoverable()
            ->with('destination')
            ->where('slug', $slug)
            ->firstOrFail();

        return view('portal.bookings.create', [
            'profile' => $profile,
            'settings' => BookingSetting::current(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'integer', Rule::exists('companion_profiles', 'id')],
            'start_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'duration_minutes' => ['required', Rule::in([30, 60, 90, 120, 180, 240])],
            'client_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = BookingSetting::current();
        $profile = CompanionProfile::query()
            ->publiclyDiscoverable()
            ->with('destination')
            ->find($validated['profile_id']);

        if (! $profile) {
            throw ValidationException::withMessages([
                'profile_id' => 'This profile is not currently available for booking.',
            ]);
        }

        $timezone = $profile->destination->timezone;
        $startLocal = CarbonImmutable::createFromFormat('Y-m-d\\TH:i', $validated['start_at'], $timezone);

        if (! $startLocal || $startLocal->format('Y-m-d\\TH:i') !== $validated['start_at']) {
            throw ValidationException::withMessages([
                'start_at' => 'Choose a valid local date and time.',
            ]);
        }

        $nowLocal = CarbonImmutable::now($timezone);
        if ($startLocal->lessThan($nowLocal->addHours($settings->minimum_notice_hours))) {
            throw ValidationException::withMessages([
                'start_at' => 'The selected time does not meet the minimum booking notice.',
            ]);
        }

        if ($startLocal->greaterThan($nowLocal->addDays($settings->maximum_advance_days))) {
            throw ValidationException::withMessages([
                'start_at' => 'The selected time is outside the booking window.',
            ]);
        }

        $startsAt = $startLocal->utc();
        $endsAt = $startsAt->addMinutes((int) $validated['duration_minutes']);

        DB::transaction(function () use ($profile, $settings, $validated, $startsAt, $endsAt, $timezone): void {
            $lockedProfile = CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);

            if ($lockedProfile->publication_status !== 'published'
                || ! $lockedProfile->age_verified_at
                || ! $lockedProfile->publication_consent_at
                || $lockedProfile->consent_withdrawn_at
                || ! $lockedProfile->destination()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'profile_id' => 'This profile is not currently available for booking.',
                ]);
            }

            $hasConflict = Booking::query()
                ->where('companion_profile_id', $lockedProfile->id)
                ->whereIn('status', ['confirmed', 'pending_review'])
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'start_at' => 'That time is no longer available. Please choose another time.',
                ]);
            }

            $depositStatus = $settings->auto_requires_verified_deposit ? 'pending' : 'not_required';
            $localStart = $startsAt->timezone($timezone);
            $localEnd = $endsAt->timezone($timezone);
            $availabilityMatches = $localStart->toDateString() === $localEnd->toDateString()
                && $lockedProfile->availabilityRules()
                    ->where('is_active', true)
                    ->where('weekday', $localStart->dayOfWeek)
                    ->where('starts_at', '<=', $localStart->format('H:i:s'))
                    ->where('ends_at', '>=', $localEnd->format('H:i:s'))
                    ->exists();

            $canAutoConfirm = $settings->booking_mode === 'automatic'
                && $settings->auto_confirm_enabled
                && $availabilityMatches
                && $lockedProfile->accepts_automatic_bookings
                && $lockedProfile->automatic_booking_consent_at !== null
                && ! $settings->auto_requires_verified_deposit;

            $booking = Booking::query()->create([
                'reference' => Str::upper(Str::random(10)),
                'client_id' => auth()->id(),
                'companion_profile_id' => $lockedProfile->id,
                'destination_id' => $lockedProfile->destination_id,
                'booking_mode' => $settings->booking_mode,
                'status' => $canAutoConfirm ? 'confirmed' : 'pending_review',
                'deposit_status' => $depositStatus,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $timezone,
                'duration_minutes' => (int) $validated['duration_minutes'],
                'client_note' => $validated['client_note'] ?? null,
                'confirmed_at' => $canAutoConfirm ? now() : null,
            ]);

            BookingEvent::query()->create([
                'booking_id' => $booking->id,
                'actor_id' => auth()->id(),
                'event_type' => $canAutoConfirm ? 'automatically_confirmed' : 'requested',
                'from_status' => null,
                'to_status' => $booking->status,
                'metadata' => [
                    'booking_mode' => $booking->booking_mode,
                    'deposit_status' => $booking->deposit_status,
                ],
                'created_at' => now(),
            ]);
        });

        return redirect()->route('portal.dashboard')->with('status', 'Your booking request has been received. The team will confirm the details.');
    }
}
