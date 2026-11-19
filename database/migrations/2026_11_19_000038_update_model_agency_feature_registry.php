<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_features')->where('key', 'model-agency.applications')->update([
            'name' => 'Model agency applications',
            'description' => 'Adult model applications, privacy rights, legal holds, eligibility verification and separate agency review.',
            'implementation_status' => 'available',
            'is_enabled' => false,
            'risk_level' => 'high',
            'dependencies' => json_encode([]),
            'activation_requirements' => json_encode([
                'model_agency_workflow',
                'applicant_data_lifecycle',
                'applicant_privacy_notice',
                'adult_eligibility_policy',
                'adult_eligibility_verification',
            ]),
            'updated_at' => now(),
        ]);

        DB::table('platform_features')->where('key', 'model-agency.usage-rights')->update([
            'name' => 'Model representation and image rights',
            'description' => 'Versioned representation agreements, private portfolio consent and purpose-bound image usage licence ledger.',
            'implementation_status' => 'available',
            'is_enabled' => false,
            'risk_level' => 'critical',
            'dependencies' => json_encode(['model-agency.applications']),
            'activation_requirements' => json_encode([
                'model_agency_rights_ledger',
                'model_agency_representation_terms',
                'model_agency_portfolio_consent',
                'model_agency_image_licence_policy',
                'market_approval',
            ]),
            'updated_at' => now(),
        ]);

        DB::table('platform_features')->where('key', 'model-agency.casting')->update([
            'name' => 'Private model casting',
            'description' => 'Private casting briefs, licensed submissions and manual shortlist/selection; no assignment or public image delivery.',
            'implementation_status' => 'available',
            'is_enabled' => false,
            'risk_level' => 'critical',
            'dependencies' => json_encode(['model-agency.applications', 'model-agency.usage-rights']),
            'activation_requirements' => json_encode([
                'model_agency_casting_schema',
                'model_agency_casting_policy',
                'market_approval',
            ]),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_features')->where('key', 'model-agency.applications')->update([
            'implementation_status' => 'planned',
            'is_enabled' => false,
            'activation_requirements' => json_encode(['model_agency_workflow', 'representation_terms']),
            'updated_at' => now(),
        ]);
        DB::table('platform_features')->where('key', 'model-agency.usage-rights')->update([
            'implementation_status' => 'planned',
            'is_enabled' => false,
            'activation_requirements' => json_encode(['rights_ledger', 'separate_media_consent']),
            'updated_at' => now(),
        ]);
        DB::table('platform_features')->where('key', 'model-agency.casting')->update([
            'implementation_status' => 'planned',
            'is_enabled' => false,
            'activation_requirements' => json_encode(['casting_workflow', 'assignment_terms']),
            'updated_at' => now(),
        ]);
    }
};
