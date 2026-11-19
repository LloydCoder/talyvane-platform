<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminMfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_without_mfa_is_forced_to_enrol_before_dashboard_access(): void
    {
        $admin = User::create([
            'name' => 'New Administrator',
            'email' => 'new-admin@example.test',
            'password' => 'Strong-Admin-Password-934!',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => 'new-admin@example.test',
            'password' => 'Strong-Admin-Password-934!',
        ])->assertRedirect('/admin/mfa/setup');

        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertRedirect('/admin/mfa/setup');
        $this->get('/admin/mfa/setup')->assertOk();

        $secret = $this->app['session.store']->get('mfa_setup_secret');
        $step = intdiv(time(), 30);
        $code = (new TotpService())->codeForStep($secret, $step);

        $this->post('/admin/mfa/setup', ['code' => $code])
            ->assertRedirect('/admin/mfa/recovery-codes');

        $this->assertNotNull($admin->fresh()->mfa_enabled_at);
        $this->assertNotEmpty($admin->fresh()->mfa_recovery_codes);
        $this->get('/admin/mfa/recovery-codes')->assertOk()->assertSee('Recovery');
        $this->get('/admin')->assertOk();
    }

    public function test_recovery_code_is_single_use(): void
    {
        $admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@example.test',
            'password' => 'Strong-Admin-Password-934!',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->forceFill([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enabled_at' => now(),
            'mfa_last_used_step' => intdiv(time(), 30),
            'mfa_recovery_codes' => [Hash::make('ABCDE-12345')],
        ])->save();

        $this->post('/admin/login', [
            'email' => 'admin@example.test',
            'password' => 'Strong-Admin-Password-934!',
        ])->assertRedirect('/admin/mfa/challenge');

        $this->post('/admin/mfa/recovery', ['recovery_code' => 'ABCDE-12345'])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame([], $admin->fresh()->mfa_recovery_codes);
    }
}
