<?php

namespace Tests\Feature;

use App\Models\PlatformFeatureAuditEvent;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminFeatureControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_change_feature_state(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Legacy Owner',
            'email' => 'owner@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($owner)
            ->patch('/admin/features/cms.pages', [
                'enabled' => false,
                'reason' => 'Emergency maintenance disable.',
                'password' => 'Strong-Testing-Password-934!',
                'code' => '123456',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_disable_a_feature_with_password_totp_and_reason(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);
        $step = intdiv(time(), 30);
        $code = (new TotpService())->codeForStep($admin->mfa_secret, $step);

        $this->actingAs($admin)
            ->patch('/admin/features/cms.pages', [
                'enabled' => false,
                'reason' => 'Emergency maintenance window approved by the deployment owner.',
                'password' => 'Strong-Testing-Password-934!',
                'code' => $code,
            ])
            ->assertRedirect(route('admin.features.index'));

        $this->assertDatabaseHas('platform_features', [
            'key' => 'cms.pages',
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('platform_feature_audit_events', [
            'feature_key' => 'cms.pages',
            'actor_id' => $admin->id,
            'action' => 'feature.disabled',
            'reason' => 'Emergency maintenance window approved by the deployment owner.',
        ]);
        $this->assertSame($step, $admin->fresh()->mfa_last_used_step);
    }

    public function test_totp_code_cannot_be_replayed_for_a_second_feature_change(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);
        $step = intdiv(time(), 30);
        $code = (new TotpService())->codeForStep($admin->mfa_secret, $step);
        $this->actingAs($admin);

        $this->patch('/admin/features/cms.pages', [
            'enabled' => false,
            'reason' => 'Emergency maintenance window approved by the deployment owner.',
            'password' => 'Strong-Testing-Password-934!',
            'code' => $code,
        ])->assertRedirect(route('admin.features.index'));

        $this->patch('/admin/features/cms.pages', [
            'enabled' => true,
            'reason' => 'Restore content management after maintenance completion.',
            'password' => 'Strong-Testing-Password-934!',
            'code' => $code,
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseHas('platform_features', ['key' => 'cms.pages', 'is_enabled' => false]);
        $this->assertSame(1, PlatformFeatureAuditEvent::query()->where('feature_key', 'cms.pages')->count());
    }

    public function test_planned_feature_cannot_be_enabled_even_by_super_admin(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);
        $step = intdiv(time(), 30);
        $code = (new TotpService())->codeForStep($admin->mfa_secret, $step);

        $this->actingAs($admin)
            ->patch('/admin/features/model-agency.applications', [
                'enabled' => true,
                'reason' => 'Attempt to activate a module before implementation is complete.',
                'password' => 'Strong-Testing-Password-934!',
                'code' => $code,
            ])
            ->assertSessionHasErrors('enabled');

        $this->assertDatabaseHas('platform_features', [
            'key' => 'model-agency.applications',
            'implementation_status' => 'planned',
            'is_enabled' => false,
        ]);
        $this->assertDatabaseMissing('platform_feature_audit_events', [
            'feature_key' => 'model-agency.applications',
        ]);
    }

    public function test_feature_activation_is_blocked_without_an_operationally_approved_market(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);
        $step = intdiv(time(), 30);
        $totp = new TotpService();

        $this->actingAs($admin)->patch('/admin/features/public.discovery', [
            'enabled' => false,
            'reason' => 'Temporarily disable discovery for a controlled policy review.',
            'password' => 'Strong-Testing-Password-934!',
            'code' => $totp->codeForStep($admin->mfa_secret, $step),
        ])->assertRedirect(route('admin.features.index'));

        $this->patch('/admin/features/public.discovery', [
            'enabled' => true,
            'reason' => 'Attempt to restore discovery without market approval evidence.',
            'password' => 'Strong-Testing-Password-934!',
            'code' => $totp->codeForStep($admin->mfa_secret, $step + 1),
        ])->assertSessionHasErrors('enabled');

        $this->assertDatabaseHas('platform_features', [
            'key' => 'public.discovery',
            'is_enabled' => false,
        ]);
    }

    public function test_feature_change_requires_a_meaningful_reason_and_valid_step_up_credentials(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);
        $step = intdiv(time(), 30);
        $code = (new TotpService())->codeForStep($admin->mfa_secret, $step);

        $this->actingAs($admin)
            ->patch('/admin/features/cms.pages', [
                'enabled' => false,
                'reason' => 'short',
                'password' => 'incorrect-password',
                'code' => $code,
            ])
            ->assertSessionHasErrors(['reason', 'password']);

        $this->assertDatabaseHas('platform_features', ['key' => 'cms.pages', 'is_enabled' => true]);
        $this->assertSame(0, PlatformFeatureAuditEvent::query()->count());
    }
}
