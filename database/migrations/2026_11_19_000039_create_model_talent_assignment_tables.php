<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_talent_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->foreignId('source_submission_id')->unique()->constrained('model_casting_submissions')->restrictOnDelete();
            $table->foreignId('application_id')->constrained('model_talent_applications')->restrictOnDelete();
            $table->foreignId('representation_agreement_id')->constrained('model_talent_representation_agreements')->restrictOnDelete();
            $table->char('country_code', 2);
            $table->string('project_purpose', 40);
            $table->text('client_name');
            $table->text('client_reference')->nullable();
            $table->text('brief');
            $table->text('location_city')->nullable();
            $table->timestamp('shoot_starts_at');
            $table->timestamp('shoot_ends_at');
            $table->text('deliverables');
            $table->decimal('compensation_amount', 20, 4)->nullable();
            $table->decimal('expense_cap_amount', 20, 4)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->decimal('commission_percent', 5, 2);
            $table->string('assignment_policy_version', 80);
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('offered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('offered_at')->nullable();
            $table->foreignId('acceptance_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->text('executed_document_reference')->nullable();
            $table->text('model_acceptance_evidence_reference')->nullable();
            $table->text('model_decline_evidence_reference')->nullable();
            $table->text('decline_reason')->nullable();
            $table->foreignId('declined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('disputed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disputed_at')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->timestamps();

            $table->foreign('country_code')->references('country_code')->on('country_markets')->restrictOnDelete();
            $table->foreign('currency_code')->references('code')->on('currencies')->nullOnDelete();
            $table->index(['application_id', 'status']);
            $table->index(['status', 'shoot_starts_at', 'shoot_ends_at'], 'model_assignments_status_shoot_window_index');
        });

        Schema::create('model_talent_assignment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('model_talent_assignments')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 24);
            $table->string('event_type', 64);
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['assignment_id', 'occurred_at']);
        });

        DB::table('platform_features')->insert([
            'key' => 'model-agency.assignments',
            'name' => 'Model assignments',
            'module' => 'model-agency',
            'description' => 'Human-approved model assignment offers, external contract evidence, model acceptance and delivery states; excludes settlement.',
            'implementation_status' => 'available',
            'is_enabled' => false,
            'risk_level' => 'critical',
            'dependencies' => json_encode(['model-agency.applications', 'model-agency.usage-rights', 'model-agency.casting']),
            'activation_requirements' => json_encode([
                'model_agency_assignment_schema',
                'model_agency_assignment_policy',
                'market_approval',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_features')->where('key', 'model-agency.assignments')->update([
            'implementation_status' => 'planned',
            'is_enabled' => false,
            'updated_at' => now(),
        ]);
        Schema::dropIfExists('model_talent_assignment_events');
        Schema::dropIfExists('model_talent_assignments');
    }
};
