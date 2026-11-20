<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\CompanionAvailabilityRule;
use App\Models\CompanionProfile;
use App\Models\ProfilePublicationReview;
use App\Models\ProfileMedia;
use App\Services\MembershipEntitlementCatalog;
use App\Services\BrandSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompanionPortalController extends Controller
{
    public function index(BrandSettings $brandSettings): View
    {
        $profiles = CompanionProfile::query()
            ->with(['destination', 'availabilityRules', 'media'])
            ->where('user_id', auth()->id())
            ->get();

        $bookings = \App\Models\Booking::query()
            ->with(['profile', 'destination', 'client'])
            ->whereHas('profile', fn ($query) => $query->where('user_id', auth()->id()))
            ->latest()
            ->paginate(10);

        $weekdays = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

        return view('portal.companion-dashboard', [
            'profiles' => $profiles,
            'bookings' => $bookings,
            'weekdays' => $weekdays,
            'privateGalleryConsentStatement' => MembershipEntitlementCatalog::privateGalleryConsentStatement((string) ($brandSettings->publicBrand()['brand_name'] ?? 'Talyvane')),
            'privateGalleryConsentAvailable' => filled($brandSettings->publicBrand()['brand_name'] ?? null)
                && filled(config('talyvane.privacy.private_gallery_consent_version'))
                && config('talyvane.privacy.private_gallery_consent_approved'),
        ]);
    }



    public function privacyStatus(): View
    {
        $profile = CompanionProfile::query()->where('user_id', auth()->id())->first();
        $media = $profile
            ? $profile->media()->latest()->get(['id', 'companion_profile_id', 'status', 'created_at'])
            : collect();

        return view('portal.companion-privacy-status', compact('profile', 'media'));
    }

    public function storeAvailability(Request $request, CompanionProfile $profile): RedirectResponse
    {
        $this->assertOwnership($profile);
        $validated = $request->validate([
            'weekday' => ['required', 'integer', 'between:0,6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
        ]);

        DB::transaction(function () use ($profile, $validated): void {
            $lockedProfile = CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertOwnership($lockedProfile);

            CompanionAvailabilityRule::query()->updateOrCreate(
                [
                    'companion_profile_id' => $lockedProfile->id,
                    'weekday' => $validated['weekday'],
                    'starts_at' => $validated['starts_at'],
                ],
                ['ends_at' => $validated['ends_at'], 'is_active' => true],
            );
        });

        return redirect()->route('companion-portal.dashboard')->with('status', 'Availability window saved.');
    }

    public function destroyAvailability(CompanionProfile $profile, CompanionAvailabilityRule $rule): RedirectResponse
    {
        $this->assertOwnership($profile);
        abort_unless((int) $rule->companion_profile_id === (int) $profile->id, 404);

        DB::transaction(function () use ($profile, $rule): void {
            $lockedProfile = CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertOwnership($lockedProfile);
            CompanionAvailabilityRule::query()
                ->where('companion_profile_id', $lockedProfile->id)
                ->whereKey($rule->id)
                ->delete();
        });

        return redirect()->route('companion-portal.dashboard')->with('status', 'Availability window removed.');
    }

    public function updateAutomaticBooking(Request $request, CompanionProfile $profile, \App\Services\FeatureRegistry $features): RedirectResponse
    {
        $this->assertOwnership($profile);
        $enabled = $request->boolean('enabled');

        if ($enabled) {
            $features->assertOperationallyEnabled('bookings.automatic');
            $request->validate([
                'consent_confirmed' => ['required', 'accepted'],
            ]);

            if ($profile->publication_status !== 'published' || ! $profile->publication_consent_at || $profile->consent_withdrawn_at || ! $profile->destination()->operationallyAvailable()->exists()) {
                throw ValidationException::withMessages([
                    'enabled' => 'The profile must be actively published with current consent before enabling automatic booking.',
                ]);
            }
        }

        DB::transaction(function () use ($profile, $enabled): void {
            $lockedProfile = CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertOwnership($lockedProfile);
            if ($enabled && ($lockedProfile->publication_status !== 'published' || ! $lockedProfile->publication_consent_at || $lockedProfile->consent_withdrawn_at || ! $lockedProfile->destination()->first()?->lockAndCheckOperationalAvailability())) {
                throw ValidationException::withMessages(['enabled' => 'Publication consent or market approval is no longer active.']);
            }
            $lockedProfile->forceFill([
                'accepts_automatic_bookings' => $enabled,
                'automatic_booking_consent_at' => $enabled ? now() : null,
            ])->save();

            ProfilePublicationReview::query()->create([
                'companion_profile_id' => $lockedProfile->id,
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
            'withdraw_confirmed' => ['required', 'accepted'],
        ]);

        DB::transaction(function () use ($profile, $validated): void {
            $lockedProfile = CompanionProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertOwnership($lockedProfile);
            $lockedProfile->forceFill([
                'publication_status' => 'suspended',
                'publication_consent_at' => null,
                'consent_withdrawn_at' => now(),
                'published_at' => null,
                'accepts_automatic_bookings' => false,
                'automatic_booking_consent_at' => null,
            ])->save();

            // Re-consenting to the profile later must never silently revive old image consent.
            ProfileMedia::query()
                ->where('companion_profile_id', $lockedProfile->id)
                ->whereIn('status', ['pending_review', 'approved'])
                ->update([
                    'status' => 'withdrawn',
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                    'review_note' => 'Image publication consent withdrawn by profile owner.',
                ]);

            $pendingBookings = Booking::query()->where('companion_profile_id', $lockedProfile->id)
                ->where('status', 'pending_review')->get();
            foreach ($pendingBookings as $pendingBooking) {
                $pendingBooking->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                BookingEvent::query()->create([
                    'booking_id' => $pendingBooking->id,
                    'actor_id' => auth()->id(),
                    'event_type' => 'consent_withdrawn',
                    'from_status' => 'pending_review',
                    'to_status' => 'cancelled',
                    'metadata' => [],
                    'created_at' => now(),
                ]);
            }

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

        return redirect()->route('companion-portal.privacy-status')->with('status', 'Publication consent withdrawn. The profile has been hidden immediately.');
    }

    private function assertOwnership(CompanionProfile $profile): void
    {
        abort_unless((int) $profile->user_id === (int) auth()->id(), 403);
    }
}
