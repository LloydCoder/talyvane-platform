<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\CompanionProfile;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private function client(): User
    {
        $client = User::create([
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        return $client;
    }

    private function profile(bool $autoAccept = false): CompanionProfile
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

        return CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
            'accepts_automatic_bookings' => $autoAccept,
            'automatic_booking_consent_at' => $autoAccept ? now() : null,
        ]);
    }

    public function test_manual_booking_creates_a_pending_review_request(): void
    {
        $client = $this->client();
        $profile = $this->profile();
        BookingSetting::create(['booking_mode' => 'manual', 'auto_confirm_enabled' => false]);

        $this->actingAs($client)
            ->post('/bookings', [
                'profile_id' => $profile->id,
                'start_at' => now()->addDays(3)->setTimezone('Europe/London')->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
                'client_note' => 'Please confirm the details.',
            ])
            ->assertRedirect('/portal')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('bookings', [
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
            'status' => 'pending_review',
            'booking_mode' => 'manual',
        ]);
    }

    public function test_automatic_booking_confirms_only_with_explicit_profile_opt_in(): void
    {
        $client = $this->client();
        $profile = $this->profile(true);
        BookingSetting::create(['booking_mode' => 'automatic', 'auto_confirm_enabled' => true, 'auto_requires_verified_deposit' => false]);

        $this->actingAs($client)
            ->post('/bookings', [
                'profile_id' => $profile->id,
                'start_at' => now()->addDays(4)->setTimezone('Europe/London')->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ])
            ->assertRedirect('/portal');

        $this->assertDatabaseHas('bookings', [
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
            'status' => 'confirmed',
            'booking_mode' => 'automatic',
        ]);
    }

    public function test_automatic_mode_falls_back_to_manual_review_without_companion_opt_in(): void
    {
        $client = $this->client();
        $profile = $this->profile(false);
        BookingSetting::create(['booking_mode' => 'automatic', 'auto_confirm_enabled' => true, 'auto_requires_verified_deposit' => false]);

        $this->actingAs($client)
            ->post('/bookings', [
                'profile_id' => $profile->id,
                'start_at' => now()->addDays(5)->setTimezone('Europe/London')->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ])
            ->assertRedirect('/portal');

        $this->assertDatabaseHas('bookings', [
            'companion_profile_id' => $profile->id,
            'status' => 'pending_review',
            'booking_mode' => 'automatic',
        ]);
    }

    public function test_automatic_booking_does_not_bypass_verified_deposit_requirement(): void
    {
        $client = $this->client();
        $profile = $this->profile(true);
        BookingSetting::create(['booking_mode' => 'automatic', 'auto_confirm_enabled' => true, 'auto_requires_verified_deposit' => true]);

        $this->actingAs($client)
            ->post('/bookings', [
                'profile_id' => $profile->id,
                'start_at' => now()->addDays(6)->setTimezone('Europe/London')->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ])
            ->assertRedirect('/portal');

        $this->assertDatabaseHas('bookings', [
            'companion_profile_id' => $profile->id,
            'status' => 'pending_review',
            'deposit_status' => 'pending',
        ]);
    }

    public function test_unverified_or_unconsented_profile_cannot_be_booked(): void
    {
        $client = $this->client();
        $profile = $this->profile();
        $profile->forceFill(['publication_consent_at' => null])->save();

        $this->actingAs($client)
            ->post('/bookings', [
                'profile_id' => $profile->id,
                'start_at' => now()->addDays(3)->setTimezone('Europe/London')->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ])
            ->assertSessionHasErrors('profile_id');

        $this->assertDatabaseCount('bookings', 0);
    }
    public function test_overlapping_confirmed_booking_is_rejected(): void
    {
        $client = $this->client();
        $profile = $this->profile();
        BookingSetting::create(['booking_mode' => 'manual', 'auto_confirm_enabled' => false]);
        $startLocal = now('Europe/London')->addDays(3)->setTime(12, 0);
        $startsAt = $startLocal->copy()->utc();

        Booking::create([
            'reference' => 'EXISTING123',
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
            'destination_id' => $profile->destination_id,
            'booking_mode' => 'manual',
            'status' => 'confirmed',
            'deposit_status' => 'verified',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'timezone' => 'Europe/London',
            'duration_minutes' => 60,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($client)
            ->from('/portal')
            ->post('/bookings', [
                'profile_id' => $profile->id,
                'start_at' => $startLocal->format('Y-m-d\\TH:i'),
                'duration_minutes' => 60,
            ])
            ->assertRedirect('/portal')
            ->assertSessionHasErrors('start_at');

        $this->assertDatabaseCount('bookings', 1);
    }

}
