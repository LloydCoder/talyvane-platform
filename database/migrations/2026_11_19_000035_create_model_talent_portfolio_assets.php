<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_talent_portfolio_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('model_talent_applications')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('storage_path', 500)->nullable();
            $table->string('mime_type', 80)->default('image/webp');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('file_size');
            $table->char('sha256', 64);
            $table->string('status', 24)->default('pending_review')->index();
            $table->text('source_description');
            $table->text('consent_scope');
            $table->text('rights_evidence_reference');
            $table->text('consent_evidence_reference');
            $table->string('consent_policy_version', 80);
            $table->timestamp('consent_recorded_at');
            $table->timestamp('consent_withdrawn_at')->nullable();
            $table->text('withdrawal_evidence_reference')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'status']);
            $table->unique(['application_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_talent_portfolio_assets');
    }
};
