<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelTalentSettlementAdminTest extends TestCase
{
    use RefreshDatabase;

    private function enableSettlementFeature(): void
    {
        DB::table('platform_features')->whereIn('key', [
            'model-agency.applications',
            'model-agency.usage-rights',
            'model-agency.casting',
            'model-agency.assignments',
            'model-agency.settlements',
        ])->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }

    public function test_settlement_register_is_admin_only_and_disabled_by_default(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin)->get('/admin/model-settlements')->assertNotFound();

        $this->enableSettlementFeature();
        $this->actingAs($admin)
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
