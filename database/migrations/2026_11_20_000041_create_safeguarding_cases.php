<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safeguarding_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->string('case_type', 48)->index();
            $table->string('severity', 16)->index();
            $table->string('status', 24)->default('open')->index();
            $table->string('subject_type', 32)->nullable()->index();
            $table->text('subject_reference')->nullable();
            $table->text('summary');
            $table->longText('details');
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('legal_hold_at')->nullable();
            $table->foreignId('legal_hold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('legal_hold_reason')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'status', 'created_at']);
            $table->index(['severity', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safeguarding_cases');
    }
};
