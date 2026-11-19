<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CountryMarket;
use App\Models\BookingSetting;
use App\Models\CompanionProfile;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanionPortalTest extends TestCase
{
    use RefreshDatabase;

    private function companion(string $email): User
    {
        $user = User::create([
            'name' => 'Companion Example',
            'email' => $email,
            'password' => 'Strong-Companion-Password-934!',
            'role' => 'companion',
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function profile(User $user): CompanionProfile
    {
        $destination = Destination::create([
            'name' => 'London',
            'slug' => 'london',
            'country_code' => 'GB',
            'country_name' => 'United Kingdom',
            'city_name' => 'London',
            'currency_code' => 'GBP',
            'locale' => 'en',
            'timezone' => 'Europe/London',
            'is_active' => true,
        ]);
        $this->approveCountryMarket($destination);

        return CompanionProfile::create([
            'user_id' => $user->id,
            'destination_id' => $destination->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);
    }

    public function test_companion_cannot_accept_booking_after_market_approval_is_revoked(): void
    {
        $companion = $this->companion('companion@example.test');
        $profile = $this->profile($companion);
        $client = User::create([
            'name' => 'Client',
            'email' => 'client@example.test',
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        $start = now('Europe/London')->addDays(4)->utc();
        $booking = Booking::create([
            'reference' => 'COMPANIONGATE1',
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
            'destination_id' => $profile->destination_id,
            'booking_mode' => 'manual',
            'status' => 'pending_review',
            'deposit_status' => 'not_required',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'timezone' => 'Europe/London',
            'duration_minutes' => 60,
        ]);
        CountryMarket::query()->where('country_code', 'GB')->update([
            'is_enabled' => false,
            'compliance_status' => 'rejected',
        ]);

        $this->actingAs($companion)
            ->from('/companion-portal')
            ->patch('/companion-portal/bookings/'.$booking->id, ['decision' => 'accept'])
            ->assertRedirect('/companion-portal')
            ->assertSessionHasErrors('decision');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending_review']);
    }

    public function test_companion_can_opt_in_to_automatic_bookings_with_explicit_consent(): void
    {
        $companion = $this->companion('companion@example.test');
        $profile = $this->profile($companion);

        $this->actingAs($companion)
            ->post('/companion-portal/profiles/'.$profile->id.'/automatic-booking', [
                'enabled' => '1',
                'consent_confirmed' => '1',
            ])
            ->assertRedirect('/companion-portal/privacy');

        $this->get('/companion-portal/privacy')->assertOk()->assertSee('Your privacy');

        $this->assertDatabaseHas('companion_profiles', [
            'id' => $profile->id,
            'accepts_automatic_bookings' => true,
        ]);

        $this->assertDatabaseHas('profile_publication_reviews', [
            'companion_profile_id' => $profile->id,
            'reviewer_id' => $companion->id,
            'decision' => 'automatic_booking_opt_in',
        ]);
    }

    public function test_companion_can_withdraw_publication_consent_and_profile_is_hidden_immediately(): void
    {
        $companion = $this->companion('companion@example.test');
        $profile = $this->profile($companion);
        $client = User::create(['name' => 'Client', 'email' => 'client@example.test', 'password' => 'Strong-Client-Password-934!', 'role' => 'client', 'is_active' => true]);
        $start = now()->addDays(3);
        DB::table('platform_features')->where('key', 'portals.companion')->update(['is_enabled' => false]);

        $pendingBooking = Booking::create([
            'reference' => 'WITHDRAW123',
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
            'destination_id' => $profile->destination_id,
            'booking_mode' => 'manual',
            'status' => 'pending_review',
            'deposit_status' => 'pending',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'timezone' => 'Europe/London',
            'duration_minutes' => 60,
        ]);

        $this->actingAs($companion)
            ->post('/companion-portal/profiles/'.$profile->id.'/withdraw-consent', [
                'reason' => 'I no longer consent to public profile publication.',
                'withdraw_confirmed' => '1',
            ])
            ->assertRedirect('/companion-portal');

        $this->assertDatabaseHas('companion_profiles', [
            'id' => $profile->id,
            'publication_status' => 'suspended',
        ]);
        $this->assertDatabaseHas('bookings', ['id' => $pendingBooking->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('booking_events', ['booking_id' => $pendingBooking->id, 'event_type' => 'consent_withdrawn']);

        $this->get('/companions')
            ->assertOk()
            ->assertDontSee('Alex');
    }

    public function test_companion_cannot_change_another_companions_profile(): void
    {
        $owner = $this->companion('owner@example.test');
        $other = $this->companion('other@example.test');
        $profile = $this->profile($other);

        $this->actingAs($owner)
            ->post('/companion-portal/profiles/'.$profile->id.'/withdraw-consent', [
                'reason' => 'Attempt to change another profile.',
            ])
            ->assertForbidden();
    }

    public function test_companion_can_accept_a_pending_booking_after_deposit_is_verified(): void
    {
        $companion = $this->companion('companion@example.test');
        $profile = $this->profile($companion);
        $client = User::create([
            'name' => 'Client Example',
            'email' => 'client@example.test',
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        BookingSetting::create(['booking_mode' => 'manual', 'auto_requires_verified_deposit' => true]);
        $start = now('Europe/London')->addDays(3)->utc();
        $booking = Booking::create([
            'reference' => 'COMPANION123',
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
            'destination_id' => $profile->destination_id,
            'booking_mode' => 'manual',
            'status' => 'pending_review',
            'deposit_status' => 'verified',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'timezone' => 'Europe/London',
            'duration_minutes' => 60,
        ]);

        $this->actingAs($companion)
            ->patch('/companion-portal/bookings/'.$booking->id, ['decision' => 'accept'])
            ->assertRedirect('/companion-portal');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('booking_events', [
            'booking_id' => $booking->id,
            'event_type' => 'companion_accepted',
            'to_status' => 'confirmed',
        ]);
    }
    public function test_companion_can_manage_own_weekly_availability(): void
    {
        $companion = $this->companion('companion@example.test');
        $profile = $this->profile($companion);

        $this->actingAs($companion)
            ->post('/companion-portal/profiles/'.$profile->id.'/availability', [
                'weekday' => 2,
                'starts_at' => '09:00',
                'ends_at' => '17:00',
            ])
            ->assertRedirect('/companion-portal');

        $this->assertDatabaseHas('companion_availability_rules', [
            'companion_profile_id' => $profile->id,
            'weekday' => 2,
            'starts_at' => '09:00',
            'ends_at' => '17:00',
        ]);
    }

}
