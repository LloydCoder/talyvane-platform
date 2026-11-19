<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\CompanionAvailabilityRule;
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
        $this->assertDatabaseHas('booking_events', ['event_type' => 'requested', 'to_status' => 'pending_review']);
    }

    public function test_automatic_booking_confirms_only_with_explicit_profile_opt_in(): void
    {
        $client = $this->client();
        $profile = $this->profile(true);
        $localStart = now()->addDays(4)->setTimezone('Europe/London');
        CompanionAvailabilityRule::create(['companion_profile_id' => $profile->id, 'weekday' => $localStart->dayOfWeek, 'starts_at' => '00:00:00', 'ends_at' => '23:59:59']);
        BookingSetting::create(['booking_mode' => 'automatic', 'auto_confirm_enabled' => true, 'auto_requires_verified_deposit' => false]);

        $this->actingAs($client)
            ->post('/bookings', [
                'profile_id' => $profile->id,
                'start_at' => $localStart->format('Y-m-d\TH:i'),
                'duration_minutes' => 60,
            ])
            ->assertRedirect('/portal');

        $this->assertDatabaseHas('bookings', [
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
            'status' => 'confirmed',
            'booking_mode' => 'automatic',
        ]);
        $this->assertDatabaseHas('booking_events', ['event_type' => 'automatically_confirmed', 'to_status' => 'confirmed']);
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

    public function test_admin_must_verify_deposit_before_confirmation_when_required(): void
    {
        $client = $this->client();
        $profile = $this->profile();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Strong-Admin-Password-934!', 'role' => 'admin', 'is_active' => true]);
        BookingSetting::create(['booking_mode' => 'manual', 'auto_requires_verified_deposit' => true]);
        $start = now('Europe/London')->addDays(4)->utc();
        $booking = Booking::create([
            'reference' => 'PENDING1234', 'client_id' => $client->id,
            'companion_profile_id' => $profile->id, 'destination_id' => $profile->destination_id,
            'booking_mode' => 'manual', 'status' => 'pending_review', 'deposit_status' => 'pending',
            'starts_at' => $start, 'ends_at' => $start->copy()->addHour(),
            'timezone' => 'Europe/London', 'duration_minutes' => 60,
        ]);

        $this->actingAs($admin)->from('/admin/bookings')
            ->patch('/admin/bookings/'.$booking->id, ['status' => 'confirmed'])
            ->assertRedirect('/admin/bookings')->assertSessionHasErrors('status');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending_review']);
    }

    public function test_admin_can_verify_deposit_then_confirm_booking(): void
    {
        $client = $this->client();
        $profile = $this->profile();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'Strong-Admin-Password-934!', 'role' => 'admin', 'is_active' => true]);
        BookingSetting::create(['booking_mode' => 'manual', 'auto_requires_verified_deposit' => true]);
        $start = now('Europe/London')->addDays(5)->utc();
        $booking = Booking::create([
            'reference' => 'PENDING5678', 'client_id' => $client->id,
            'companion_profile_id' => $profile->id, 'destination_id' => $profile->destination_id,
            'booking_mode' => 'manual', 'status' => 'pending_review', 'deposit_status' => 'pending',
            'starts_at' => $start, 'ends_at' => $start->copy()->addHour(),
            'timezone' => 'Europe/London', 'duration_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->post('/admin/bookings/'.$booking->id.'/deposit', [
                'transaction_reference' => 'external-transaction-reference-123',
                'verified' => '1',
            ])->assertRedirect('/admin/bookings');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'deposit_status' => 'verified', 'deposit_verified_by' => $admin->id]);

        $this->patch('/admin/bookings/'.$booking->id, ['status' => 'confirmed'])
            ->assertRedirect('/admin/bookings');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    }

    public function test_automatic_booking_does_not_confirm_without_a_matching_availability_window(): void
    {
        $client = $this->client();
        $profile = $this->profile(true);
        BookingSetting::create(['booking_mode' => 'automatic', 'auto_confirm_enabled' => true, 'auto_requires_verified_deposit' => false]);

        $this->actingAs($client)
            ->post('/bookings', [
                'profile_id' => $profile->id,
                'start_at' => now()->addDays(7)->setTimezone('Europe/London')->format('Y-m-d\\TH:i'),
                'duration_minutes' => 60,
            ])
            ->assertRedirect('/portal');

        $this->assertDatabaseHas('bookings', [
            'companion_profile_id' => $profile->id,
            'status' => 'pending_review',
            'booking_mode' => 'automatic',
        ]);
    }

}
