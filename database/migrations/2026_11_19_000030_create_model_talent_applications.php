<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_talent_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->text('full_name');
            $table->text('email');
            $table->text('phone')->nullable();
            $table->char('country_code', 2)->index();
            $table->text('city');
            $table->text('portfolio_url')->nullable();
            $table->text('experience_summary');
            $table->string('status', 24)->default('submitted')->index();
            $table->timestamp('adult_eligibility_confirmed_at');
            $table->string('adult_eligibility_policy_version', 80);
            $table->timestamp('adult_eligibility_policy_accepted_at');
            $table->string('privacy_notice_version', 80);
            $table->timestamp('privacy_notice_accepted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('submitted_at')->index();
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('model_talent_application_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('model_talent_applications')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 24);
            $table->string('event_type', 48);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['application_id', 'occurred_at']);
        });

        DB::table('platform_features')->where('key', 'model-agency.applications')->update([
            'implementation_status' => 'available',
            'is_enabled' => false,
            'dependencies' => json_encode(['cms.pages']),
            'description' => 'Independent adult model-agency application intake and reviewed admissions workflow. This is separate from companionship profiles and bookings.',
            'activation_requirements' => json_encode([
                'model_agency_workflow',
                'applicant_data_lifecycle',
                'applicant_privacy_notice',
                'adult_eligibility_policy',
            ]),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_features')->where('key', 'model-agency.applications')->update([
            'implementation_status' => 'planned',
            'is_enabled' => false,
            'dependencies' => json_encode([]),
            'activation_requirements' => json_encode(['model_agency_workflow', 'representation_terms']),
            'updated_at' => now(),
        ]);

        Schema::dropIfExists('model_talent_application_events');
        Schema::dropIfExists('model_talent_applications');
    }
};
