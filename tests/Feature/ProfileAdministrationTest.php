<?php

namespace Tests\Feature;

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
        return User::create([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    private function destination(): Destination
    {
        return Destination::create([
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
}
