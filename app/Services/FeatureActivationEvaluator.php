<?php

namespace App\Services;

use App\Models\CountryMarket;
use App\Models\PlatformFeature;
use App\Models\SitePage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FeatureActivationEvaluator
{
    public function __construct(private readonly FeatureRegistry $registry)
    {
    }

    private const RUNTIME_GUARDS = [
        'adult_age_assurance',
        'current_consent',
        'companion_opt_in',
        'availability',
        'deposit_prerequisites',
        'email_verification',
        'verified_account',
        'consent_controls',
    ];

    public function blockers(PlatformFeature $feature): array
    {
        $blockers = [];

        if ($feature->implementation_status !== 'available') {
            $blockers[] = 'This feature has not reached an available implementation state.';
        }

        foreach ($feature->dependencies ?? [] as $dependency) {
            if (! $this->registry->isEnabled((string) $dependency)) {
                $blockers[] = 'Required feature is not effectively enabled: '.$dependency.'.';
            }
        }

        foreach ($feature->activation_requirements ?? [] as $requirement) {
            if (in_array($requirement, self::RUNTIME_GUARDS, true)) {
                continue;
            }

            if ($requirement === 'model_agency_workflow') {
                if (! Schema::hasTable('model_talent_applications') || ! Schema::hasTable('model_talent_application_events')) {
                    $blockers[] = 'The separate model-agency application and audit workflow is not installed.';
                }

                continue;
            }

            if ($requirement === 'applicant_privacy_notice') {
                 $privacyVersion = (string) config('talyvane.model_agency.privacy_notice_version');
                $privacyPage = SitePage::query()->where('slug', 'model-applicant-privacy')->where('is_published', true)->first();

                if (! filled($privacyVersion)
                    || ! config('talyvane.model_agency.privacy_notice_approved')
                    || ! $privacyPage
                    || ! Str::contains((string) $privacyPage->body, $privacyVersion)) {
                    $blockers[] = 'A legally reviewed and versioned model-applicant privacy notice must be configured and explicitly approved.';
                }

                continue;
            }

            if ($requirement === 'adult_eligibility_policy') {
                 $eligibilityVersion = (string) config('talyvane.model_agency.adult_eligibility_policy_version');
                $eligibilityPage = SitePage::query()->where('slug', 'model-adult-eligibility')->where('is_published', true)->first();

                if (! filled($eligibilityVersion)
                    || ! config('talyvane.model_agency.adult_eligibility_policy_approved')
                    || ! $eligibilityPage
                    || ! Str::contains((string) $eligibilityPage->body, $eligibilityVersion)) {
                    $blockers[] = 'A legally reviewed, versioned adult-eligibility policy must be configured and explicitly approved.';
                }

                continue;
            }

            if (in_array($requirement, ['approved_market_policy', 'market_approval'], true)) {
                if (! CountryMarket::query()->operationallyApproved()->exists()) {
                    $blockers[] = 'At least one market must have a documented, operational compliance approval.';
                }

                continue;
            }

            $blockers[] = 'Activation prerequisite is not satisfied or has no verified evaluator: '.$requirement.'.';
        }

        return array_values(array_unique($blockers));
    }

    public function impactSummary(PlatformFeature $feature): array
    {
        $surfaces = match ($feature->key) {
            'public.discovery' => ['Destination and companion discovery', 'Public profile and approved-media delivery', 'New booking entry points'],
            'bookings.companionship' => ['Client booking creation and cancellation', 'Companion booking controls', 'Agency booking operations'],
            'bookings.automatic' => ['Automatic confirmation path', 'Companion automatic-booking opt-in controls'],
            'portals.client' => ['Client sign-in experience', 'Client shortlist and booking history'],
            'portals.companion' => ['Companion sign-in experience', 'Companion availability, consent and booking controls'],
            'cms.pages' => ['Public deployment-managed pages'],
            'memberships' => ['Client membership display and agency assignment'],
            'model-agency.applications' => ['Public adult model-agency application intake', 'Restricted agency review workspace', 'Append-only application status history'],
            'payments.configuration' => ['Payment method configuration and payment-purpose controls'],
            default => [$feature->module.' module surfaces'],
        };

        $dependents = PlatformFeature::query()->get(['key', 'name', 'dependencies'])
            ->filter(fn (PlatformFeature $candidate): bool => in_array($feature->key, $candidate->dependencies ?? [], true))
            ->pluck('name')
            ->values()
            ->all();

        if ($dependents !== []) {
            $surfaces[] = 'Dependent features affected: '.implode(', ', $dependents);
        }

        return $surfaces;
    }
}
