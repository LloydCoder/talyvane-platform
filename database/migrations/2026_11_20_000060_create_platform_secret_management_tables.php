<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_secrets', function (Blueprint $table): void {
            $table->id();
            $table->string('secret_key', 120)->unique();
            $table->string('vault_path', 180)->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('vault_version')->default(0);
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_secret_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('secret_key', 120);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email', 254)->nullable();
            $table->string('action', 64);
            $table->unsignedInteger('vault_version')->nullable();
            $table->string('reason', 500);
            $table->string('correlation_id', 64)->index();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('secret_key')->references('secret_key')->on('platform_secrets')->restrictOnDelete();
            $table->index(['secret_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_secret_audit_events');
        Schema::dropIfExists('platform_secrets');
    }
};
