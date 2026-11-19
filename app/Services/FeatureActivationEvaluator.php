<?php

namespace App\Services;

use App\Models\CountryMarket;
use App\Models\PlatformFeature;

class FeatureActivationEvaluator
{
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
            if (! app(FeatureRegistry::class)->isEnabled((string) $dependency)) {
                $blockers[] = 'Required feature is not effectively enabled: '.$dependency.'.';
            }
        }

        foreach ($feature->activation_requirements ?? [] as $requirement) {
            if (in_array($requirement, self::RUNTIME_GUARDS, true)) {
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
