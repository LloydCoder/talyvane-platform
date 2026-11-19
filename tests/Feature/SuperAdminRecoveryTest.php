<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SuperAdminRecoveryNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SuperAdminRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_recovery_requires_password_and_offline_key_then_rotates_mfa_and_invalidates_sessions(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();

        [$superAdmin, $oldRecoveryKey] = $this->provisionSuperAdmin();

        $this->artisan('talyvane:super-admin:recover')
            ->expectsQuestion('Current Super Admin password', 'Strong-Root-Password-934!')
            ->expectsQuestion('Offline deployment recovery key', $oldRecoveryKey)
            ->expectsQuestion('Reason for recovery (minimum 20 characters)', 'Authenticator device lost; verified by deployment owner')
            ->expectsOutput('Super Admin MFA reset and all prior administrator sessions invalidated.')
            ->expectsOutputToContain('Super Admin recovery key (store offline; shown once):')
            ->assertExitCode(0);

        $superAdmin->refresh();
        $control = DB::table('platform_control')->where('id', 1)->first();

        $this->assertNull($superAdmin->mfa_secret);
        $this->assertNull($superAdmin->mfa_enabled_at);
        $this->assertNull($superAdmin->mfa_recovery_codes);
        $this->assertNull($superAdmin->mfa_last_used_step);
        $this->assertSame(8, $superAdmin->admin_session_version);
        $this->assertNotSame('old-remember-token', $superAdmin->remember_token);
        $this->assertNotSame(Hash::make($oldRecoveryKey), $control->super_admin_recovery_key_hash);
        $this->assertFalse(Hash::check($oldRecoveryKey, $control->super_admin_recovery_key_hash));
        $this->assertNotNull($control->super_admin_recovery_key_rotated_at);

        $this->assertDatabaseHas('super_admin_recovery_events', [
            'super_admin_user_id' => $superAdmin->id,
            'event_type' => 'mfa_recovered',
            'session_version_before' => 7,
            'session_version_after' => 8,
        ]);
        $this->assertDatabaseHas('super_admin_recovery_events', [
            'super_admin_user_id' => $superAdmin->id,
            'event_type' => 'notification_sent',
        ]);
        Notification::assertSentOnDemand(SuperAdminRecoveryNotice::class, 2);
    }

    public function test_super_admin_recovery_rejects_invalid_credentials_without_mutating_security_state(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();

        [$superAdmin, $recoveryKey] = $this->provisionSuperAdmin();

        $this->artisan('talyvane:super-admin:recover')
            ->expectsQuestion('Current Super Admin password', 'Wrong-Root-Password-934!')
            ->expectsQuestion('Offline deployment recovery key', $recoveryKey)
            ->assertExitCode(1);

        $superAdmin->refresh();
        $this->assertNotNull($superAdmin->mfa_enabled_at);
        $this->assertSame(7, $superAdmin->admin_session_version);
        $this->assertSame('old-remember-token', $superAdmin->remember_token);
        $this->assertTrue(Hash::check($recoveryKey, DB::table('platform_control')->where('id', 1)->value('super_admin_recovery_key_hash')));
        Notification::assertNothingSent();
        $this->assertDatabaseHas('super_admin_recovery_events', [
            'super_admin_user_id' => $superAdmin->id,
            'event_type' => 'recovery_rejected',
        ]);
    }

    public function test_super_admin_recovery_fails_closed_without_verified_notification_addresses(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);

        $superAdmin = User::query()->create([
            'name' => 'Deployment Super Admin',
            'email' => 'root@example.test',
            'password' => 'Strong-Root-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $superAdmin->id,
            'super_admin_recovery_key_hash' => Hash::make('offline-recovery-key-0123456789'),
        ]);

        $this->artisan('talyvane:super-admin:recover')
            ->expectsOutput('Super Admin recovery is unavailable until both notification addresses are verified.')
            ->assertExitCode(1);

        $this->assertNull($superAdmin->fresh()->mfa_enabled_at);
    }

    private function provisionSuperAdmin(): array
    {
        $recoveryKey = 'offline-recovery-key-0123456789-ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $superAdmin = User::query()->create([
            'name' => 'Deployment Super Admin',
            'email' => 'root@example.test',
            'password' => 'Strong-Root-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $superAdmin->forceFill([
            'email_verified_at' => now(),
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enabled_at' => now(),
            'mfa_last_used_step' => intdiv(time(), 30),
            'mfa_recovery_codes' => [Hash::make('ABCDE-12345')],
            'admin_session_version' => 7,
            'remember_token' => 'old-remember-token',
        ])->save();

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $superAdmin->id,
            'super_admin_recovery_key_hash' => Hash::make($recoveryKey),
            'super_admin_recovery_key_rotated_at' => now(),
            'super_admin_recovery_email' => 'recovery@example.test',
            'super_admin_recovery_email_verified_at' => now(),
            'updated_at' => now(),
        ]);

        return [$superAdmin, $recoveryKey];
    }
}
