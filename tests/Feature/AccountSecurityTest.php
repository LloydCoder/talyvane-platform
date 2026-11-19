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
                'password' => 'test-password-two',
                'password_confirmation' => 'test-password-two',
            ])
            ->assertRedirect('/portal')
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('test-password-two', $client->fresh()->password));
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
                'password' => 'test-password-two',
                'password_confirmation' => 'test-password-two',
            ])
            ->assertRedirect('/portal')
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('test-password-one', $client->fresh()->password));
    }
}
