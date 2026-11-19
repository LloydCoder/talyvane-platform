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
