<?php

namespace Tests\Feature;

use App\Models\SuperAdminRecoveryGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SuperAdminRecoveryGuardianTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardian_assignment_cannot_be_updated_or_deleted_through_eloquent(): void
    {
        $guardian = User::query()->create([
            'name' => 'Recovery Guardian',
            'email' => 'guardian@example.test',
            'password' => 'test-password-value',
            'role' => 'owner',
            'is_active' => true,
        ]);

        $assignment = SuperAdminRecoveryGuardian::query()->create([
            'slot' => 1,
            'user_id' => $guardian->id,
            'enrolled_at' => now(),
        ]);

        try {
            $assignment->update(['slot' => 2]);
            $this->fail('Guardian assignment updates must use controlled CLI workflows.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            $assignment->delete();
            $this->fail('Guardian assignment deletion must use controlled CLI workflows.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertDatabaseHas('super_admin_recovery_guardians', [
            'id' => $assignment->id,
            'slot' => 1,
            'user_id' => $guardian->id,
        ]);
    }
}
