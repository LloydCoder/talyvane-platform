<?php

namespace Tests\Feature\Support;

use App\Models\SitePage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait EnablesModelAgencyFeatureRoutes
{
    protected function enableModelAgencyFeatureRoutes(array $additionalFeatures = []): void
    {
        $keys = [
            'model-agency.applications',
            'model-agency.usage-rights',
            'model-agency.casting',
            'model-agency.assignments',
            'model-agency.settlements',
        ];

        DB::table('platform_features')->whereIn('key', array_unique(array_merge($keys, $additionalFeatures)))->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);

        config([
            'talyvane.model_agency.retention.application_days' => 365,
            'talyvane.model_agency.retention.accepted_days' => 730,
            'talyvane.model_agency.retention.schedule_approved' => true,
            'talyvane.model_agency.privacy_notice_version' => 'privacy-v1',
            'talyvane.model_agency.privacy_notice_approved' => true,
            'talyvane.model_agency.adult_eligibility_policy_version' => 'adult-v1',
            'talyvane.model_agency.adult_eligibility_policy_approved' => true,
            'talyvane.model_agency.representation_terms_version' => 'representation-v1',
            'talyvane.model_agency.representation_terms_approved' => true,
            'talyvane.model_agency.portfolio_consent_version' => 'portfolio-v1',
            'talyvane.model_agency.portfolio_consent_approved' => true,
            'talyvane.model_agency.image_licence_policy_version' => 'licence-v1',
            'talyvane.model_agency.image_licence_policy_approved' => true,
            'talyvane.model_agency.casting_policy_version' => 'casting-v1',
            'talyvane.model_agency.casting_policy_approved' => true,
            'talyvane.model_agency.assignment_policy_version' => 'assignment-v1',
            'talyvane.model_agency.assignment_policy_approved' => true,
            'talyvane.model_agency.settlement_policy_version' => 'settlement-v1',
            'talyvane.model_agency.settlement_policy_approved' => true,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.test',
        ]);

        SitePage::query()->updateOrCreate(['slug' => 'model-applicant-privacy'], [
            'title' => 'Model Applicant Privacy',
            'slug' => 'model-applicant-privacy',
            'body' => 'privacy-v1',
            'is_published' => true,
            'published_at' => now(),
        ]);

        SitePage::query()->updateOrCreate(['slug' => 'model-adult-eligibility'], [
            'title' => 'Adult Eligibility',
            'slug' => 'model-adult-eligibility',
            'body' => 'adult-v1',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $verifier = User::query()->firstOrCreate([
            'email' => 'model-agency-verifier@example.test',
        ], [
            'name' => 'Model Agency Verifier',
            'password' => Str::random(48),
            'role' => 'verifier',
            'is_active' => true,
        ]);
        $verifier->forceFill([
            'email_verified_at' => now(),
            'mfa_secret' => Str::random(32),
            'mfa_enabled_at' => now(),
        ])->save();
    }
}
