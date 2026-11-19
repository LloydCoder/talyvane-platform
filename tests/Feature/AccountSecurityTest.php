<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_client_can_change_password_with_current_password(): void
    {
        $client = User::create([
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'password' => 'test-password-one',
            'role' => 'client',
            'is_active' => true,
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($client)
            ->patch('/account/password', [
                'current_password' => 'test-password-one',
                'password' => 'test-password-two123',
                'password_confirmation' => 'test-password-two123',
            ])
            ->assertRedirect('/portal')
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('test-password-two123', $client->fresh()->password));
    }


    public function test_admin_password_change_rotates_session_version_and_preserves_the_current_session(): void
    {
        $admin = User::create([
            'name' => 'Security Administrator',
            'email' => 'security-admin@example.test',
            'password' => 'test-password-one',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->forceFill([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enabled_at' => now(),
            'admin_session_version' => 3,
            'remember_token' => 'old-admin-remember-token',
        ])->save();

        $this->actingAs($admin)
            ->patch('/account/password', [
                'current_password' => 'test-password-one',
                'password' => 'test-password-two123',
                'password_confirmation' => 'test-password-two123',
            ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status')
            ->assertSessionHas('admin_session_version', 4);

        $this->assertTrue(Hash::check('test-password-two123', $admin->fresh()->password));
        $this->assertSame(4, $admin->fresh()->admin_session_version);
        $this->assertNotSame('old-admin-remember-token', $admin->fresh()->remember_token);
    }

    public function test_password_change_rejects_incorrect_current_password(): void
    {
        $client = User::create([
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'password' => 'test-password-one',
            'role' => 'client',
            'is_active' => true,
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($client)
            ->from('/portal')
            ->patch('/account/password', [
                'current_password' => 'wrong-test-password',
                'password' => 'test-password-two123',
                'password_confirmation' => 'test-password-two123',
            ])
            ->assertRedirect('/portal')
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('test-password-one', $client->fresh()->password));
    }
}
