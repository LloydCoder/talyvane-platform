<?php

namespace Tests\Feature;

use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformFeatureRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_currently_available_public_discovery_is_enabled_by_the_registry(): void
    {
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
    }

    public function test_planned_features_cannot_be_enabled_by_registry_state_alone(): void
    {
        DB::table('platform_features')->where('key', 'model-agency.applications')->update([
            'is_enabled' => true,
        ]);

        $this->assertFalse(app(FeatureRegistry::class)->isEnabled('model-agency.applications'));
        $this->get('/destinations')->assertOk();
        $this->assertDatabaseHas('platform_features', [
            'key' => 'model-agency.applications',
            'implementation_status' => 'planned',
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

        $this->expectException(\\LogicException::class);
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

        $this->expectException(\\LogicException::class);
        $event->delete();
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
}
