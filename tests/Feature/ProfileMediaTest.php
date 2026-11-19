<?php

namespace Tests\Feature;

use App\Models\CompanionProfile;
use App\Models\Destination;
use App\Models\ProfileMedia;
use App\Models\User;
use App\Services\ProfileMediaProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileMediaTest extends TestCase
{
    use RefreshDatabase;

    private function companionWithProfile(): array
    {
        $user = User::create([
            'name' => 'Companion Example',
            'email' => 'companion@example.test',
            'password' => 'Strong-Companion-Password-934!',
            'role' => 'companion',
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

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

        $profile = CompanionProfile::create([
            'user_id' => $user->id,
            'destination_id' => $destination->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);

        return [$user, $profile];
    }

    public function test_companion_upload_is_private_pending_review_and_requires_both_attestations(): void
    {
        Storage::fake('local');
        [$companion, $profile] = $this->companionWithProfile();
        $image = UploadedFile::fake()->image('portrait.jpg', 900, 1200);

        $this->actingAs($companion)
            ->post('/companion-portal/profiles/'.$profile->id.'/media', [
                'media' => $image,
                'rights_confirmed' => '1',
                'publication_confirmed' => '1',
            ])
            ->assertRedirect('/companion-portal');

        $media = ProfileMedia::query()->firstOrFail();
        $this->assertSame('pending_review', $media->status);
        $this->assertSame('image/webp', $media->mime_type);
        $this->assertSame($companion->id, $media->uploaded_by);
        Storage::disk('local')->assertExists($media->storage_path);

        $this->get(route('profile-media.show', $media))->assertNotFound();

        $this->actingAs($companion)
            ->from('/companion-portal')
            ->post('/companion-portal/profiles/'.$profile->id.'/media', [
                'media' => UploadedFile::fake()->image('missing-consent.jpg'),
            ])
            ->assertSessionHasErrors(['rights_confirmed', 'publication_confirmed']);
    }

    public function test_only_profile_owner_can_upload_media(): void
    {
        Storage::fake('local');
        [$owner, $profile] = $this->companionWithProfile();
        $other = User::create([
            'name' => 'Other Companion',
            'email' => 'other@example.test',
            'password' => 'Strong-Companion-Password-934!',
            'role' => 'companion',
            'is_active' => true,
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($other)
            ->post('/companion-portal/profiles/'.$profile->id.'/media', [
                'media' => UploadedFile::fake()->image('portrait.jpg'),
                'rights_confirmed' => '1',
                'publication_confirmed' => '1',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('profile_media', 0);
    }

    public function test_approved_media_is_served_only_while_profile_consent_remains_active(): void
    {
        Storage::fake('local');
        [$companion, $profile] = $this->companionWithProfile();
        $file = UploadedFile::fake()->image('portrait.jpg', 800, 1000);
        $processed = app(ProfileMediaProcessor::class)->storeSanitized($file);

        $media = ProfileMedia::create([
            'companion_profile_id' => $profile->id,
            'uploaded_by' => $companion->id,
            'storage_path' => $processed['path'],
            'mime_type' => 'image/webp',
            'width' => $processed['width'],
            'height' => $processed['height'],
            'file_size' => $processed['size'],
            'status' => 'pending_review',
            'rights_confirmed_at' => now(),
            'consent_version' => 'profile-publication-v1',
        ]);

        $admin = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'owner@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($admin)
            ->post('/admin/profile-media/'.$media->id.'/review', ['decision' => 'approved'])
            ->assertRedirect('/admin/profiles');

        $this->get(route('profile-media.show', $media->fresh()))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $this->actingAs($companion)
            ->post('/companion-portal/profiles/'.$profile->id.'/withdraw-consent', [
                'reason' => 'I withdraw consent for this profile and its images.',
                'withdraw_confirmed' => '1',
            ])
            ->assertRedirect('/companion-portal');

        $this->assertDatabaseHas('profile_media', ['id' => $media->id, 'status' => 'withdrawn']);
        $this->get(route('profile-media.show', $media->fresh()))->assertNotFound();

        // A later profile-level re-consent must not revive this image's withdrawn consent.
        $profile->forceFill([
            'publication_status' => 'published',
            'publication_consent_at' => now(),
            'consent_withdrawn_at' => null,
            'age_verified_at' => now(),
        ])->save();

        $this->get(route('profile-media.show', $media->fresh()))->assertNotFound();
    }
}
