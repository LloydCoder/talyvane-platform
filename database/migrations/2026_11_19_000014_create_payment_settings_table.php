<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('payments_enabled')->default(false);
            $table->boolean('crypto_enabled')->default(false);
            $table->boolean('cards_enabled')->default(false);
            $table->boolean('manual_enabled')->default(false);
            $table->boolean('booking_payments_enabled')->default(false);
            $table->boolean('membership_payments_enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
