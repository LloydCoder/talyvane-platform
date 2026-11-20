<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Feature\Support\EnablesModelAgencyFeatureRoutes;

class ModelTalentSettlementAdminTest extends TestCase
{
    use RefreshDatabase;
    use EnablesModelAgencyFeatureRoutes;

    private function enableSettlementFeature(): void
    {
        $this->enableModelAgencyFeatureRoutes();
        $destination = \\App\\Models\\Destination::query()->firstOrCreate(
            ['slug' => 'settlement-feature-admin-market'],
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

    public function test_settlement_register_remains_available_for_close_out_while_new_claims_are_paused(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'settlement-owner@example.test',
            'role' => 'owner',
            'is_active' => true,
        ]);
        $this->actingAs($owner)->get('/admin/model-settlements')->assertOk()->assertSee('New financial claims paused')->assertDontSee('Submit expense');

        $this->enableSettlementFeature();
        $this->actingAs($owner)
            ->get('/admin/model-settlements')
            ->assertOk()
            ->assertSee('Expense and settlement ledger')
            ->assertSee('No settlements have been calculated.');
    }

    public function test_client_cannot_access_the_model_settlement_register_even_when_enabled(): void
    {
        $this->enableSettlementFeature();
        $client = User::query()->create([
            'name' => 'Private Client',
            'email' => 'settlement-client@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);

        $this->actingAs($client)->get('/admin/model-settlements')->assertForbidden();
    }
    public function test_finance_role_can_access_settlement_ledger_without_agency_or_platform_access(): void
    {
        $this->enableSettlementFeature();
        $finance = $this->createAdminUser([
            'name' => 'Finance Specialist',
            'email' => 'finance-specialist@example.test',
            'role' => 'finance',
            'is_active' => true,
        ]);

        $this->actingAs($finance)
            ->get('/admin/model-settlements')
            ->assertOk()
            ->assertSee('Expense and settlement ledger');

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/model-assignments')->assertForbidden();
        $this->get('/admin/branding')->assertForbidden();
    }

    public function test_general_administrator_cannot_access_finance_ledger(): void
    {
        $this->enableSettlementFeature();
        $admin = $this->createAdminUser([
            'name' => 'Operations Administrator',
            'email' => 'operations-admin@example.test',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get('/admin/model-settlements')->assertForbidden();
    }

}
