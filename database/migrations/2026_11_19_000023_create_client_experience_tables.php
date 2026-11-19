<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('companion_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'companion_profile_id']);
            $table->index(['client_id', 'created_at']);
        });

        Schema::create('client_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tier_name', 80);
            $table->text('benefits_summary');
            $table->string('status', 24)->default('active')->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->text('internal_note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_memberships');
        Schema::dropIfExists('client_favorites');
    }
};
