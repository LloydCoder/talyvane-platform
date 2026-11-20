<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminFirstLoginPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_installed_super_admin_must_change_the_one_time_password_before_mfa_setup(): void
    {
        $admin = User::query()->create([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'password' => 'Initial-Install-Password-123!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        DB::table('platform_control')->where('id', 1)->update(['super_admin_user_id' => $admin->id]);
        $admin->forceFill(['email_verified_at' => now(), 'must_change_password_at' => now()])->save();

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'Initial-Install-Password-123!',
        ])->assertRedirect('/admin/first-login/password');

        $this->assertGuest();
        $this->get('/admin/first-login/password')->assertOk()->assertSee('Change your');

        $this->post('/admin/first-login/password', [
            'password' => 'New-Strong-Password-934!',
            'password_confirmation' => 'New-Strong-Password-934!',
        ])->assertRedirect('/admin/mfa/setup');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull($admin->fresh()->must_change_password_at);
        $this->assertTrue(Hash::check('New-Strong-Password-934!', $admin->fresh()->password));
        $this->assertFalse(Hash::check('Initial-Install-Password-123!', $admin->fresh()->password));
        $this->get('/admin/mfa/setup')->assertOk();
    }

    public function test_weak_first_login_password_is_rejected_without_clearing_requirement(): void
    {
        $admin = User::query()->create([
            'name' => 'Deployment Super Admin',
            'email' => 'weak-password-admin@example.test',
            'password' => 'Initial-Install-Password-123!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        DB::table('platform_control')->where('id', 1)->update(['super_admin_user_id' => $admin->id]);
        $admin->forceFill(['email_verified_at' => now(), 'must_change_password_at' => now()])->save();

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'Initial-Install-Password-123!',
        ])->assertRedirect('/admin/first-login/password');

        $this->post('/admin/first-login/password', [
            'password' => 'weak-password',
            'password_confirmation' => 'weak-password',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertNotNull($admin->fresh()->must_change_password_at);
        $this->assertTrue(Hash::check('Initial-Install-Password-123!', $admin->fresh()->password));
    }
}
