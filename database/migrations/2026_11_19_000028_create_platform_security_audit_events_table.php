<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_security_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('subject_email', 254);
            $table->string('action', 64);
            $table->string('reason', 500);
            $table->json('details')->nullable();
            $table->string('actor_type', 32)->default('cli');
            $table->string('actor_identifier', 128)->default('local-cli');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
            $table->index(['subject_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_security_audit_events');
    }
};
