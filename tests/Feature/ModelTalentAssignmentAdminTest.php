<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Feature\Support\EnablesModelAgencyFeatureRoutes;

class ModelTalentAssignmentAdminTest extends TestCase
{
    use RefreshDatabase;
    use EnablesModelAgencyFeatureRoutes;

    private function enableAssignmentFeature(): void
    {
        $this->enableModelAgencyFeatureRoutes();
        $destination = \\App\\Models\\Destination::query()->firstOrCreate(
            ['slug' => 'assignment-feature-admin-market'],
            [
                'name' => 'London',
                'country_code' => 'GB',
                'country_name' => 'United Kingdom',
                'city_name' => 'London',
                'currency_code' => 'GBP',
                'locale' => 'en',
                'timezone' => 'Europe/London',
                'is_active' => true,
            ],
        );
        $this->approveCountryMarket($destination);
    }

    public function test_assignment_register_remains_available_for_existing_workflows_while_creation_is_paused(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin)->get('/admin/model-assignments')->assertOk()->assertSee('New assignments paused')->assertDontSee('Create assignment draft');

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
