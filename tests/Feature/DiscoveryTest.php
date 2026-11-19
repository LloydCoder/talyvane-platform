<?php

namespace Tests\Feature;

use App\Models\BrandSetting;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;


    public function test_stock_photography_uses_only_reviewed_unsplash_cdn_sources(): void
    {
        $urls = array_merge(
            [config('stock_photography.hero.url')],
            array_column(config('stock_photography.gallery'), 'url'),
            config('stock_photography.destination_fallbacks'),
        );

        $this->assertNotEmpty($urls);
        $this->assertCount(8, $urls);
        $this->assertStringNotContainsString('pexels.com', implode(' ', $urls));

        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://images.unsplash.com/', $url);
        }
    }

    public function test_homepage_renders_curated_hospitality_photography(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('editorial-gallery')
            ->assertSee('images.unsplash.com/photo-1776763018821')
            ->assertSee('images.unsplash.com/photo-1776761731066')
            ->assertSee('images.unsplash.com/photo-1768397003905')
            ->assertSee('images.unsplash.com/photo-1768346564233');
    }

    public function test_public_destination_directory_shows_active_destinations(): void
    {
        Destination::create([
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
        $this->approveCountryMarket(Destination::query()->where('slug', 'london')->firstOrFail());

        Destination::create([
            'name' => 'Hidden Destination',
            'slug' => 'hidden-destination',
            'country_code' => 'GB',
            'country_name' => 'United Kingdom',
            'city_name' => 'Hidden',
            'currency_code' => 'GBP',
            'locale' => 'en',
            'timezone' => 'Europe/London',
            'is_active' => false,
        ]);

        $this->get('/destinations')
            ->assertOk()
            ->assertSee('London')
            ->assertSee('destination-card-image')
            ->assertSee('Illustrative hospitality photography')
            ->assertDontSee('Hidden Destination');
    }

    public function test_public_companion_directory_only_shows_verified_consented_published_profiles(): void
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

        $visible = \App\Models\CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'summary' => 'A considered profile introduction.',
            'languages' => ['English', 'Spanish'],
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);

        \App\Models\CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Unverified',
            'slug' => 'unverified-profile',
            'publication_status' => 'published',
            'publication_consent_at' => now(),
        ]);

        \App\Models\CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'No Consent',
            'slug' => 'no-consent-profile',
            'publication_status' => 'published',
            'age_verified_at' => now(),
        ]);

        \App\Models\CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Draft',
            'slug' => 'draft-profile',
            'publication_status' => 'draft',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);

        $this->get('/companions')
            ->assertOk()
            ->assertSee('Alex')
            ->assertDontSee('Unverified')
            ->assertDontSee('No Consent')
            ->assertDontSee('Draft');

        $this->assertDatabaseHas('companion_profiles', [
            'id' => $visible->id,
            'publication_status' => 'published',
        ]);
    }

    public function test_companion_directory_can_filter_by_active_destination(): void
    {
        $london = Destination::create([
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
        $this->approveCountryMarket($london);

        $manila = Destination::create([
            'name' => 'Manila',
            'slug' => 'manila',
            'country_code' => 'PH',
            'country_name' => 'Philippines',
            'city_name' => 'Manila',
            'currency_code' => 'PHP',
            'locale' => 'en',
            'timezone' => 'Asia/Manila',
            'is_active' => true,
        ]);
        $this->approveCountryMarket($manila);

        foreach ([[$london, 'London Profile'], [$manila, 'Manila Profile']] as [$destination, $name]) {
            \App\Models\CompanionProfile::create([
                'destination_id' => $destination->id,
                'display_name' => $name,
                'slug' => str($name)->slug(),
                'publication_status' => 'published',
                'age_verified_at' => now(),
                'publication_consent_at' => now(),
            ]);
        }

        $this->get('/companions?destination=london')
            ->assertOk()
            ->assertSee('London Profile')
            ->assertDontSee('Manila Profile');
    }

    public function test_companion_directory_rejects_array_filter_input(): void
    {
        $this->from('/companions')
            ->get('/companions?destination[]=london')
            ->assertRedirect('/companions')
            ->assertSessionHasErrors('destination');
    }

    public function test_public_discovery_hides_destinations_and_profiles_until_market_approval(): void
    {
        $destination = Destination::create([
            'name' => 'Lisbon',
            'slug' => 'lisbon',
            'country_code' => 'PT',
            'country_name' => 'Portugal',
            'city_name' => 'Lisbon',
            'currency_code' => 'EUR',
            'locale' => 'pt-PT',
            'timezone' => 'Europe/Lisbon',
            'is_active' => true,
        ]);
        \\App\\Models\\CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Taylor',
            'slug' => 'taylor-lisbon',
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);

        $this->get('/destinations')->assertDontSee('Lisbon');
        $this->get('/companions')->assertDontSee('Taylor');

        $this->approveCountryMarket($destination);

        $this->get('/destinations')->assertSee('Lisbon');
        $this->get('/companions')->assertSee('Taylor');
    }

    public function test_companion_directory_uses_only_approved_real_profile_images_and_never_stock_portraits(): void
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
        $companion = User::create([
            'name' => 'Companion Account',
            'email' => 'companion@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'companion',
            'is_active' => true,
        ]);

        $withImage = \App\Models\CompanionProfile::create([
            'user_id' => $companion->id,
            'destination_id' => $destination->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'summary' => 'A considered profile introduction.',
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);
        $withoutImage = \App\Models\CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Morgan',
            'slug' => 'morgan-london',
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);

        $approved = \App\Models\ProfileMedia::create([
            'companion_profile_id' => $withImage->id,
            'uploaded_by' => $companion->id,
            'storage_path' => 'profile-media/approved.webp',
            'mime_type' => 'image/webp',
            'width' => 800,
            'height' => 1000,
            'file_size' => 12000,
            'status' => 'approved',
            'rights_confirmed_at' => now(),
            'consent_version' => 'profile-publication-v1',
        ]);
        $pending = \App\Models\ProfileMedia::create([
            'companion_profile_id' => $withImage->id,
            'uploaded_by' => $companion->id,
            'storage_path' => 'profile-media/pending.webp',
            'mime_type' => 'image/webp',
            'width' => 800,
            'height' => 1000,
            'file_size' => 12000,
            'status' => 'pending_review',
            'rights_confirmed_at' => now(),
            'consent_version' => 'profile-publication-v1',
        ]);

        $this->get('/companions')
            ->assertOk()
            ->assertSee(route('profile-media.show', $approved), false)
            ->assertDontSee(route('profile-media.show', $pending), false)
            ->assertSee('profile-monogram')
            ->assertSee('Morgan')
            ->assertSee('Companions');
    }

    public function test_owner_can_create_a_destination(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post('/admin/destinations', [
                'name' => 'Mexico City',
                'slug' => 'mexico-city',
                'country_code' => 'MX',
                'country_name' => 'Mexico',
                'city_name' => 'Mexico City',
                'currency_code' => 'MXN',
                'locale' => 'es',
                'timezone' => 'America/Mexico_City',
                'is_active' => '1',
            ])
            ->assertRedirect('/admin/destinations');

        $this->assertDatabaseHas('destinations', [
            'slug' => 'mexico-city',
            'country_code' => 'MX',
            'is_active' => true,
        ]);
    }


    public function test_admin_can_upload_a_destination_cover_image(): void
    {
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post('/admin/destinations', [
                'name' => 'Lisbon',
                'slug' => 'lisbon',
                'country_code' => 'PT',
                'country_name' => 'Portugal',
                'city_name' => 'Lisbon',
                'currency_code' => 'EUR',
                'locale' => 'pt-PT',
                'timezone' => 'Europe/Lisbon',
                'is_active' => '1',
                'cover_image' => UploadedFile::fake()->image('lisbon.jpg', 1600, 1000),
                'image_rights_confirmed' => '1',
            ])
            ->assertRedirect('/admin/destinations');

        $destination = Destination::query()->where('slug', 'lisbon')->firstOrFail();
        $this->approveCountryMarket($destination);
        $this->assertNotNull($destination->image_path);
        Storage::disk('public')->assertExists($destination->image_path);

        $this->get('/destinations')
            ->assertOk()
            ->assertSee('storage/'.$destination->image_path);
    }


    public function test_admin_must_confirm_image_rights_when_uploading_a_cover(): void
    {
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->from('/admin/destinations/create')
            ->post('/admin/destinations', [
                'name' => 'Lisbon',
                'slug' => 'lisbon',
                'country_code' => 'PT',
                'country_name' => 'Portugal',
                'city_name' => 'Lisbon',
                'currency_code' => 'EUR',
                'locale' => 'pt-PT',
                'timezone' => 'Europe/Lisbon',
                'cover_image' => UploadedFile::fake()->image('lisbon.jpg', 1600, 1000),
            ])
            ->assertRedirect('/admin/destinations/create')
            ->assertSessionHasErrors('image_rights_confirmed');

        $this->assertDatabaseMissing('destinations', ['slug' => 'lisbon']);
        $this->assertSame([], Storage::disk('public')->allFiles('destinations'));
    }

    public function test_admin_destination_upload_rejects_svg_files(): void
    {
        Storage::fake('public');
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->from('/admin/destinations/create')
            ->post('/admin/destinations', [
                'name' => 'Lisbon',
                'slug' => 'lisbon',
                'country_code' => 'PT',
                'country_name' => 'Portugal',
                'city_name' => 'Lisbon',
                'currency_code' => 'EUR',
                'locale' => 'pt-PT',
                'timezone' => 'Europe/Lisbon',
                'cover_image' => UploadedFile::fake()->create('cover.svg', 20, 'image/svg+xml'),
            ])
            ->assertRedirect('/admin/destinations/create')
            ->assertSessionHasErrors('cover_image');

        $this->assertDatabaseMissing('destinations', ['slug' => 'lisbon']);
        $this->assertSame([], Storage::disk('public')->allFiles('destinations'));
    }

    public function test_operator_cannot_manage_destinations(): void
    {
        $operator = User::create([
            'name' => 'Operations User',
            'email' => 'operator@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->get('/admin/destinations')
            ->assertForbidden();
    }
}
