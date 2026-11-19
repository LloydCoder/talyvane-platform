<?php

namespace Tests\Feature;

use App\Models\SuperAdminRecoveryEvent;
use App\Models\SuperAdminRecoveryGuardian;
use App\Models\User;
use App\Notifications\SuperAdminEmailVerificationCode;
use App\Notifications\SuperAdminRecoveryEnrollmentNotice;
use App\Notifications\SuperAdminRecoveryNotice;
use App\Services\RecoveryCodeGenerator;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SuperAdminBreakGlassTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_configures_two_distinct_owner_guardians_with_verified_email_and_mfa(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();
        $this->mock(RecoveryCodeGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->twice()->andReturn('111111', '222222'));

        $superAdmin = $this->provisionSuperAdmin();
        $step = intdiv(time(), 30);
        $superAdmin->forceFill(['mfa_last_used_step' => $step - 1])->save();

        $one = $this->provisionGuardian('guardian-one@example.test', 'Guardian-One-Password-934!', 'JBSWY3DPEHPK3PXP', $step - 1);
        $two = $this->provisionGuardian('guardian-two@example.test', 'Guardian-Two-Password-934!', 'KRUGS4ZANFZSAYJA', $step - 1);
        $oneCode = (new TotpService())->codeForStep($one->mfa_secret, $step);
        $twoCode = (new TotpService())->codeForStep($two->mfa_secret, $step);
        $rootCode = (new TotpService())->codeForStep($superAdmin->mfa_secret, $step);

        $this->artisan('talyvane:super-admin:configure-break-glass')
            ->expectsQuestion('Current Super Admin password', 'Strong-Root-Password-934!')
            ->expectsQuestion('Current authenticator code', $rootCode)
            ->expectsQuestion('Recovery guardian 1 email', $one->email)
            ->expectsQuestion('Guardian 1 password', 'Guardian-One-Password-934!')
            ->expectsQuestion('Guardian 1 authenticator code', $oneCode)
            ->expectsQuestion('Recovery guardian 2 email', $two->email)
            ->expectsQuestion('Guardian 2 password', 'Guardian-Two-Password-934!')
            ->expectsQuestion('Guardian 2 authenticator code', $twoCode)
            ->expectsQuestion('Verification code sent to '.$one->email, '111111')
            ->expectsQuestion('Verification code sent to '.$two->email, '222222')
            ->expectsQuestion('Reason for break-glass configuration (minimum 20 characters)', 'Two independent owners approved emergency recovery setup')
            ->expectsOutput('Two-owner Super Admin break-glass recovery is configured. All prior administrator sessions were invalidated.')
            ->assertExitCode(0);

        $this->assertSame(8, $superAdmin->fresh()->admin_session_version);
        $this->assertSame($step, $one->fresh()->mfa_last_used_step);
        $this->assertSame($step, $two->fresh()->mfa_last_used_step);
        $this->assertSame([$one->id, $two->id], SuperAdminRecoveryGuardian::query()->orderBy('slot')->pluck('user_id')->all());
        $this->assertDatabaseHas('super_admin_recovery_events', [
            'super_admin_user_id' => $superAdmin->id,
            'event_type' => 'break_glass_guardians_configured',
            'session_version_before' => 7,
            'session_version_after' => 8,
        ]);
        Notification::assertSentOnDemand(SuperAdminEmailVerificationCode::class, 2);
        Notification::assertSentOnDemand(SuperAdminRecoveryEnrollmentNotice::class, 4);
    }

    public function test_two_guardians_can_reset_super_admin_when_password_and_offline_key_are_lost(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();
        $this->mock(RecoveryCodeGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->twice()->andReturn('333333', '444444'));

        $superAdmin = $this->provisionSuperAdmin();
        $superAdmin->forceFill(['password' => 'Lost-Root-Password-934!'])->save();
        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_recovery_key_hash' => Hash::make('lost-offline-key-value'),
        ]);

        $step = intdiv(time(), 30);
        $one = $this->provisionGuardian('guardian-one@example.test', 'Guardian-One-Password-934!', 'JBSWY3DPEHPK3PXP', $step - 1);
        $two = $this->provisionGuardian('guardian-two@example.test', 'Guardian-Two-Password-934!', 'KRUGS4ZANFZSAYJA', $step - 1);
        SuperAdminRecoveryGuardian::query()->create(['slot' => 1, 'user_id' => $one->id, 'enrolled_at' => now()]);
        SuperAdminRecoveryGuardian::query()->create(['slot' => 2, 'user_id' => $two->id, 'enrolled_at' => now()]);

        $oneCode = (new TotpService())->codeForStep($one->mfa_secret, $step);
        $twoCode = (new TotpService())->codeForStep($two->mfa_secret, $step);

        $this->artisan('talyvane:super-admin:break-glass')
            ->expectsQuestion('Guardian 1 password', 'Guardian-One-Password-934!')
            ->expectsQuestion('Guardian 1 authenticator code', $oneCode)
            ->expectsQuestion('Guardian 2 password', 'Guardian-Two-Password-934!')
            ->expectsQuestion('Guardian 2 authenticator code', $twoCode)
            ->expectsQuestion('Verification code sent to '.$one->email, '333333')
            ->expectsQuestion('Verification code sent to '.$two->email, '444444')
            ->expectsQuestion('New Super Admin password (minimum 14 characters)', 'New-Root-Password-934!')
            ->expectsQuestion('Confirm new Super Admin password', 'New-Root-Password-934!')
            ->expectsQuestion('Reason for break-glass recovery (minimum 20 characters)', 'Primary credentials and offline key lost; two owners authorized reset')
            ->expectsOutputToContain('Super Admin recovery key (store offline; shown once):')
            ->expectsOutput('Super Admin password reset, MFA cleared and prior sessions invalidated. Sign in and enroll MFA again.')
            ->assertExitCode(0);

        $superAdmin->refresh();
        $control = DB::table('platform_control')->where('id', 1)->first();

        $this->assertTrue(Hash::check('New-Root-Password-934!', $superAdmin->password));
        $this->assertNull($superAdmin->mfa_secret);
        $this->assertNull($superAdmin->mfa_enabled_at);
        $this->assertNull($superAdmin->mfa_recovery_codes);
        $this->assertSame(8, $superAdmin->admin_session_version);
        $this->assertFalse(Hash::check('lost-offline-key-value', $control->super_admin_recovery_key_hash));
        $this->assertSame($step, $one->fresh()->mfa_last_used_step);
        $this->assertSame($step, $two->fresh()->mfa_last_used_step);
        $this->assertDatabaseHas('super_admin_recovery_events', [
            'super_admin_user_id' => $superAdmin->id,
            'event_type' => 'break_glass_completed',
            'session_version_before' => 7,
            'session_version_after' => 8,
        ]);
        Notification::assertSentOnDemand(SuperAdminEmailVerificationCode::class, 2);
        Notification::assertSentOnDemand(SuperAdminRecoveryNotice::class, 4);
    }

    public function test_break_glass_rejects_invalid_guardian_password_without_mutating_super_admin(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();
        $superAdmin = $this->provisionSuperAdmin();
        $step = intdiv(time(), 30);
        $one = $this->provisionGuardian('guardian-one@example.test', 'Guardian-One-Password-934!', 'JBSWY3DPEHPK3PXP', $step - 1);
        $two = $this->provisionGuardian('guardian-two@example.test', 'Guardian-Two-Password-934!', 'KRUGS4ZANFZSAYJA', $step - 1);
        SuperAdminRecoveryGuardian::query()->create(['slot' => 1, 'user_id' => $one->id, 'enrolled_at' => now()]);
        SuperAdminRecoveryGuardian::query()->create(['slot' => 2, 'user_id' => $two->id, 'enrolled_at' => now()]);

        $this->artisan('talyvane:super-admin:break-glass')
            ->expectsQuestion('Guardian 1 password', 'Wrong-Guardian-Password-934!')
            ->expectsQuestion('Guardian 1 authenticator code', '000000')
            ->expectsOutput('The supplied recovery guardian credentials could not be verified.')
            ->assertExitCode(1);

        $this->assertSame(7, $superAdmin->fresh()->admin_session_version);
        $this->assertTrue(Hash::check('Strong-Root-Password-934!', $superAdmin->fresh()->password));
        Notification::assertNothingSent();
        $this->assertDatabaseHas('super_admin_recovery_events', [
            'super_admin_user_id' => $superAdmin->id,
            'event_type' => 'break_glass_rejected',
        ]);
    }

    public function test_break_glass_attempts_are_throttled_after_five_rejections(): void
    {
        $superAdmin = $this->provisionSuperAdmin();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            SuperAdminRecoveryEvent::query()->create([
                'super_admin_user_id' => $superAdmin->id,
                'event_type' => 'break_glass_rejected',
                'reason' => 'Guardian credential verification failed',
                'session_version_before' => 7,
                'session_version_after' => 7,
                'metadata' => ['source' => 'trusted_cli'],
                'occurred_at' => now()->subMinutes(5),
            ]);
        }

        $this->artisan('talyvane:super-admin:break-glass')
            ->expectsOutput('Break-glass recovery is temporarily locked after five rejected attempts. Wait 15 minutes before retrying.')
            ->assertExitCode(1);
    }


    public function test_super_admin_can_clear_break_glass_guardians_with_current_password_and_mfa(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();
        $superAdmin = $this->provisionSuperAdmin();
        $step = intdiv(time(), 30);
        $superAdmin->forceFill(['mfa_last_used_step' => $step - 1])->save();

        $one = $this->provisionGuardian('guardian-one@example.test', 'Guardian-One-Password-934!', 'JBSWY3DPEHPK3PXP', $step - 1);
        $two = $this->provisionGuardian('guardian-two@example.test', 'Guardian-Two-Password-934!', 'KRUGS4ZANFZSAYJA', $step - 1);
        SuperAdminRecoveryGuardian::query()->create(['slot' => 1, 'user_id' => $one->id, 'enrolled_at' => now()]);
        SuperAdminRecoveryGuardian::query()->create(['slot' => 2, 'user_id' => $two->id, 'enrolled_at' => now()]);

        $code = (new TotpService())->codeForStep($superAdmin->mfa_secret, $step);

        $this->artisan('talyvane:super-admin:clear-break-glass')
            ->expectsQuestion('Current Super Admin password', 'Strong-Root-Password-934!')
            ->expectsQuestion('Current authenticator code', $code)
            ->expectsQuestion('Reason for clearing break-glass guardians (minimum 20 characters)', 'Guardian ownership changed after approved security review')
            ->expectsOutput('Break-glass guardian assignments cleared. The recovery path is unavailable until two new Owners are configured.')
            ->assertExitCode(0);

        $this->assertSame(0, SuperAdminRecoveryGuardian::query()->count());
        $this->assertSame(8, $superAdmin->fresh()->admin_session_version);
        $this->assertDatabaseHas('super_admin_recovery_events', [
            'super_admin_user_id' => $superAdmin->id,
            'event_type' => 'break_glass_guardians_cleared',
            'session_version_before' => 7,
            'session_version_after' => 8,
        ]);
        Notification::assertSentOnDemand(\App\Notifications\SuperAdminBreakGlassNotice::class, 4);
    }

    public function test_assigned_guardian_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $this->provisionSuperAdmin();
        $guardian = $this->provisionGuardian('guardian-one@example.test', 'Guardian-One-Password-934!', 'JBSWY3DPEHPK3PXP', intdiv(time(), 30) - 1);
        SuperAdminRecoveryGuardian::query()->create(['slot' => 1, 'user_id' => $guardian->id, 'enrolled_at' => now()]);

        try {
            $guardian->forceFill(['role' => 'admin'])->save();
            $this->fail('Assigned guardian demotion should be blocked.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('break-glass guardian', $exception->getMessage());
        }

        try {
            $guardian->forceFill(['is_active' => false])->save();
            $this->fail('Assigned guardian deactivation should be blocked.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('break-glass guardian', $exception->getMessage());
        }

        try {
            $guardian->delete();
            $this->fail('Assigned guardian deletion should be blocked.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('break-glass guardian', $exception->getMessage());
        }
    }

    private function provisionSuperAdmin(): User
    {
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
            'mfa_last_used_step' => intdiv(time(), 30) - 1,
            'mfa_recovery_codes' => [Hash::make('ABCDE-12345')],
            'admin_session_version' => 7,
            'remember_token' => 'old-remember-token',
        ])->save();

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $superAdmin->id,
            'super_admin_recovery_key_hash' => Hash::make('offline-recovery-key-value'),
            'super_admin_recovery_key_rotated_at' => now(),
            'super_admin_recovery_email' => 'recovery@example.test',
            'super_admin_recovery_email_verified_at' => now(),
            'updated_at' => now(),
        ]);

        return $superAdmin;
    }

    private function provisionGuardian(string $email, string $password, string $secret, int $lastUsedStep): User
    {
        $guardian = User::query()->create([
            'name' => 'Recovery Guardian',
            'email' => $email,
            'password' => $password,
            'role' => 'owner',
            'is_active' => true,
        ]);
        $guardian->forceFill([
            'email_verified_at' => now(),
            'mfa_secret' => $secret,
            'mfa_enabled_at' => now(),
            'mfa_last_used_step' => $lastUsedStep,
            'mfa_recovery_codes' => [],
        ])->save();

        return $guardian;
    }
}
