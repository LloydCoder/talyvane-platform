<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_membership_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->nullable()->constrained('client_memberships')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email', 254)->nullable();
            $table->string('event_type', 64);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500);
            $table->string('correlation_id', 64)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_id', 'created_at']);
            $table->index(['membership_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_membership_events');
    }
};
