<?php

namespace Tests\Feature;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalizationAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_globalization_configuration(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get(route('admin.globalization'))
            ->assertOk()
            ->assertSee('Global markets');
    }

    public function test_admin_can_register_currency_metadata(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.globalization.currencies.store'), [
                'code' => 'USD',
                'numeric_code' => '840',
                'name' => 'US Dollar',
                'symbol' => '$',
                'minor_unit' => 2,
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('admin.globalization'));

        $this->assertDatabaseHas('currencies', [
            'code' => 'USD',
            'minor_unit' => 2,
            'is_enabled' => true,
        ]);
    }

    public function test_new_country_markets_are_created_pending_compliance_review(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post(route('admin.globalization.markets.store'), [
                'country_code' => 'US',
                'alpha3_code' => 'USA',
                'name' => 'United States',
                'default_locale' => 'en-US',
                'default_timezone' => 'America/New_York',
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('admin.globalization'));

        $this->assertDatabaseHas('country_markets', [
            'country_code' => 'US',
            'is_enabled' => true,
            'compliance_status' => 'pending',
        ]);
    }

    public function test_admin_can_set_reporting_currency_and_it_is_marked_in_registry(): void
    {
        $admin = $this->createAdminUser();
        Currency::query()->create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'minor_unit' => 2,
            'is_enabled' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.globalization.defaults.update'), [
                'base_currency_code' => 'USD',
                'default_locale' => 'en',
                'default_timezone' => 'UTC',
            ])
            ->assertRedirect(route('admin.globalization'));

        $this->assertDatabaseHas('globalization_settings', ['id' => 1, 'base_currency_code' => 'USD']);
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_reporting_currency' => true]);
    }

    public function test_admin_cannot_disable_the_active_reporting_currency(): void
    {
        $admin = $this->createAdminUser();
        Currency::query()->create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'minor_unit' => 2,
            'is_enabled' => true,
        ]);
        \App\Models\GlobalizationSetting::query()->create([
            'id' => 1,
            'base_currency_code' => 'USD',
            'default_locale' => 'en',
            'default_timezone' => 'UTC',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.globalization.currencies.enabled', 'USD'), ['is_enabled' => '0'])
            ->assertSessionHasErrors('currency');

        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_enabled' => true]);
    }

    public function test_base_currency_must_be_registered_and_enabled(): void
    {
        $admin = $this->createAdminUser();
        Currency::query()->create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'minor_unit' => 2,
            'is_enabled' => false,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.globalization.defaults.update'), [
                'base_currency_code' => 'USD',
                'default_locale' => 'en',
                'default_timezone' => 'UTC',
            ])
            ->assertSessionHasErrors('base_currency_code');
    }
}
