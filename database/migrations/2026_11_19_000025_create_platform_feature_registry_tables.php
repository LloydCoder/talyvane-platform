<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_features', function (Blueprint $table): void {
            $table->string('key', 120)->primary();
            $table->string('name', 160);
            $table->string('module', 80)->index();
            $table->text('description');
            $table->string('implementation_status', 24)->default('planned')->index();
            $table->boolean('is_enabled')->default(false)->index();
            $table->string('risk_level', 24)->default('medium');
            $table->json('dependencies')->nullable();
            $table->json('activation_requirements')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_feature_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('feature_key', 120);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email', 254)->nullable();
            $table->string('action', 64);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500);
            $table->string('correlation_id', 64)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('feature_key')->references('key')->on('platform_features')->restrictOnDelete();
            $table->index(['feature_key', 'created_at']);
        });

        $now = now();
        DB::table('platform_features')->insert([
            ['key' => 'public.discovery', 'name' => 'Public discovery', 'module' => 'discovery', 'description' => 'Public destination and companion discovery pages.', 'implementation_status' => 'available', 'is_enabled' => true, 'risk_level' => 'high', 'dependencies' => json_encode([]), 'activation_requirements' => json_encode(['approved_market_policy']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'bookings.companionship', 'name' => 'Companionship booking', 'module' => 'bookings', 'description' => 'Manual companionship booking requests and agency-managed decisions.', 'implementation_status' => 'available', 'is_enabled' => true, 'risk_level' => 'high', 'dependencies' => json_encode(['public.discovery']), 'activation_requirements' => json_encode(['market_approval', 'adult_age_assurance', 'current_consent']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'bookings.automatic', 'name' => 'Automatic booking', 'module' => 'bookings', 'description' => 'Companion-opted-in automatic confirmation subject to all eligibility and payment gates.', 'implementation_status' => 'available', 'is_enabled' => true, 'risk_level' => 'critical', 'dependencies' => json_encode(['bookings.companionship']), 'activation_requirements' => json_encode(['companion_opt_in', 'availability', 'market_approval', 'deposit_prerequisites']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'portals.client', 'name' => 'Client portal', 'module' => 'portals', 'description' => 'Client account, shortlist, booking history and membership display.', 'implementation_status' => 'available', 'is_enabled' => true, 'risk_level' => 'high', 'dependencies' => json_encode([]), 'activation_requirements' => json_encode(['email_verification']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'portals.companion', 'name' => 'Companion portal', 'module' => 'portals', 'description' => 'Companion account, availability, consent and booking controls.', 'implementation_status' => 'available', 'is_enabled' => true, 'risk_level' => 'critical', 'dependencies' => json_encode([]), 'activation_requirements' => json_encode(['verified_account', 'consent_controls']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'cms.pages', 'name' => 'Content management', 'module' => 'content', 'description' => 'Admin-managed site pages and SEO metadata.', 'implementation_status' => 'available', 'is_enabled' => true, 'risk_level' => 'medium', 'dependencies' => json_encode([]), 'activation_requirements' => json_encode([]), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'memberships', 'name' => 'Client memberships', 'module' => 'client-experience', 'description' => 'Admin-managed client membership assignment and entitlement display.', 'implementation_status' => 'available', 'is_enabled' => true, 'risk_level' => 'high', 'dependencies' => json_encode(['portals.client']), 'activation_requirements' => json_encode(['server_side_entitlement_checks']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'payments.configuration', 'name' => 'Payment configuration', 'module' => 'payments', 'description' => 'Admin configuration of payment rails and purpose controls; does not itself settle payments.', 'implementation_status' => 'available', 'is_enabled' => true, 'risk_level' => 'critical', 'dependencies' => json_encode([]), 'activation_requirements' => json_encode(['payment_provider_review']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'payments.crypto.settlement', 'name' => 'Cryptocurrency settlement', 'module' => 'payments', 'description' => 'On-chain invoice monitoring, confirmation and reconciliation. Not yet implemented.', 'implementation_status' => 'planned', 'is_enabled' => false, 'risk_level' => 'critical', 'dependencies' => json_encode(['payments.configuration']), 'activation_requirements' => json_encode(['chain_verification', 'network_validation', 'legal_approval']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'payments.cards', 'name' => 'Card processing', 'module' => 'payments', 'description' => 'Card processor integration. Not available until written underwriting approval and implementation.', 'implementation_status' => 'planned', 'is_enabled' => false, 'risk_level' => 'critical', 'dependencies' => json_encode(['payments.configuration']), 'activation_requirements' => json_encode(['written_underwriting_approval', 'approved_processor_adapter']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model-agency.applications', 'name' => 'Model agency applications', 'module' => 'model-agency', 'description' => 'Talent applications and independent agency review workflow.', 'implementation_status' => 'planned', 'is_enabled' => false, 'risk_level' => 'high', 'dependencies' => json_encode([]), 'activation_requirements' => json_encode(['model_agency_workflow', 'representation_terms']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model-agency.casting', 'name' => 'Model agency casting', 'module' => 'model-agency', 'description' => 'Casting calls, assignments and talent booking workflow.', 'implementation_status' => 'planned', 'is_enabled' => false, 'risk_level' => 'high', 'dependencies' => json_encode(['model-agency.applications']), 'activation_requirements' => json_encode(['casting_workflow', 'assignment_terms']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'model-agency.usage-rights', 'name' => 'Model image usage rights', 'module' => 'model-agency', 'description' => 'Image usage licences, scope, term, territory and withdrawal workflow.', 'implementation_status' => 'planned', 'is_enabled' => false, 'risk_level' => 'critical', 'dependencies' => json_encode(['model-agency.applications']), 'activation_requirements' => json_encode(['rights_ledger', 'separate_media_consent']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'communications.concierge-inbox', 'name' => 'Concierge inbox', 'module' => 'communications', 'description' => 'Unified first-party enquiry and communication inbox.', 'implementation_status' => 'planned', 'is_enabled' => false, 'risk_level' => 'high', 'dependencies' => json_encode([]), 'activation_requirements' => json_encode(['secure_message_storage', 'retention_policy']), 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'integrations.calendar', 'name' => 'Calendar integration', 'module' => 'integrations', 'description' => 'Calendar synchronization for approved availability and bookings.', 'implementation_status' => 'planned', 'is_enabled' => false, 'risk_level' => 'high', 'dependencies' => json_encode(['bookings.companionship']), 'activation_requirements' => json_encode(['oauth_secret_storage', 'provider_review']), 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_feature_audit_events');
        Schema::dropIfExists('platform_features');
    }
};
