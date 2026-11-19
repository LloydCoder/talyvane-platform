<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return $this->createAdminUser([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    private function destination(): Destination
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

        return $destination;
    }

    public function test_owner_cannot_publish_profile_in_a_pending_market(): void
    {
        $owner = $this->owner();
        config(['talyvane.verification.approved_providers' => ['approved-provider']]);
        $destination = Destination::create([
            'name' => 'Lisbon',
            'slug' => 'lisbon-pending',
            'country_code' => 'PT',
            'country_name' => 'Portugal',
            'city_name' => 'Lisbon',
            'currency_code' => 'EUR',
            'locale' => 'pt-PT',
            'timezone' => 'Europe/Lisbon',
            'is_active' => true,
        ]);
        $profile = CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Taylor',
            'slug' => 'taylor-lisbon',
            'publication_status' => 'draft',
        ]);

        $this->actingAs($owner)
            ->post('/admin/profiles/'.$profile->id.'/publish', [
                'verification_provider' => 'approved-provider',
                'verification_reference' => 'verified-reference-12345',
                'consent_confirmed' => '1',
                'consent_version' => config('talyvane.privacy.profile_consent_version'),
            ])
            ->assertSessionHasErrors('destination');

        $this->assertDatabaseHas('companion_profiles', [
            'id' => $profile->id,
            'publication_status' => 'draft',
        ]);
    }

    public function test_owner_can_create_a_draft_profile_without_publication(): void
    {
        $this->actingAs($this->owner())
            ->post('/admin/profiles', [
                'destination_id' => $this->destination()->id,
                'display_name' => 'Alex',
                'slug' => 'alex-london',
                'summary' => 'A profile introduction.',
                'languages' => ['English', 'Spanish'],
            ])
            ->assertRedirect('/admin/profiles');

        $this->assertDatabaseHas('companion_profiles', [
            'slug' => 'alex-london',
            'publication_status' => 'draft',
            'age_verified_at' => null,
            'publication_consent_at' => null,
        ]);

        $this->get('/companions')
            ->assertOk()
            ->assertDontSee('Alex');
    }

    public function test_profile_publication_requires_provider_verification_reference_and_explicit_consent(): void
    {
        config(['talyvane.verification.approved_providers' => ['approved-provider']]);
        $owner = $this->owner();
        $destination = $this->destination();
        $profile = \App\Models\CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'publication_status' => 'draft',
        ]);

        $this->actingAs($owner)
            ->post('/admin/profiles/'.$profile->id.'/publish', [
                'verification_provider' => 'approved-provider',
                'verification_reference' => 'provider-reference-123456',
                'consent_confirmed' => '1',
                'automatic_booking_opt_in' => '1',
                'consent_version' => 'profile-publication-v1',
            ])
            ->assertRedirect('/admin/profiles');

        $this->assertDatabaseHas('companion_profiles', [
            'id' => $profile->id,
            'publication_status' => 'published',
            'accepts_automatic_bookings' => true,
        ]);

        $this->assertDatabaseHas('profile_publication_reviews', [
            'companion_profile_id' => $profile->id,
            'reviewer_id' => $owner->id,
            'verification_provider' => 'approved-provider',
            'consent_version' => 'profile-publication-v1',
            'automatic_booking_opt_in' => true,
            'decision' => 'approved',
        ]);

        $this->assertDatabaseMissing('profile_publication_reviews', [
            'companion_profile_id' => $profile->id,
            'verification_reference_hash' => 'provider-reference-123456',
        ]);
    }

    public function test_profile_cannot_be_published_without_consent_confirmation(): void
    {
        $profile = \App\Models\CompanionProfile::create([
            'destination_id' => $this->destination()->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'publication_status' => 'draft',
        ]);

        $this->actingAs($this->owner())
            ->from('/admin/profiles')
            ->post('/admin/profiles/'.$profile->id.'/publish', [
                'verification_provider' => 'approved-provider',
                'verification_reference' => 'provider-reference-123456',
                'consent_version' => 'profile-publication-v1',
            ])
            ->assertSessionHasErrors('consent_confirmed');

        $this->assertDatabaseHas('companion_profiles', [
            'id' => $profile->id,
            'publication_status' => 'draft',
        ]);
    }
    public function test_admin_unpublishing_profile_cancels_pending_booking_requests(): void
    {
        $owner = $this->owner();
        $destination = $this->destination();
        $profile = \App\Models\CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);
        $client = User::create([
            'name' => 'Client',
            'email' => 'client@example.test',
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        $start = now()->addDays(3);
        $booking = Booking::create([
            'reference' => 'UNPUBLISH123',
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
            'destination_id' => $destination->id,
            'booking_mode' => 'manual',
            'status' => 'pending_review',
            'deposit_status' => 'pending',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'timezone' => 'Europe/London',
            'duration_minutes' => 60,
        ]);

        $this->actingAs($owner)
            ->post('/admin/profiles/'.$profile->id.'/unpublish', [
                'reason' => 'Administrative safeguarding review.',
            ])
            ->assertRedirect('/admin/profiles');

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('booking_events', ['booking_id' => $booking->id, 'event_type' => 'profile_unpublished']);
    }

}
