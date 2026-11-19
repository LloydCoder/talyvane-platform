<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 16)->unique();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('companion_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('destination_id')->constrained()->restrictOnDelete();
            $table->string('booking_mode', 20);
            $table->string('status', 24)->default('pending_review')->index();
            $table->string('deposit_status', 20)->default('pending')->index();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('duration_minutes');
            $table->text('client_note')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['companion_profile_id', 'status', 'starts_at']);
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
