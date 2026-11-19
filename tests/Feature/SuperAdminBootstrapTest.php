<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_bootstrap_creates_the_single_deployment_super_admin(): void
    {
        $this->artisan('talyvane:super-admin:create', ['email' => 'ROOT@example.test'])
            ->expectsQuestion('Super Admin name', 'Deployment Owner')
            ->expectsQuestion('Password (minimum 14 characters)', 'Strong-Root-Password-934!')
            ->expectsQuestion('Confirm password', 'Strong-Root-Password-934!')
            ->expectsOutput('Deployment Super Admin created. Sign in and complete mandatory MFA setup before administration.')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'root@example.test')->firstOrFail();

        $this->assertSame('super_admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->mfa_enabled_at);
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

        $this->artisan('talyvane:super-admin:create', ['email' => 'root@example.test'])
            ->expectsQuestion('Super Admin name', 'New Deployment Owner')
            ->expectsQuestion('Password (minimum 14 characters)', 'Strong-Root-Password-934!')
            ->expectsQuestion('Confirm password', 'Strong-Root-Password-934!')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'role' => 'owner']);
        $this->assertDatabaseHas('users', ['email' => 'root@example.test', 'role' => 'super_admin']);
    }
}
