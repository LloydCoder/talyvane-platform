<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GlobalizationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_globalization_tables_are_available_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('currencies'));
        $this->assertTrue(Schema::hasTable('country_markets'));
        $this->assertTrue(Schema::hasTable('exchange_rates'));
        $this->assertTrue(Schema::hasTable('localized_prices'));
        $this->assertTrue(Schema::hasTable('globalization_settings'));
        $this->assertTrue(Schema::hasTable('globalization_audit_events'));
        $this->assertTrue(Schema::hasColumn('destinations', 'image_path'));
        $this->assertTrue(Schema::hasColumn('country_markets', 'compliance_evidence_reference'));
        $this->assertTrue(Schema::hasColumn('country_markets', 'compliance_review_summary'));
    }

    public function test_new_destination_registers_its_country_as_pending_and_disabled(): void
    {
        $destination = \App\Models\Destination::create([
            'name' => 'Lisbon',
            'slug' => 'lisbon',
            'country_code' => 'PT',
            'country_name' => 'Portugal',
            'city_name' => 'Lisbon',
            'currency_code' => 'EUR',
            'locale' => 'pt-PT',
            'timezone' => 'Europe/Lisbon',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('country_markets', [
            'country_code' => 'PT',
            'is_enabled' => false,
            'compliance_status' => 'pending',
        ]);
        $this->assertFalse($destination->countryMarket->isOperationallyApproved());
    }

    public function test_financial_schema_preserves_currency_precision_and_rate_provenance(): void
    {
        $this->assertTrue(Schema::hasColumn('currencies', 'minor_unit'));
        $this->assertTrue(Schema::hasColumn('currencies', 'is_reporting_currency'));
        $this->assertTrue(Schema::hasColumn('exchange_rates', 'provider'));
        $this->assertTrue(Schema::hasColumn('exchange_rates', 'observed_at'));
        $this->assertTrue(Schema::hasColumn('exchange_rates', 'expires_at'));
        $this->assertTrue(Schema::hasColumn('localized_prices', 'amount_minor'));
        $this->assertTrue(Schema::hasColumn('localized_prices', 'currency_code'));
    }
}
