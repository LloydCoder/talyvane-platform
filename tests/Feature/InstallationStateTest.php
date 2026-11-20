<?php

namespace Tests\Feature;

use App\Install\InstallationLock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallationStateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(storage_path('app/private/talyvane.install.lock'));
        parent::tearDown();
    }

    public function test_completed_database_without_its_signed_lock_fails_closed(): void
    {
        config(['talyvane.installer.enforce' => true]);
        DB::table('platform_control')->where('id', 1)->update([
            'installation_id' => '00000000-0000-4000-8000-000000000001',
            'installation_lock_hash' => str_repeat('a', 64),
            'installation_completed_at' => now(),
        ]);

        $this->get('/admin/login')->assertStatus(503);
        $this->get('/install')->assertStatus(503);
    }

    public function test_valid_signed_lock_allows_application_access_and_blocks_installer_routes(): void
    {
        $lock = new InstallationLock(base_path(), (string) config('app.key'));
        $created = $lock->create();

        DB::table('platform_control')->where('id', 1)->update([
            'installation_id' => $created['installation_id'],
            'installation_lock_hash' => $created['lock_hash'],
            'installation_completed_at' => now(),
        ]);
        config(['talyvane.installer.enforce' => true]);

        $this->get('/admin/login')->assertOk();
        $this->get('/install')->assertNotFound();
    }

    public function test_installer_is_hidden_for_legacy_cli_provisioned_deployments_even_without_enforcement_flag(): void
    {
        $admin = User::query()->create([
            'name' => 'Existing Deployment Super Admin',
            'email' => 'existing-deployment-admin@example.test',
            'password' => 'Strong-Root-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $admin->id,
        ]);
        config(['talyvane.installer.enforce' => false]);

        $this->get('/install')->assertNotFound();
    }
}
