<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_talent_image_licences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('model_talent_applications')->restrictOnDelete();
            $table->foreignId('portfolio_asset_id')->constrained('model_talent_portfolio_assets')->restrictOnDelete();
            $table->foreignId('representation_agreement_id')->constrained('model_talent_representation_agreements')->restrictOnDelete();
            $table->string('reference', 48)->unique();
            $table->string('status', 16)->default('draft')->index();
            $table->text('licensee_name');
            $table->text('licensee_reference')->nullable();
            $table->string('purpose', 40);
            $table->json('channels');
            $table->text('purpose_details');
            $table->char('country_code', 2);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('exclusivity')->default(false);
            $table->decimal('compensation_amount', 20, 4)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('licence_policy_version', 80);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->text('execution_evidence_reference')->nullable();
            $table->text('model_consent_evidence_reference')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->foreign('country_code')->references('country_code')->on('country_markets')->restrictOnDelete();
            $table->foreign('currency_code')->references('code')->on('currencies')->nullOnDelete();
            $table->index(['portfolio_asset_id', 'status']);
            $table->index(['representation_agreement_id', 'status'], 'model_image_licences_agreement_status_index');
            $table->index(['country_code', 'purpose', 'status']);
            $table->index(['status', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_talent_image_licences');
    }
};
