<?php

namespace Tests\Feature;

use App\Models\BrandSetting;
use App\Models\ClientMembership;
use App\Models\ClientMembershipEvent;
use App\Models\ClientMembershipEntitlement;
use App\Models\CompanionProfile;
use App\Models\Destination;
use App\Models\ProfileMedia;
use App\Models\ProfileMediaConsentEvent;
use App\Models\User;
use App\Services\FeatureRegistry;
use App\Services\MembershipEntitlementService;
use App\Services\MembershipEntitlementCatalog;
use App\Services\ClientMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    private function privateMedia(CompanionProfile $profile, string $status = 'approved', bool $withConsent = true): ProfileMedia
    {
        Storage::disk('local')->put('profile-media/private-gallery.webp', 'test-image-bytes');
        $uploader = $this->createAdminUser([
            'name' => 'Uploader',
            'email' => 'uploader@example.test',
            'role' => 'owner',
        ]);

        $media = ProfileMedia::query()->create([
            'companion_profile_id' => $profile->id,
            'uploaded_by' => $uploader->id,
            'storage_path' => 'profile-media/private-gallery.webp',
            'mime_type' => 'image/webp',
            'width' => 800,
            'height' => 1000,
            'file_size' => 17,
            'status' => $status,
            'access_level' => 'members_only',
            'members_only_consent_at' => $withConsent && filled(config('talyvane.privacy.private_gallery_consent_version')) ? now() : null,
            'members_only_consent_version' => $withConsent ? config('talyvane.privacy.private_gallery_consent_version') : null,
            'rights_confirmed_at' => now(),
            'consent_version' => 'profile-publication-v1',
            'reviewed_at' => now(),
        ]);

        if ($media->members_only_consent_at && $media->members_only_consent_version) {
            $statement = MembershipEntitlementCatalog::privateGalleryConsentStatement();
            ProfileMediaConsentEvent::query()->create([
                'profile_media_id' => $media->id,
                'companion_profile_id' => $profile->id,
                'media_reference' => 'profile-media:'.$media->id,
                'actor_id' => $uploader->id,
                'actor_email' => $uploader->email,
                'action' => 'members_only_consent.granted',
                'capture_method' => 'authenticated_companion_portal_checkbox',
                'consent_policy_version' => $media->members_only_consent_version,
                'consent_statement' => $statement,
                'statement_sha256' => hash('sha256', $statement),
                'correlation_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);
        }

        return $media;
    }

    private function enableEntitlements(): void
    {
        config([
            'talyvane.privacy.private_gallery_consent_version' => 'private-gallery-v1',
            'talyvane.privacy.private_gallery_consent_approved' => true,
        ]);

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
        $this->enableEntitlements();
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

    public function test_profile_media_consent_events_reject_updates_and_deletions(): void
    {
        Storage::fake('local');
        config([
            'talyvane.privacy.private_gallery_consent_version' => 'private-gallery-v1',
            'talyvane.privacy.private_gallery_consent_approved' => true,
        ]);
        $profile = $this->profile();
        $media = $this->privateMedia($profile);
        $event = ProfileMediaConsentEvent::query()->create([
            'profile_media_id' => $media->id,
            'companion_profile_id' => $profile->id,
            'media_reference' => 'profile-media:'.$media->id,
            'actor_email' => 'audit@example.test',
            'action' => 'members_only_consent.granted',
            'capture_method' => 'test_fixture',
            'consent_policy_version' => 'private-gallery-v1',
            'consent_statement' => MembershipEntitlementCatalog::privateGalleryConsentStatement(),
            'statement_sha256' => hash('sha256', MembershipEntitlementCatalog::privateGalleryConsentStatement()),
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $event->update(['action' => 'tampered']);
    }

    public function test_profile_media_consent_events_reject_deletions(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $media = $this->privateMedia($profile);
        $event = ProfileMediaConsentEvent::query()->create([
            'profile_media_id' => $media->id,
            'companion_profile_id' => $profile->id,
            'media_reference' => 'profile-media:'.$media->id,
            'actor_id' => $media->uploaded_by,
            'actor_email' => 'audit-delete@example.test',
            'action' => 'members_only_consent.granted',
            'consent_policy_version' => 'private-gallery-v1',
            'statement_sha256' => hash('sha256', MembershipEntitlementCatalog::privateGalleryConsentStatement()),
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_membership_service_rejects_non_operator_actor_even_when_called_directly(): void
    {
        $client = $this->client('service-target@example.test');
        $untrustedActor = $this->client('service-actor@example.test');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(ClientMembershipService::class)->assign($client, $untrustedActor, [
            'tier_name' => 'Signature',
            'benefits_summary' => 'A private membership benefit.',
            'status' => 'active',
            'starts_at' => now()->format('Y-m-d'),
        ]);
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

        $event = ClientMembershipEvent::query()
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

    public function test_entitlement_activation_requires_an_approved_private_gallery_consent_policy(): void
    {
        DB::table('platform_features')->where('key', 'memberships.entitlements')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
            'activation_requirements' => json_encode([
                'server_side_entitlement_checks',
                'private_gallery_consent_policy',
            ]),
        ]);
        config([
            'talyvane.privacy.private_gallery_consent_version' => null,
            'talyvane.privacy.private_gallery_consent_approved' => false,
        ]);

        $this->assertFalse(app(FeatureRegistry::class)->isOperationallyEnabled('memberships.entitlements'));

        config([
            'talyvane.privacy.private_gallery_consent_version' => 'private-gallery-v1',
            'talyvane.privacy.private_gallery_consent_approved' => true,
        ]);

        $this->assertTrue(app(FeatureRegistry::class)->isOperationallyEnabled('memberships.entitlements'));
    }

    public function test_admin_cannot_approve_private_media_without_separate_current_companion_consent(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $media = $this->privateMedia($profile, 'pending_review', false);
        $admin = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'reviewer-no-consent@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($admin)
            ->from('/admin/profiles')
            ->post('/admin/profile-media/'.$media->id.'/review', [
                'decision' => 'approved',
                'access_level' => 'members_only',
            ])
            ->assertRedirect('/admin/profiles')
            ->assertSessionHasErrors('access_level');

        $this->assertDatabaseHas('profile_media', [
            'id' => $media->id,
            'status' => 'pending_review',
        ]);
    }

    public function test_membership_grant_cannot_bypass_missing_media_consent_evidence(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $media = $this->privateMedia($profile, 'approved', false);
        $client = $this->client();
        $membership = $this->membership($client);
        $this->grantPrivateGallery($membership);

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertNotFound();
    }

    public function test_disabling_the_entitlement_feature_revokes_private_gallery_access_immediately(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $media = $this->privateMedia($profile);
        $client = $this->client();
        $membership = $this->membership($client);
        $this->grantPrivateGallery($membership);

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertOk();

        DB::table('platform_features')->where('key', 'memberships.entitlements')->update(['is_enabled' => false]);

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertNotFound();
    }

    public function test_private_media_is_hidden_when_the_approved_consent_policy_version_changes(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $media = $this->privateMedia($profile);
        $client = $this->client();
        $membership = $this->membership($client);
        $this->grantPrivateGallery($membership);

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertOk();

        config(['talyvane.privacy.private_gallery_consent_version' => 'private-gallery-v2']);

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertNotFound();
        $this->get('/companions/'.$profile->slug)
            ->assertOk()
            ->assertDontSee(route('profile-media.show', $media), false);
    }

    public function test_brand_identity_change_invalidates_private_gallery_consent_until_reconsent(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $media = $this->privateMedia($profile);
        $client = $this->client();
        $membership = $this->membership($client);
        $this->grantPrivateGallery($membership);

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertOk();

        BrandSetting::query()->create([
            'key' => 'brand_name',
            'value' => 'New Agency Identity',
            'group' => 'branding',
            'is_public' => true,
        ]);

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertNotFound();
    }

    public function test_companion_can_withdraw_members_only_consent_without_changing_publication_consent(): void
    {
        Storage::fake('local');
        $this->enableEntitlements();
        $profile = $this->profile();
        $companion = User::query()->create([
            'name' => 'Profile Owner',
            'email' => 'profile-owner@example.test',
            'password' => 'Strong-Companion-Password-934!',
            'role' => 'companion',
            'is_active' => true,
        ]);
        $profile->forceFill(['user_id' => $companion->id])->save();

        $media = $this->privateMedia($profile);
        $client = $this->client();
        $membership = $this->membership($client);
        $this->grantPrivateGallery($membership);

        $this->actingAs($client)->get(route('profile-media.show', $media))->assertOk();

        $this->actingAs($companion)
            ->patch(route('companion-portal.profiles.media.withdraw-members-only-consent', [$profile, $media]), ['withdraw_confirmed' => '1'])
            ->assertRedirect(route('companion-portal.dashboard'));

        $media->refresh();
        $this->assertNotNull($media->members_only_consent_withdrawn_at);
        $this->assertSame($companion->id, $media->members_only_consent_withdrawn_by);
        $this->assertDatabaseHas('profile_media_consent_events', [
            'profile_media_id' => $media->id,
            'action' => 'members_only_consent.withdrawn',
            'capture_method' => 'authenticated_companion_portal_withdrawal',
            'actor_id' => $companion->id,
            'consent_policy_version' => 'private-gallery-v1',
        ]);
        $this->assertNotNull($profile->fresh()->publication_consent_at);
        $this->actingAs($client)->get(route('profile-media.show', $media))->assertNotFound();
    }

    public function test_membership_entitlements_feature_becomes_operational_only_after_the_runtime_gate_is_present(): void
    {
        config([
            'talyvane.privacy.private_gallery_consent_version' => 'private-gallery-v1',
            'talyvane.privacy.private_gallery_consent_approved' => true,
        ]);
        DB::table('platform_features')->where('key', 'memberships.entitlements')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
            'activation_requirements' => json_encode(['server_side_entitlement_checks', 'private_gallery_consent_policy']),
        ]);

        $this->assertTrue(app(FeatureRegistry::class)->isOperationallyEnabled('memberships.entitlements'));
    }
}
