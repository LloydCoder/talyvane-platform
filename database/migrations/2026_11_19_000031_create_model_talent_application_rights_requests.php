<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_talent_applications', function (Blueprint $table): void {
            $table->timestamp('adult_eligibility_confirmed_at')->nullable()->change();
            $table->timestamp('adult_eligibility_policy_accepted_at')->nullable()->change();
            $table->timestamp('privacy_notice_accepted_at')->nullable()->change();
            $table->timestamp('legal_hold_at')->nullable();
            $table->foreignId('legal_hold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('legal_hold_reason')->nullable();
        });

        Schema::create('model_talent_application_rights_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('model_talent_applications')->restrictOnDelete();
            $table->string('request_type', 24);
            $table->string('status', 24)->default('pending');
            $table->text('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'status', 'created_at'], 'model_app_rights_status_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('model_talent_applications')->whereNull('adult_eligibility_confirmed_at')->orWhereNull('adult_eligibility_policy_accepted_at')->orWhereNull('privacy_notice_accepted_at')->exists()) {
            throw new RuntimeException('Cannot roll back applicant lifecycle after erasure has cleared consent timestamps. Restore a pre-erasure backup or retain this migration.');
        }

        Schema::dropIfExists('model_talent_application_rights_requests');

        Schema::table('model_talent_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legal_hold_by');
            $table->dropColumn(['legal_hold_at', 'legal_hold_reason']);
            $table->timestamp('adult_eligibility_confirmed_at')->nullable(false)->change();
            $table->timestamp('adult_eligibility_policy_accepted_at')->nullable(false)->change();
            $table->timestamp('privacy_notice_accepted_at')->nullable(false)->change();
        });
    }
};
