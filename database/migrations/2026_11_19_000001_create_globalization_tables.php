<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->char('numeric_code', 3)->nullable()->unique();
            $table->string('name', 100);
            $table->string('symbol', 16)->nullable();
            $table->unsignedTinyInteger('minor_unit')->default(2);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_reporting_currency')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('country_markets', function (Blueprint $table): void {
            $table->char('country_code', 2)->primary();
            $table->char('alpha3_code', 3)->nullable()->unique();
            $table->string('name', 120);
            $table->char('default_currency_code', 3)->nullable();
            $table->string('default_locale', 35)->nullable();
            $table->string('default_timezone', 64)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->string('compliance_status', 24)->default('pending');
            $table->timestamp('compliance_reviewed_at')->nullable();
            $table->string('compliance_reviewed_by')->nullable();
            $table->json('market_policy')->nullable();
            $table->timestamps();

            $table->foreign('default_currency_code')->references('code')->on('currencies')->nullOnDelete();
            $table->index(['is_enabled', 'compliance_status']);
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('base_currency_code', 3);
            $table->char('quote_currency_code', 3);
            $table->decimal('rate', 30, 14);
            $table->string('provider', 100);
            $table->timestamp('observed_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('source_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('base_currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('quote_currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['base_currency_code', 'quote_currency_code', 'provider', 'observed_at'], 'exchange_rates_pair_provider_observed_unique');
            $table->index(['base_currency_code', 'quote_currency_code', 'expires_at'], 'exchange_rates_pair_expiry_index');
        });

        Schema::create('localized_prices', function (Blueprint $table): void {
            $table->id();
            $table->morphs('priceable');
            $table->char('country_code', 2)->nullable();
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('price_basis', 32)->default('manual');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('country_code')->references('country_code')->on('country_markets')->nullOnDelete();
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['country_code', 'currency_code', 'is_active']);
            $table->index(['priceable_type', 'priceable_id', 'is_active'], 'localized_prices_priceable_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('localized_prices');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('country_markets');
        Schema::dropIfExists('currencies');
    }
};
