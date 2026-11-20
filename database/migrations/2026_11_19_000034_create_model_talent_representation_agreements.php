<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_talent_representation_agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('model_talent_applications')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->char('country_code', 2)->index();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft')->index();
            $table->text('terms_snapshot');
            $table->string('terms_policy_version', 80);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('offered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('offered_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->text('executed_document_reference')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->foreignId('terminated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('termination_reason')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'status'], 'model_rep_agreements_application_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_talent_representation_agreements');
    }
};
