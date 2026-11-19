<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('globalization_settings', function (Blueprint $table): void {
            $table->id();
            $table->char('base_currency_code', 3)->nullable();
            $table->string('default_locale', 35)->default('en');
            $table->string('default_timezone', 64)->default('UTC');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('base_currency_code')->references('code')->on('currencies')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('globalization_settings');
    }
};
