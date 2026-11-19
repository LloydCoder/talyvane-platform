<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecoveryCodeGenerator;
use App\Notifications\SuperAdminEmailVerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SuperAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_bootstrap_creates_the_single_deployment_super_admin(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();
        $this->mock(RecoveryCodeGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->twice()->andReturn('111111', '222222'));

        $this->artisan('talyvane:super-admin:create', ['email' => 'ROOT@example.test'])
            ->expectsQuestion('Super Admin name', 'Deployment Owner')
            ->expectsQuestion('Password (minimum 14 characters)', 'Strong-Root-Password-934!')
            ->expectsQuestion('Confirm password', 'Strong-Root-Password-934!')
            ->expectsQuestion('Recovery notification email (must differ from admin email)', 'recovery@example.test')
            ->expectsQuestion('Verification code sent to root@example.test', '111111')
            ->expectsQuestion('Verification code sent to recovery@example.test', '222222')
            ->expectsOutputToContain('Super Admin recovery key (store offline; shown once):')
            ->expectsOutput('Deployment Super Admin created. Sign in and complete mandatory MFA setup before administration.')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'root@example.test')->firstOrFail();

        $this->assertSame('super_admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->mfa_enabled_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotEmpty(DB::table('platform_control')->value('super_admin_recovery_key_hash'));
        $this->assertSame('recovery@example.test', DB::table('platform_control')->value('super_admin_recovery_email'));
        $this->assertNotNull(DB::table('platform_control')->value('super_admin_recovery_email_verified_at'));
        $this->assertDatabaseHas('super_admin_recovery_events', ['super_admin_user_id' => $user->id, 'event_type' => 'bootstrap_recovery_key_issued']);
        Notification::assertSentOnDemand(SuperAdminEmailVerificationCode::class, null, 2);
        $this->assertDatabaseHas('platform_control', [
            'id' => 1,
            'super_admin_user_id' => $user->id,
        ]);
    }

    public function test_cli_bootstrap_refuses_a_second_super_admin_without_creating_an_account(): void
    {
        $first = User::query()->create([
            'name' => 'Existing Super Admin',
            'email' => 'existing-root@example.test',
            'password' => 'Strong-Root-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $first->id,
        ]);

        $this->artisan('talyvane:super-admin:create', ['email' => 'second-root@example.test'])
            ->expectsOutput('A Super Admin is already provisioned for this deployment. Use the documented recovery process.')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'second-root@example.test']);
        $this->assertDatabaseHas('platform_control', [
            'id' => 1,
            'super_admin_user_id' => $first->id,
        ]);
    }

    public function test_cli_bootstrap_does_not_promote_or_replace_an_existing_owner(): void
    {
        $owner = User::query()->create([
            'name' => 'Legacy Owner',
            'email' => 'legacy-owner@example.test',
            'password' => 'Strong-Root-Password-934!',
            'role' => 'owner',
            'is_active' => true,
        ]);

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);
        Notification::fake();
        $this->mock(RecoveryCodeGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->twice()->andReturn('111111', '222222'));

        $this->artisan('talyvane:super-admin:create', ['email' => 'root@example.test'])
            ->expectsQuestion('Super Admin name', 'New Deployment Owner')
            ->expectsQuestion('Password (minimum 14 characters)', 'Strong-Root-Password-934!')
            ->expectsQuestion('Confirm password', 'Strong-Root-Password-934!')
            ->expectsQuestion('Recovery notification email (must differ from admin email)', 'recovery@example.test')
            ->expectsQuestion('Verification code sent to root@example.test', '111111')
            ->expectsQuestion('Verification code sent to recovery@example.test', '222222')
            ->expectsOutputToContain('Super Admin recovery key (store offline; shown once):')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'role' => 'owner']);
        $this->assertDatabaseHas('users', ['email' => 'root@example.test', 'role' => 'super_admin']);
    }

    public function test_designated_super_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $superAdmin = User::query()->create([
            'name' => 'Deployment Super Admin',
            'email' => 'protected-root@example.test',
            'password' => 'Strong-Root-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $superAdmin->id,
        ]);

        try {
            $superAdmin->forceFill(['role' => 'owner'])->save();
            $this->fail('The designated Super Admin must not be demoted outside the recovery workflow.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('designated Super Admin', $exception->getMessage());
        }

        $superAdmin->refresh();

        try {
            $superAdmin->forceFill(['is_active' => false])->save();
            $this->fail('The designated Super Admin must not be deactivated outside the recovery workflow.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('designated Super Admin', $exception->getMessage());
        }

        $superAdmin->refresh();

        try {
            $superAdmin->delete();
            $this->fail('The designated Super Admin must not be deleted outside the recovery workflow.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('designated Super Admin', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'id' => $superAdmin->id,
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('platform_control', [
            'id' => 1,
            'super_admin_user_id' => $superAdmin->id,
        ]);
    }

    public function test_second_super_admin_cannot_be_created_after_deployment_principal_is_assigned(): void
    {
        $first = User::query()->create([
            'name' => 'Deployment Super Admin',
            'email' => 'first-root@example.test',
            'password' => 'Strong-Root-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $first->id,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only the designated deployment Super Admin may hold this role.');

        User::query()->create([
            'name' => 'Second Super Admin',
            'email' => 'second-root@example.test',
            'password' => 'Strong-Root-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_user_serialization_does_not_expose_authenticator_secrets_or_recovery_material(): void
    {
        $user = $this->createAdminUser([
            'name' => 'Protected Administrator',
            'email' => 'protected@example.test',
        ]);

        $serialized = $user->toArray();

        $this->assertArrayNotHasKey('mfa_secret', $serialized);
        $this->assertArrayNotHasKey('mfa_recovery_codes', $serialized);
        $this->assertArrayNotHasKey('mfa_last_used_step', $serialized);
    }
}
