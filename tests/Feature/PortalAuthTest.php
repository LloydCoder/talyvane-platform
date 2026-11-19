<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_creates_a_client_account_only(): void
    {
        $this->post('/register', [
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'password' => 'Strong-Client-Password-934!',
            'password_confirmation' => 'Strong-Client-Password-934!',
            'terms_accepted' => '1',
        ])->assertRedirect('/portal');

        $user = User::where('email', 'taylor@example.test')->firstOrFail();

        $this->assertSame('client', $user->role);
        $this->assertTrue(Hash::check('Strong-Client-Password-934!', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_client_portal_requires_email_verification(): void
    {
        $client = User::create([
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);

        $this->actingAs($client)
            ->get('/portal')
            ->assertRedirect('/email/verify');
    }

    public function test_verified_client_can_access_client_portal(): void
    {
        $client = User::create([
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($client)
            ->get('/portal')
            ->assertOk()
            ->assertSee('Your account');
    }

    public function test_public_registration_cannot_create_companion_or_admin_roles(): void
    {
        $this->post('/register', [
            'name' => 'Companion Account',
            'email' => 'companion@example.test',
            'password' => 'Strong-Client-Password-934!',
            'password_confirmation' => 'Strong-Client-Password-934!',
            'role' => 'companion',
            'terms_accepted' => '1',
        ])->assertRedirect('/portal');

        $this->assertDatabaseHas('users', [
            'email' => 'companion@example.test',
            'role' => 'client',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'companion@example.test',
            'role' => 'companion',
        ]);
    }

    public function test_client_cannot_access_admin_dashboard(): void
    {
        $client = User::create([
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);

        $this->actingAs($client)
            ->get('/admin')
            ->assertForbidden();
    }
    public function test_guest_client_portal_redirects_to_client_login(): void
    {
        $this->get('/portal')->assertRedirect('/login');
    }

    public function test_guest_admin_dashboard_redirects_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

}
