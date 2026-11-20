<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SuperAdminEmailVerificationCode;
use App\Services\RecoveryCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminInitialPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_super_admin_can_reset_lost_password_only_after_dual_email_verification(): void
    {
        $admin = User::query()->create([
            'name' => 'Deployment Super Admin',
            'email' => 'root@example.test',
            'password' => 'Initial-Install-Password-123!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $admin->id,
            'super_admin_recovery_key_hash' => Hash::make('offline-recovery-key'),
            'super_admin_recovery_email' => 'recovery@example.test',
            'super_admin_recovery_email_verified_at' => now(),
            'installation_id' => '00000000-0000-4000-8000-000000000001',
            'installation_lock_hash' => str_repeat('a', 64),
            'installation_completed_at' => now(),
        ]);
        $admin->forceFill(['email_verified_at' => now(), 'must_change_password_at' => now()])->save();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();
        $this->mock(RecoveryCodeGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->twice()->andReturn('111111', '222222'));

        $this->post('/admin/first-login/recover', [
            'email' => 'ROOT@example.test',
            'recovery_email' => 'recovery@example.test',
        ])->assertRedirect('/admin/first-login/recover/verify');

        Notification::assertSentOnDemandTimes(SuperAdminEmailVerificationCode::class, 2);
        $this->get('/admin/first-login/recover/verify')
            ->assertOk()
            ->assertSee('DUAL-EMAIL VERIFICATION')
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $this->post('/admin/first-login/recover/verify', [
            'primary_code' => '111111',
            'recovery_code' => '222222',
            'password' => 'Recovered-Strong-Password-934!',
            'password_confirmation' => 'Recovered-Strong-Password-934!',
        ])->assertRedirect('/admin/mfa/setup');

        $this->assertAuthenticatedAs($admin);
        $fresh = $admin->fresh();
        $this->assertTrue(Hash::check('Recovered-Strong-Password-934!', $fresh->password));
        $this->assertFalse(Hash::check('Initial-Install-Password-123!', $fresh->password));
        $this->assertNull($fresh->must_change_password_at);
        $this->assertDatabaseHas('super_admin_recovery_events', [
            'super_admin_user_id' => $admin->id,
            'event_type' => 'initial_password_recovered',
        ]);
    }

    public function test_dual_email_recovery_is_unavailable_after_mfa_is_enabled(): void
    {
        $admin = User::query()->create([
            'name' => 'Deployment Super Admin',
            'email' => 'mfa-root@example.test',
            'password' => 'Initial-Install-Password-123!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $admin->id,
            'super_admin_recovery_key_hash' => Hash::make('offline-recovery-key'),
            'super_admin_recovery_email' => 'mfa-recovery@example.test',
            'super_admin_recovery_email_verified_at' => now(),
            'installation_id' => '00000000-0000-4000-8000-000000000002',
            'installation_lock_hash' => str_repeat('b', 64),
            'installation_completed_at' => now(),
        ]);
        $admin->forceFill(['email_verified_at' => now(), 'mfa_enabled_at' => now(), 'mfa_secret' => 'secret'])->save();

        Notification::fake();
        $this->post('/admin/first-login/recover', [
            'email' => $admin->email,
            'recovery_email' => 'mfa-recovery@example.test',
        ])->assertRedirect('/admin/first-login/recover/verify');

        Notification::assertNothingSent();
        $this->post('/admin/first-login/recover/verify', [
            'primary_code' => '111111',
            'recovery_code' => '222222',
            'password' => 'Recovered-Strong-Password-934!',
            'password_confirmation' => 'Recovered-Strong-Password-934!',
        ])->assertSessionHasErrors('primary_code');

        $this->assertGuest();
        $this->assertTrue(Hash::check('Initial-Install-Password-123!', $admin->fresh()->password));
    }
}
