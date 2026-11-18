<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_admin_dashboard(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_owner_can_access_admin_dashboard(): void
    {
        $owner = User::create([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Platform overview');
    }

    public function test_non_privileged_user_cannot_access_admin_dashboard(): void
    {
        $operator = User::create([
            'name' => 'Operations User',
            'email' => 'operator@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_can_sign_in_and_sign_out(): void
    {
        User::create([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => 'owner@example.test',
            'password' => 'Strong-Testing-Password-934!',
        ])->assertRedirect('/admin');

        $this->assertAuthenticated();

        $this->post('/admin/logout')->assertRedirect('/admin/login');

        $this->assertGuest();
    }
}
