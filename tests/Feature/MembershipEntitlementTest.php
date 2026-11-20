<?php

namespace Tests\Feature;

use App\Models\ClientMembership;
use App\Models\ClientMembershipEvent;
use App\Models\ClientMembershipEntitlement;
use App\Models\CompanionProfile;
use App\Models\Destination;
use App\Models\ProfileMedia;
use App\Models\User;
use App\Services\MembershipEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MembershipEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $email = 'member@example.test'): User
    {
        $client = User::query()->create([
            'name' => 'Private Client',
            'email' => $email,
            'password' => 'Strong-Membership-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        return $client;
    }

    private function profile(): CompanionProfile
    {
        $destination = Destination::query()->create([
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

        return CompanionProfile::query()->create([
            'destination_id' => $destination->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'summary' => 'A considered profile introduction.',
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);
    }

    private function membership(User $client, string $status = 'active', ?\Illuminate\Support\Carbon $endsAt = null): ClientMembership
    {
        $admin = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'owner-'.uniqid().'@example.test',
            'role' => 'owner',
        ]);

        return ClientMembership::query()->create([
            'user_id' => $client->id,
            'tier_name' => 'Signature',
            'benefits_summary' => 'Private gallery access.',
            'status' => $status,
            'starts_at' => now()->subDay(),
            'ends_at' => $endsAt,
            'assigned_by' => $admin->id,
        ]);
    }

    private function grantPrivateGallery(ClientMembership $membership): void
    {
        ClientMembershipEntitlement::query()->create([
            'membership_id' => $membership->id,
            'entitlement_key' => 'media.private_gallery',
            'granted_by' => $membership->assigned_by,
            'starts_at' => now()->subMinute(),
        ]);
    }

    private function privateMedia(CompanionProfile $profile, string $status = 'approved'): ProfileMedia
    {
        Storage::disk('local')->put('profile-media/private-gallery.webp', 'test-image-bytes');

        return ProfileMedia::query()->create([
            'companion_profile_id' => $profile->id,
            'uploaded_by' => $this->createAdminUser([
                'name' => 'Uploader',
                'email' => 'uploader@example.test',
                'role' => 'owner',
            ])->id,
            'storage_path' => 'profile-media/private-gallery.webp',
            'mime_type' => 'image/webp',
            'width' => 800,
            'height' => 1000,
            'file_size' => 17,
            'status' => $status,
            'access_level' => 'members_only',
            'rights_confirmed_at' => now(),
            'consent_version' => 'profile-publication-v1',
            'reviewed_at' => now(),
        ]);
    }

    private function enableEntitlements(): void
    {
        DB::table('platform_features')->where('key', 'memberships.entitlements')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }

    public function test_only_a_current_active_membership_with_a_current_grant_has_an_entitlement(): void
    {
        $client = $this->client();
        $membership = $this->membership($client);
        $this->grantPrivateGallery($membership);

        $this->assertTrue(app(MembershipEntitlementService::class)->has($client, 'media.private_gallery'));

        $membership->forceFill(['status' => 'superseded'])->save();
        $this->assertFalse(app(MembershipEntitlementService::class)->has($client, 'media.private_gallery'));
    }

    public function test_expired_memberships_and_expired_entitlement_grants_never_authorize_access(): void
    {
        $client = $this->client();
        $membership = $this->membership($client, 'active', now()->subSecond());
        $this->grantPrivateGallery($membership);

        $this->assertFalse(app(MembershipEntitlementService::class)->has($client, 'media.private_gallery'));

        $membership->forceFill(['ends_at' => null])->save();
        ClientMembershipEntitlement::query()->where('membership_id', $membership->id)->update([
            'ends_at' => now()->subSecond(),
        ]);

        $this->assertFalse(app(MembershipEntitlementService::class)->has($client, 'media.private_gallery'));
    }

    public function test_private_gallery_media_is_denied_to_guests_and_clients_without_the_entitlement(): void
    {
        Storage::fake('local');
        $profile = $this->profile();
        $media = $this->privateMedia($profile);
        $client = $this->client();

        $this->get(route('profile-media.show', $media))->assertNotFound();
        $this->actingAs($client)->get(route('profile-media.show', $media))->assertNotFound();
    }

    public function test_private_gallery_media_is_available_only_to_a_verified_entitled_client(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $media = $this->privateMedia($profile);
        $client = $this->client();
        $membership = $this->membership($client);
        $this->grantPrivateGallery($membership);

        $this->actingAs($client)
            ->get(route('profile-media.show', $media))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_public_discovery_never_exposes_private_gallery_media_urls(): void
    {
        Storage::fake('local');
        $profile = $this->profile();
        $media = $this->privateMedia($profile);

        $this->get('/companions')
            ->assertOk()
            ->assertDontSee(route('profile-media.show', $media), false);

        $this->get('/companions/'.$profile->slug)
            ->assertOk()
            ->assertDontSee(route('profile-media.show', $media), false);
    }

    public function test_admin_assignment_records_entitlement_grants_in_the_membership_audit_snapshot(): void
    {
        $this->enableEntitlements();
        $client = $this->client();
        $admin = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'membership-admin@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.clients.membership', $client), [
                'tier_name' => 'Signature',
                'benefits_summary' => 'Private gallery access.',
                'status' => 'active',
                'starts_at' => now()->format('Y-m-d'),
                'entitlements' => ['media.private_gallery'],
            ])
            ->assertRedirect(route('admin.clients.index'));

        $membership = ClientMembership::query()->where('user_id', $client->id)->firstOrFail();
        $this->assertDatabaseHas('client_membership_entitlements', [
            'membership_id' => $membership->id,
            'entitlement_key' => 'media.private_gallery',
            'granted_by' => $admin->id,
        ]);

        $event = \App\Models\ClientMembershipEvent::query()
            ->where('membership_id', $membership->id)
            ->firstOrFail();

        $this->assertSame(['media.private_gallery'], $event->after_state['entitlements']);
    }

    public function test_admin_cannot_approve_members_only_media_when_entitlements_are_disabled(): void
    {
        Storage::fake('local');
        $profile = $this->profile();
        $media = $this->privateMedia($profile, 'pending_review');
        $admin = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'reviewer-disabled@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($admin)
            ->post('/admin/profile-media/'.$media->id.'/review', [
                'decision' => 'approved',
                'access_level' => 'members_only',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('profile_media', [
            'id' => $media->id,
            'status' => 'pending_review',
            'access_level' => 'members_only',
        ]);
    }

    public function test_admin_can_approve_members_only_media_after_super_admin_feature_activation(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $media = $this->privateMedia($profile, 'pending_review');
        $admin = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'reviewer-enabled@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($admin)
            ->post('/admin/profile-media/'.$media->id.'/review', [
                'decision' => 'approved',
                'access_level' => 'members_only',
            ])
            ->assertRedirect('/admin/profiles');

        $this->assertDatabaseHas('profile_media', [
            'id' => $media->id,
            'status' => 'approved',
            'access_level' => 'members_only',
        ]);
    }

    public function test_membership_supersession_revokes_private_gallery_access_immediately(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $media = $this->privateMedia($profile);
        $client = $this->client();
        $membership = $this->membership($client);
        $this->grantPrivateGallery($membership);

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertOk();

        $membership->forceFill(['status' => 'superseded'])->save();

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertNotFound();
    }

    public function test_destination_discovery_never_exposes_private_gallery_media_urls(): void
    {
        Storage::fake('local');
        $profile = $this->profile();
        $media = $this->privateMedia($profile);

        $this->get('/destinations/london')
            ->assertOk()
            ->assertDontSee(route('profile-media.show', $media), false);
    }

    public function test_inactive_or_unverified_clients_never_receive_membership_entitlements(): void
    {
        $inactive = $this->client('inactive-member@example.test');
        $membership = $this->membership($inactive);
        $this->grantPrivateGallery($membership);
        $inactive->forceFill(['is_active' => false])->save();

        $this->assertFalse(app(MembershipEntitlementService::class)->has($inactive, 'media.private_gallery'));

        $unverified = $this->client('unverified-member@example.test');
        $unverified->forceFill(['email_verified_at' => null])->save();
        $unverifiedMembership = $this->membership($unverified);
        $this->grantPrivateGallery($unverifiedMembership);

        $this->assertFalse(app(MembershipEntitlementService::class)->has($unverified, 'media.private_gallery'));
    }

    public function test_membership_entitlements_feature_becomes_operational_only_after_the_runtime_gate_is_present(): void
    {
        DB::table('platform_features')->where('key', 'memberships.entitlements')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
            'activation_requirements' => json_encode(['server_side_entitlement_checks']),
        ]);

        $this->assertTrue(app(\App\Services\FeatureRegistry::class)->isOperationallyEnabled('memberships.entitlements'));
    }
}
