<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('booking_mode', 20)->default('manual');
            $table->boolean('auto_confirm_enabled')->default(false);
            $table->boolean('auto_requires_verified_deposit')->default(true);
            $table->unsignedSmallInteger('minimum_notice_hours')->default(12);
            $table->unsignedSmallInteger('maximum_advance_days')->default(90);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_settings');
    }
};
