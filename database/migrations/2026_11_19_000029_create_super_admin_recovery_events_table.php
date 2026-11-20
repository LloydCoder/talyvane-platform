<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_admin_recovery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('super_admin_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('session_version_before')->nullable();
            $table->unsignedBigInteger('session_version_after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['super_admin_user_id', 'occurred_at'], 'super_admin_recovery_user_occurred_index');
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_admin_recovery_events');
    }
};
