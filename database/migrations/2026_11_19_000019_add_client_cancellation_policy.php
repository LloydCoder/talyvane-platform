<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_settings', function (Blueprint $table): void {
            $table->boolean('client_cancellation_enabled')->default(false);
            $table->unsignedSmallInteger('cancellation_cutoff_hours')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('booking_settings', function (Blueprint $table): void {
            $table->dropColumn(['client_cancellation_enabled', 'cancellation_cutoff_hours']);
        });
    }
};
