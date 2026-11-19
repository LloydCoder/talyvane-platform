<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_talent_application_rights_requests', function (Blueprint $table): void {
            $table->string('response_status', 24)->default('pending')->index();
            $table->timestamp('response_due_at')->nullable()->index();
            $table->timestamp('response_extended_at')->nullable();
            $table->text('response_extension_reason')->nullable();
            $table->foreignId('response_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('response_reviewed_at')->nullable();
            $table->json('response_review_checklist')->nullable();
            $table->longText('response_content')->nullable();
            $table->char('response_sha256', 64)->nullable();
            $table->timestamp('response_responded_at')->nullable();
            $table->string('response_delivery_method', 32)->nullable();
            $table->char('response_delivery_reference_hash', 64)->nullable();
            $table->text('response_decision_reason')->nullable();
            $table->foreignId('response_delivered_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('platform_features')
            ->where('key', 'model-agency.applications')
            ->update([
                'activation_requirements' => json_encode([
                    'model_agency_workflow',
                    'applicant_data_lifecycle',
                    'applicant_privacy_notice',
                    'adult_eligibility_policy',
                    'operator_sar_fulfilment',
                ]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('platform_features')
            ->where('key', 'model-agency.applications')
            ->update([
                'activation_requirements' => json_encode([
                    'model_agency_workflow',
                    'applicant_data_lifecycle',
                    'applicant_privacy_notice',
                    'adult_eligibility_policy',
                ]),
                'updated_at' => now(),
            ]);

        Schema::table('model_talent_application_rights_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('response_reviewed_by');
            $table->dropConstrainedForeignId('response_delivered_by');
            $table->dropColumn([
                'response_status',
                'response_due_at',
                'response_extended_at',
                'response_extension_reason',
                'response_reviewed_at',
                'response_review_checklist',
                'response_content',
                'response_sha256',
                'response_responded_at',
                'response_delivery_method',
                'response_delivery_reference_hash',
                'response_decision_reason',
            ]);
        });
    }
};
