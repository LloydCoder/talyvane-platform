<?php

namespace Tests\Feature;

use App\Services\FeatureRegistry;
use App\Models\User;
use App\Models\CountryMarket;
use App\Models\PlatformFeatureAuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformFeatureRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function approveDiscoveryMarket(): void
    {
        CountryMarket::query()->create([
            'country_code' => 'GB',
            'name' => 'United Kingdom',
            'is_enabled' => true,
            'compliance_status' => 'approved',
            'compliance_reviewed_at' => now(),
            'compliance_reviewed_by' => 'test-fixture',
            'compliance_evidence_reference' => 'feature-registry-market-evidence',
            'compliance_review_summary' => 'Approved test market.',
            'market_policy' => ['test_fixture' => true],
        ]);
    }

    public function test_currently_available_public_discovery_is_enabled_by_the_registry(): void
    {
        $this->approveDiscoveryMarket();
        $this->assertDatabaseHas('platform_features', [
            'key' => 'public.discovery',
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);

        $this->get('/destinations')->assertOk();
    }

    public function test_disabling_public_discovery_blocks_the_public_route_server_side(): void
    {
        DB::table('platform_features')->where('key', 'public.discovery')->update([
            'is_enabled' => false,
        ]);

        $this->get('/destinations')->assertNotFound();
        $this->get('/')->assertNotFound();
    }

    public function test_available_model_agency_features_cannot_be_enabled_before_dependencies_are_enabled(): void
    {
        $this->approveDiscoveryMarket();
        DB::table('platform_features')->where('key', 'model-agency.casting')->update([
            'is_enabled' => true,
        ]);

        $this->assertFalse(app(FeatureRegistry::class)->isEnabled('model-agency.casting'));
        $this->assertFalse(app(FeatureRegistry::class)->isEnabled('memberships.entitlements'));
        $this->get('/destinations')->assertOk();
        $this->assertDatabaseHas('platform_features', [
            'key' => 'model-agency.casting',
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }

    public function test_model_assignment_feature_cannot_be_effectively_enabled_without_its_model_agency_dependencies(): void
    {
        DB::table('platform_features')->where('key', 'model-agency.assignments')->update([
            'is_enabled' => true,
        ]);

        $this->assertFalse(app(FeatureRegistry::class)->isEnabled('model-agency.assignments'));
        $this->assertDatabaseHas('platform_features', [
            'key' => 'model-agency.assignments',
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }

    public function test_automatic_booking_fails_closed_when_a_dependency_is_disabled(): void
    {
        DB::table('platform_features')->where('key', 'bookings.companionship')->update(['is_enabled' => false]);

        $this->assertFalse(app(FeatureRegistry::class)->isEnabled('bookings.automatic'));
    }

    public function test_feature_audit_events_reject_updates(): void
    {
        $event = PlatformFeatureAuditEvent::query()->create([
            'feature_key' => 'public.discovery',
            'action' => 'test.created',
            'reason' => 'Verify append-only model protection.',
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $event->update(['reason' => 'Tampered reason']);
    }

    public function test_feature_audit_events_reject_deletion(): void
    {
        $event = PlatformFeatureAuditEvent::query()->create([
            'feature_key' => 'public.discovery',
            'action' => 'test.created',
            'reason' => 'Verify append-only model protection.',
            'created_at' => now(),
        ]);

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_disabling_memberships_hides_client_membership_data_and_blocks_assignment(): void
    {
        DB::table('platform_features')->where('key', 'memberships')->update(['is_enabled' => false]);

        $client = User::query()->create([
            'name' => 'Private Client',
            'email' => 'client@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($client)
            ->get('/portal')
            ->assertOk()
            ->assertDontSee('YOUR MEMBERSHIP');

        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)
            ->post('/admin/clients/'.$client->id.'/membership', [
                'tier_name' => 'Platinum',
                'benefits_summary' => 'Private benefits',
                'starts_at' => now()->toDateString(),
            ])
            ->assertNotFound();
    }

    public function test_companion_privacy_controls_are_not_disabled_with_the_companion_portal(): void
    {
        $router = app('router');
        $withdrawal = $router->getRoutes()->getByName('companion-portal.profiles.withdraw-consent');
        $mediaDeletion = $router->getRoutes()->getByName('companion-portal.profiles.media.destroy');
        $portal = $router->getRoutes()->getByName('companion-portal.dashboard');

        $this->assertNotNull($withdrawal);
        $this->assertNotNull($mediaDeletion);
        $this->assertNotNull($portal);
        $this->assertNotContains('feature:portals.companion', $withdrawal->gatherMiddleware());
        $this->assertNotContains('verified', $withdrawal->gatherMiddleware());
        $this->assertNotContains('feature:portals.companion', $mediaDeletion->gatherMiddleware());
        $this->assertContains('feature:portals.companion', $portal->gatherMiddleware());
    }

    public function test_disabling_payment_configuration_hides_payment_admin_configuration_routes(): void
    {
        DB::table('platform_features')->where('key', 'payments.configuration')->update(['is_enabled' => false]);

        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)
            ->get('/admin/payment-settings')
            ->assertNotFound();
    }

    public function test_only_super_admin_can_view_the_feature_registry(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Legacy Owner',
            'email' => 'owner@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($owner)->get('/admin/features')->assertForbidden();

        $superAdmin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);

        $this->actingAs($superAdmin)
            ->get('/admin/features')
            ->assertOk()
            ->assertSee('Feature registry')
            ->assertSee('Model agency applications')
            ->assertSee('Planned');
    }
    public function test_activation_prerequisites_are_enforced_by_the_runtime_feature_gate(): void
    {
        DB::table('platform_features')->where('key', 'public.discovery')->update([
            'activation_requirements' => json_encode(['unrecognized_security_prerequisite']),
        ]);

        $registry = app(FeatureRegistry::class);

        $this->assertFalse($registry->isOperationallyEnabled('public.discovery'));
        $this->assertFalse($registry->catalog()->firstWhere('key', 'public.discovery')->effective_enabled);
        $this->get('/destinations')->assertNotFound();
    }

    public function test_payment_configuration_remains_available_for_provider_review_workflows(): void
    {
        DB::table('platform_features')->where('key', 'payments.configuration')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);

        $this->assertTrue(app(FeatureRegistry::class)->isOperationallyEnabled('payments.configuration'));
    }

    public function test_dependency_prerequisites_are_checked_recursively_at_runtime(): void
    {
        $this->approveDiscoveryMarket();

        DB::table('platform_features')->whereIn('key', [
            'public.discovery',
            'portals.client',
            'bookings.companionship',
            'bookings.automatic',
        ])->update(['implementation_status' => 'available', 'is_enabled' => true]);

        DB::table('platform_features')->where('key', 'public.discovery')->update([
            'activation_requirements' => json_encode(['unrecognized_security_prerequisite']),
        ]);

        $registry = app(FeatureRegistry::class);

        $this->assertFalse($registry->isOperationallyEnabled('bookings.companionship'));
        $this->assertFalse($registry->isOperationallyEnabled('bookings.automatic'));
    }

    public function test_existing_booking_cancellation_and_companion_opt_out_survive_feature_disablement(): void
    {
        $routes = app('router')->getRoutes();
        $cancel = $routes->getByName('bookings.cancel');
        $optOut = $routes->getByName('companion-portal.profiles.automatic-booking');

        $this->assertNotNull($cancel);
        $this->assertNotNull($optOut);
        $this->assertStringNotContainsString('EnsurePlatformFeatureEnabled', implode('|', $cancel->gatherMiddleware()));
        $this->assertStringNotContainsString('EnsurePlatformFeatureEnabled', implode('|', $optOut->gatherMiddleware()));
    }

    public function test_existing_assignment_and_settlement_safety_transitions_survive_feature_disablement(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([
            'admin.model-assignments.complete',
            'admin.model-assignments.decline',
            'admin.model-assignments.cancel',
            'admin.model-assignments.dispute',
            'admin.model-settlements.expenses.review',
            'admin.model-settlements.approve',
            'admin.model-settlements.payment',
            'admin.model-settlements.reverse',
            'admin.model-settlements.dispute',
            'admin.model-portfolio-assets.review',
            'admin.model-portfolio-assets.withdraw-consent',
            'admin.model-representation-agreements.terminate',
            'admin.model-image-licences.revoke',
            'admin.model-casting-submissions.decision',
        ] as $name) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route, 'Expected route '.$name.' to exist.');
            $this->assertStringNotContainsString('EnsurePlatformFeatureEnabled', implode('|', $route->gatherMiddleware()));
        }
    }

}
