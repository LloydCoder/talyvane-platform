<?php

namespace App\Services;

use App\Http\Controllers\ProfileMediaController;
use App\Models\CountryMarket;
use App\Models\ProfileMediaConsentEvent;
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
        'current_consent',
        'companion_opt_in',
        'availability',
        'deposit_prerequisites',
        'email_verification',
        'verified_account',
        'consent_controls',
        'payment_provider_review',
    ];

    public function blockers(PlatformFeature $feature): array
    {
        $blockers = [];

        if ($feature->implementation_status !== 'available') {
            $blockers[] = 'This feature has not reached an available implementation state.';
        }

        foreach ($feature->dependencies ?? [] as $dependency) {
            if (! $this->registry->isOperationallyEnabled((string) $dependency)) {
                $blockers[] = 'Required feature is not effectively enabled: '.$dependency.'.';
            }
        }

        foreach ($feature->activation_requirements ?? [] as $requirement) {
            if ($requirement === 'adult_age_assurance') {
                if (! app(ClientAgeAssuranceService::class)->isOperationallyConfigured()) {
                    $blockers[] = 'Client age assurance is not operational: an approved provider adapter, market-specific age policy, current verification evidence schema and server-side proof validation are required.';
                }

                continue;
            }

            if (in_array($requirement, self::RUNTIME_GUARDS, true)) {
                continue;
            }

            if ($requirement === 'applicant_data_lifecycle') {
                $applicationSchemaReady = Schema::hasTable('model_talent_applications')
                    && Schema::hasColumns('model_talent_applications', ['legal_hold_at', 'reviewed_at', 'submitted_at']);
                $rightsTableReady = Schema::hasTable('model_talent_application_rights_requests')
                    && Schema::hasColumns('model_talent_application_rights_requests', ['application_id', 'request_type', 'status', 'code_hash', 'attempts', 'expires_at', 'verified_at', 'completed_at']);
                $applicationDays = (int) config('talyvane.model_agency.retention.application_days');
                $acceptedDays = (int) config('talyvane.model_agency.retention.accepted_days');
                $retentionApproved = config('talyvane.model_agency.retention.schedule_approved');
                $smtpReady = config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host'));

                if (! $applicationSchemaReady || ! $rightsTableReady || $applicationDays < 1 || $acceptedDays < 1 || ! $retentionApproved || ! $smtpReady) {
                    $blockers[] = 'Applicant rights verification, legal-hold schema, approved retention periods, scheduled retention execution and SMTP delivery must all be configured.';
                }

                continue;
            }

            if ($requirement === 'model_agency_workflow') {
                $schemaReady = Schema::hasTable('model_talent_applications')
                    && Schema::hasTable('model_talent_application_events')
                    && Schema::hasColumns('model_talent_applications', [
                        'reference',
                        'full_name',
                        'email',
                        'adult_eligibility_confirmed_at',
                        'adult_eligibility_policy_version',
                        'privacy_notice_version',
                        'reviewed_at',
                        'legal_hold_at',
                        'submitted_at',
                    ])
                    && Schema::hasColumns('model_talent_application_events', ['application_id', 'actor_type', 'event_type', 'occurred_at']);

                if (! $schemaReady) {
                    $blockers[] = 'The separate model-agency application, legal-hold and audit schema is incomplete.';
                }

                continue;
            }

            if ($requirement === 'model_agency_rights_ledger') {
                $schemaReady = Schema::hasTable('model_talent_representation_agreements')
                    && Schema::hasColumns('model_talent_representation_agreements', ['application_id', 'country_code', 'status', 'terms_snapshot', 'terms_policy_version'])
                    && Schema::hasTable('model_talent_portfolio_assets')
                    && Schema::hasColumns('model_talent_portfolio_assets', ['application_id', 'storage_path', 'status', 'consent_policy_version', 'consent_withdrawn_at'])
                    && Schema::hasTable('model_talent_image_licences')
                    && Schema::hasColumns('model_talent_image_licences', ['application_id', 'portfolio_asset_id', 'representation_agreement_id', 'status', 'purpose', 'channels', 'country_code', 'starts_on', 'ends_on', 'licence_policy_version']);

                if (! $schemaReady) {
                    $blockers[] = 'The representation, private portfolio and image-licence evidence schemas must all be available.';
                }

                continue;
            }

            if ($requirement === 'model_agency_representation_terms') {
                if (! filled(config('talyvane.model_agency.representation_terms_version'))
                    || ! config('talyvane.model_agency.representation_terms_approved')) {
                    $blockers[] = 'A current, jurisdiction-reviewed representation terms version must be configured and explicitly approved.';
                }

                continue;
            }

            if ($requirement === 'model_agency_portfolio_consent') {
                if (! filled(config('talyvane.model_agency.portfolio_consent_version'))
                    || ! config('talyvane.model_agency.portfolio_consent_approved')) {
                    $blockers[] = 'A current, purpose-limited portfolio consent policy must be configured and explicitly approved.';
                }

                continue;
            }

            if ($requirement === 'model_agency_image_licence_policy') {
                if (! filled(config('talyvane.model_agency.image_licence_policy_version'))
                    || ! config('talyvane.model_agency.image_licence_policy_approved')) {
                    $blockers[] = 'A current image usage licence policy must be configured and explicitly approved.';
                }

                continue;
            }

            if ($requirement === 'model_agency_casting_schema') {
                $schemaReady = Schema::hasTable('model_casting_calls')
                    && Schema::hasColumns('model_casting_calls', ['reference', 'status', 'country_code', 'opens_at', 'closes_at', 'shoot_starts_at', 'shoot_ends_at', 'casting_policy_version'])
                    && Schema::hasTable('model_casting_submissions')
                    && Schema::hasColumns('model_casting_submissions', ['call_id', 'application_id', 'portfolio_asset_id', 'image_licence_id', 'status'])
                    && Schema::hasTable('model_casting_events');

                if (! $schemaReady) {
                    $blockers[] = 'The private casting call, submission and audit schemas must all be available.';
                }

                continue;
            }

            if ($requirement === 'model_agency_casting_policy') {
                if (! filled(config('talyvane.model_agency.casting_policy_version'))
                    || ! config('talyvane.model_agency.casting_policy_approved')) {
                    $blockers[] = 'A current, jurisdiction-reviewed casting policy must be configured and explicitly approved.';
                }

                continue;
            }

            if ($requirement === 'model_agency_assignment_schema') {
                $schemaReady = Schema::hasTable('model_talent_assignments')
                    && Schema::hasColumns('model_talent_assignments', [
                        'source_submission_id', 'application_id', 'representation_agreement_id',
                        'status', 'shoot_starts_at', 'shoot_ends_at', 'assignment_policy_version',
                    ])
                    && Schema::hasTable('model_talent_assignment_events');

                if (! $schemaReady) {
                    $blockers[] = 'The model assignment and append-only audit schemas must be available.';
                }

                continue;
            }

            if ($requirement === 'model_agency_assignment_policy') {
                if (! filled(config('talyvane.model_agency.assignment_policy_version'))
                    || ! config('talyvane.model_agency.assignment_policy_approved')) {
                    $blockers[] = 'A current, jurisdiction-reviewed model assignment policy version must be configured and explicitly approved.';
                }

                continue;
            }

            if ($requirement === 'model_agency_settlement_schema') {
                $schemaReady = Schema::hasTable('model_talent_settlements')
                    && Schema::hasColumns('model_talent_settlements', [
                        'assignment_id', 'gross_amount', 'commission_amount',
                        'approved_expenses_amount', 'model_net_amount', 'status',
                        'settlement_policy_version', 'calculation_snapshot',
                    ])
                    && Schema::hasTable('model_talent_settlement_expenses')
                    && Schema::hasTable('model_talent_settlement_events');

                if (! $schemaReady) {
                    $blockers[] = 'The model settlement, expense and append-only audit schemas must be available.';
                }

                continue;
            }

            if ($requirement === 'model_agency_settlement_policy') {
                if (! filled(config('talyvane.model_agency.settlement_policy_version'))
                    || ! config('talyvane.model_agency.settlement_policy_approved')) {
                    $blockers[] = 'A current, jurisdiction-reviewed model settlement policy version must be configured and explicitly approved.';
                }

                continue;
            }

            if ($requirement === 'server_side_entitlement_checks') {
                $schemaReady = Schema::hasTable('client_membership_entitlements')
                    && Schema::hasColumns('client_membership_entitlements', [
                        'membership_id',
                        'entitlement_key',
                        'granted_by',
                        'starts_at',
                        'ends_at',
                    ])
                    && Schema::hasTable('profile_media')
                    && Schema::hasColumns('profile_media', [
                        'access_level',
                        'members_only_consent_at',
                        'members_only_consent_version',
                        'members_only_consent_withdrawn_at',
                        'members_only_consent_withdrawn_by',
                    ])
                    && Schema::hasTable('profile_media_consent_events')
                    && Schema::hasColumns('profile_media_consent_events', [
                        'profile_media_id', 'media_reference', 'actor_id', 'actor_email',
                        'action', 'capture_method', 'consent_policy_version', 'consent_statement', 'statement_sha256', 'correlation_id',
                    ]);

                $runtimeReady = class_exists(MembershipEntitlementService::class)
                    && class_exists(MembershipEntitlementCatalog::class)
                    && class_exists(ProfileMediaController::class)
                    && class_exists(ProfileMediaConsentEvent::class);

                if (! $schemaReady || ! $runtimeReady) {
                    $blockers[] = 'The membership grant schema, entitlement catalogue and protected-media authorization boundary must all be available.';
                }

                continue;
            }

            if ($requirement === 'private_gallery_consent_policy') {
                $agencyName = app(BrandSettings::class)->publicBrand()['brand_name'] ?? null;
                if (
                    ! filled(config('talyvane.privacy.private_gallery_consent_version'))
                    || ! config('talyvane.privacy.private_gallery_consent_approved')
                    || ! filled($agencyName)
                ) {
                    $blockers[] = 'A current, separately worded, versioned companion consent policy, deployment brand identity and explicit approval are required for members-only media.';
                }

                continue;
            }

            if ($requirement === 'adult_eligibility_verification') {
                $verificationSchemaReady = Schema::hasTable('model_talent_applications')
                    && Schema::hasColumns('model_talent_applications', [
                        'adult_eligibility_verified_at',
                        'adult_eligibility_verified_by',
                        'adult_eligibility_verification_method',
                    ]);

                if (! $verificationSchemaReady) {
                    $blockers[] = 'The privacy-minimizing adult-eligibility verification workflow is unavailable.';
                }

                continue;
            }

            if ($requirement === 'applicant_privacy_notice') {
                $privacyVersion = (string) config('talyvane.model_agency.privacy_notice_version');
                $privacyPage = Schema::hasTable('site_pages')
                    ? SitePage::query()->where('slug', 'model-applicant-privacy')->where('is_published', true)->first()
                    : null;

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
                $eligibilityPage = Schema::hasTable('site_pages')
                    ? SitePage::query()->where('slug', 'model-adult-eligibility')->where('is_published', true)->first()
                    : null;

                if (! filled($eligibilityVersion)
                    || ! config('talyvane.model_agency.adult_eligibility_policy_approved')
                    || ! $eligibilityPage
                    || ! Str::contains((string) $eligibilityPage->body, $eligibilityVersion)) {
                    $blockers[] = 'A legally reviewed, versioned adult-eligibility policy must be configured and explicitly approved.';
                }

                continue;
            }

            if (in_array($requirement, ['approved_market_policy', 'market_approval'], true)) {
                if (! Schema::hasTable('country_markets') || ! CountryMarket::query()->operationallyApproved()->exists()) {
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
            'memberships.entitlements' => ['Immutable membership entitlement grants', 'Server-authorized private gallery delivery', 'Membership-aware profile detail projection'],
            'model-agency.applications' => ['Public adult model-agency application intake', 'Restricted agency review workspace', 'Append-only application status history'],
            'model-agency.usage-rights' => ['Representation agreement register', 'Private portfolio media and consent', 'Purpose-bound image usage licence ledger'],
            'model-agency.casting' => ['Private casting briefs', 'Licensed model submissions', 'Manual shortlist and selection controls'],
            'model-agency.assignments' => ['Model assignment offers and acceptance evidence', 'Assignment confirmation and delivery states', 'Agreed compensation metadata (not settlement)'],
            'model-agency.settlements' => ['Expense claim review and evidence', 'Decimal-safe commission and net settlement calculation', 'Approval and external payment evidence register'],
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
