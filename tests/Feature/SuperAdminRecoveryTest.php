<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class SuperAdminRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployment_super_admin_cannot_be_demoted(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);
        DB::table('platform_control')->where('id', 1)->update(['super_admin_user_id' => $admin->id]);

        $this->expectException(LogicException::class);
        $admin->forceFill(['role' => 'admin'])->save();
    }

    public function test_deployment_super_admin_cannot_be_deactivated_or_deleted(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);
        DB::table('platform_control')->where('id', 1)->update(['super_admin_user_id' => $admin->id]);

        try {
            $admin->forceFill(['is_active' => false])->save();
            $this->fail('The deployment Super Admin must not be deactivated.');
        } catch (LogicException) {
            $this->assertTrue($admin->fresh()->is_active);
        }

        $this->expectException(LogicException::class);
        $admin->delete();
    }

    public function test_eloquent_cannot_create_a_second_deployment_super_admin(): void
    {
        $first = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);
        DB::table('platform_control')->where('id', 1)->update(['super_admin_user_id' => $first->id]);

        $this->expectException(LogicException::class);
        User::query()->create([
            'name' => 'Second Super Admin',
            'email' => 'second-super-admin@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_cli_recovery_resets_credentials_and_mfa_for_the_existing_super_admin(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
        ]);
        DB::table('platform_control')->where('id', 1)->update(['super_admin_user_id' => $admin->id]);

        $this->artisan('talyvane:super-admin:recover', ['email' => 'super-admin@example.test'])
            ->expectsQuestion('Type RECOVER TALYVANE SUPER ADMIN to continue', 'RECOVER TALYVANE SUPER ADMIN')
            ->expectsQuestion('New Super Admin email', 'recovered@example.test')
            ->expectsQuestion('Recovery reason', 'The original deployment mailbox and authenticator were lost.')
            ->expectsQuestion('New password (minimum 14 characters)', 'Strong-Recovery-Password-934!')
            ->expectsQuestion('Confirm password', 'Strong-Recovery-Password-934!')
            ->expectsOutput('Super Admin recovery completed. Sign in with the new credentials and enroll MFA again.')
            ->assertExitCode(0);

        $recovered = $admin->fresh();

        $this->assertSame('recovered@example.test', $recovered->email);
        $this->assertSame('super_admin', $recovered->role);
        $this->assertTrue($recovered->is_active);
        $this->assertNull($recovered->email_verified_at);
        $this->assertNull($recovered->mfa_secret);
        $this->assertNull($recovered->mfa_enabled_at);
        $this->assertNull($recovered->mfa_last_used_step);
        $this->assertNull($recovered->mfa_recovery_codes);
        $this->assertDatabaseHas('platform_control', ['id' => 1, 'super_admin_user_id' => $admin->id]);
        $this->assertDatabaseHas('platform_security_audit_events', [
            'subject_user_id' => $admin->id,
            'action' => 'super_admin.recovered',
            'subject_email' => 'recovered@example.test',
        ]);
    }

    public function test_cli_recovery_refuses_to_promote_an_owner(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Legacy Owner',
            'email' => 'owner@example.test',
            'role' => 'owner',
        ]);

        $this->artisan('talyvane:super-admin:recover', ['email' => 'owner@example.test'])
            ->expectsOutput('Recovery is permitted only for the Super Admin referenced by this deployment.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'role' => 'owner']);
    }
}
