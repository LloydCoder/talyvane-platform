<?php

namespace App\Http\Controllers\Admin;

use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\CompanionProfile;
use App\Models\Destination;
use App\Models\ProfilePublicationReview;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompanionProfileController
{
    public function index(): View
    {
        return view('admin.profiles.index', [
            'profiles' => CompanionProfile::query()->with(['destination', 'user'])->latest()->paginate(20),
            'approvedProviders' => config('talyvane.verification.approved_providers', []),
            'consentVersion' => config('talyvane.privacy.profile_consent_version'),
        ]);
    }

    public function create(): View
    {
        return view('admin.profiles.create', [
            'destinations' => Destination::query()->where('is_active', true)->orderBy('name')->get(),
            'companionAccounts' => User::query()->where('role', 'companion')->where('is_active', true)->whereDoesntHave('companionProfile')->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'destination_id' => ['required', 'integer', Rule::exists('destinations', 'id')->where('is_active', true)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'companion')->where('is_active', true), 'unique:companion_profiles,user_id'],
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'slug' => ['required', 'alpha_dash', 'max:120', 'unique:companion_profiles,slug'],
            'summary' => ['nullable', 'string', 'max:500'],
            'languages' => ['nullable', 'array', 'max:8'],
            'languages_text' => ['nullable', 'string', 'max:320'],
            'languages.*' => ['string', 'max:40'],
        ]);

        $validated['publication_status'] = 'draft';
        $languageValues = $validated['languages'] ?? preg_split('/[,;]+/', $validated['languages_text'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
        $validated['languages'] = array_values(array_unique(array_filter(array_map('trim', $languageValues))));
        unset($validated['languages_text']);

        CompanionProfile::query()->create($validated);

        return redirect()->route('admin.profiles.index')->with('status', 'Draft profile created. Verification and consent review are required before publication.');
    }

    public function publish(Request $request, CompanionProfile $profile): RedirectResponse
    {
        $providers = config('talyvane.verification.approved_providers', []);
        $consentVersion = config('talyvane.privacy.profile_consent_version');

        $validated = $request->validate([
            'verification_provider' => ['required', 'string', Rule::in($providers)],
            'verification_reference' => ['required', 'string', 'min:8', 'max:255'],
            'consent_confirmed' => ['required', 'accepted'],
            'consent_version' => ['required', 'string', Rule::in([$consentVersion])],
            'automatic_booking_opt_in' => ['sometimes', 'accepted'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $profile->destination()->where('is_active', true)->exists()) {
            return back()->withErrors(['destination' => 'The destination must be active before publication.']);
        }

        $now = now();
        $automaticOptIn = isset($validated['automatic_booking_opt_in']);

        DB::transaction(function () use ($profile, $validated, $now, $automaticOptIn): void {
            $lockedProfile = CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);
            if (! $lockedProfile->destination()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['destination' => 'The destination must be active before publication.']);
            }
            $lockedProfile->forceFill([
                'age_verified_at' => $now,
                'publication_consent_at' => $now,
                'consent_withdrawn_at' => null,
                'publication_status' => 'published',
                'accepts_automatic_bookings' => $automaticOptIn,
                'automatic_booking_consent_at' => $automaticOptIn ? $now : null,
                'published_at' => $now,
            ])->save();

            ProfilePublicationReview::query()->create([
                'companion_profile_id' => $lockedProfile->id,
                'reviewer_id' => auth()->id(),
                'verification_provider' => $validated['verification_provider'],
                'verification_reference_hash' => hash_hmac('sha256', $validated['verification_reference'], (string) config('app.key')),
                'consent_version' => $validated['consent_version'],
                'automatic_booking_opt_in' => $automaticOptIn,
                'decision' => 'approved',
                'review_note' => $validated['review_note'] ?? null,
                'reviewed_at' => $now,
            ]);
        });

        return redirect()->route('admin.profiles.index')->with('status', 'Profile publication approved and recorded.');
    }

    public function unpublish(Request $request, CompanionProfile $profile): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        DB::transaction(function () use ($profile, $validated): void {
            $lockedProfile = CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $lockedProfile->forceFill([
                'publication_status' => 'suspended',
                'published_at' => null,
            ])->save();

            $pendingBookings = Booking::query()->where('companion_profile_id', $lockedProfile->id)
                ->where('status', 'pending_review')->get();
            foreach ($pendingBookings as $pendingBooking) {
                $pendingBooking->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                BookingEvent::query()->create([
                    'booking_id' => $pendingBooking->id,
                    'actor_id' => auth()->id(),
                    'event_type' => 'profile_unpublished',
                    'from_status' => 'pending_review',
                    'to_status' => 'cancelled',
                    'metadata' => [],
                    'created_at' => now(),
                ]);
            }

            ProfilePublicationReview::query()->create([
                'companion_profile_id' => $lockedProfile->id,
                'reviewer_id' => auth()->id(),
                'verification_provider' => 'administrative-action',
                'verification_reference_hash' => null,
                'consent_version' => config('talyvane.privacy.profile_consent_version', 'not-applicable'),
                'decision' => 'suspended',
                'review_note' => $validated['reason'],
                'reviewed_at' => now(),
            ]);
        });

        return redirect()->route('admin.profiles.index')->with('status', 'Profile unpublished. Its verification and consent records were retained for audit.');
    }
}
