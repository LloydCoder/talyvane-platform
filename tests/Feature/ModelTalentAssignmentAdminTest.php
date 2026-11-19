<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelTalentAssignmentAdminTest extends TestCase
{
    use RefreshDatabase;

    private function enableAssignmentFeature(): void
    {
        DB::table('platform_features')->whereIn('key', [
            'model-agency.applications',
            'model-agency.usage-rights',
            'model-agency.casting',
            'model-agency.assignments',
        ])->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }

    public function test_assignment_register_is_admin_only_and_disabled_by_default(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin)->get('/admin/model-assignments')->assertNotFound();

        $this->enableAssignmentFeature();
        $this->actingAs($admin)
            ->get('/admin/model-assignments')
            ->assertOk()
            ->assertSee('Assignment register')
            ->assertSee('Create assignment draft');
    }

    public function test_client_cannot_access_the_model_assignment_register_even_when_enabled(): void
    {
        $this->enableAssignmentFeature();
        $client = User::query()->create([
            'name' => 'Private Client',
            'email' => 'assignment-client@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);

        $this->actingAs($client)->get('/admin/model-assignments')->assertForbidden();
    }
}
