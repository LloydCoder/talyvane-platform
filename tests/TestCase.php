<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createAdminUser(array $attributes = []): User
    {
        $user = User::query()->create(array_merge([
            'name' => 'Test Administrator',
            'email' => 'admin@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'admin',
            'is_active' => true,
        ], $attributes));

        $user->forceFill([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enabled_at' => now(),
            'mfa_last_used_step' => intdiv(time(), 30) - 1,
            'mfa_recovery_codes' => [],
        ])->save();

        return $user;
    }
}
