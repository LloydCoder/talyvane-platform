<?php

namespace Tests\Feature;

use App\Models\SuperAdminRecoveryGuardian;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminBreakGlassTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recovery_owner_cannot_be_assigned_to_two_guardian_slots(): void
    {
        $guardian = User::query()->create([
            'name' => 'Recovery Guardian',
            'email' => 'guardian@example.test',
            'password' => 'test-password-value',
            'role' => 'owner',
            'is_active' => true,
        ]);

        SuperAdminRecoveryGuardian::query()->create([
            'slot' => 1,
            'user_id' => $guardian->id,
            'enrolled_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        SuperAdminRecoveryGuardian::query()->create([
            'slot' => 2,
            'user_id' => $guardian->id,
            'enrolled_at' => now(),
        ]);
    }
}
