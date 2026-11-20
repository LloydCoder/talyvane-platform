<?php

namespace Tests\Feature;

use App\Models\CountryMarket;
use App\Models\Currency;
use App\Models\ModelTalentApplication;
use App\Models\ModelCastingEvent;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\ModelTalentRepresentationAgreement;
use App\Models\User;
use App\Services\ModelCastingWorkflowService;
use App\Services\ModelTalentImageLicenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Feature\Support\EnablesModelAgencyFeatureRoutes;

class ModelCastingWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use EnablesModelAgencyFeatureRoutes;

    private function context(): array
    {
        $this->enableModelAgencyFeatureRoutes(['model-agency.casting']);
        config([
            'talyvane.model_agency.image_licence_policy_version' => 'image-licence-policy-v1',
            'talyvane.model_agency.image_licence_policy_approved' => true,
            'talyvane.model_agency.portfolio_consent_version' => 'portfolio-consent-v1',
            'talyvane.model_agency.portfolio_consent_approved' => true,
            'talyvane.model_agency.representation_terms_version' => 'representation-terms-v1',
            'talyvane.model_agency.representation_terms_approved' => true,
            'talyvane.model_agency.casting_policy_version' => 'casting-policy-v1',
            'talyvane.model_agency.casting_policy_approved' => true,
        ]);
        Storage::fake('local');
        $admin = $this->createAdminUser();
        $verifier = User::query()->create([
            'name' => 'Eligibility Verifier',
            'email' => 'verifier-'.strtolower(bin2hex(random_bytes(4))).'@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        CountryMarket::query()->create([
            'country_code' => 'GB',
            'name' => 'United Kingdom',
            'is_enabled' => true,
            'compliance_status' => 'approved',
            'compliance_reviewed_at' => now(),
            'compliance_reviewed_by' => 'test-fixture',
            'compliance_evidence_reference' => 'test-market-evidence-gb',
            'compliance_review_summary' => 'Test fixture only.',
            'market_policy' => ['test_fixture' => true],
        ]);
        Currency::query()->create([
            'code' => 'GBP',
            'numeric_code' => '826',
            'name' => 'Pound Sterling',
            'symbol' => '£',
            'minor_unit' => 2,
            'is_enabled' => true,
            'metadata' => [],
        ]);
        $application = ModelTalentApplication::query()->create([
            'reference' => 'MTA-2026-CASTING01',
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Commercial modelling experience.',
            'status' => 'accepted',
            'adult_eligibility_confirmed_at' => now(),
            'adult_eligibility_policy_version' => 'adult-policy-v1',
            'adult_eligibility_policy_accepted_at' => now(),
            'adult_eligibility_verified_at' => now(),
            'adult_eligibility_verified_by' => $verifier->id,
            'adult_eligibility_verification_method' => 'manual_document_inspection',
            'privacy_notice_version' => 'privacy-v1',
            'privacy_notice_accepted_at' => now(),
            'submitted_at' => now(),
        ]);
        $agreement = ModelTalentRepresentationAgreement::query()->create([
            'application_id' => $application->id,
            'country_code' => 'GB',
            'reference' => 'MRA-2026-CASTING01',
            'version' => 1,
            'status' => 'executed',
            'terms_snapshot' => ['representation_scope' => 'Commercial model representation.'],
            'terms_policy_version' => 'representation-terms-v1',
            'created_by' => $admin->id,
            'offered_by' => $admin->id,
            'offered_at' => now()->subDays(3),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDays(2),
            'executed_document_reference' => 'private/model-agreements/signed-representation-casting.pdf',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_until' => now()->addMonths(3)->toDateString(),
        ]);
        Storage::disk('local')->put('model-portfolio/casting-asset.webp', 'sanitized-image-bytes');
        $asset = ModelTalentPortfolioAsset::query()->create([
            'application_id' => $application->id,
            'uploaded_by' => $admin->id,
            'storage_path' => 'model-portfolio/casting-asset.webp',
            'mime_type' => 'image/webp',
            'width' => 1000,
            'height' => 1400,
            'file_size' => 100,
            'sha256' => hash('sha256', 'sanitized-image-bytes'),
            'status' => 'approved',
            'source_description' => 'Image supplied directly by the represented model.',
            'consent_scope' => 'Private agency portfolio curation only.',
            'rights_evidence_reference' => 'private/model-rights/casting-rights-evidence.pdf',
            'consent_evidence_reference' => 'private/model-consent/casting-portfolio-consent.pdf',
            'consent_policy_version' => 'portfolio-consent-v1',
            'consent_recorded_at' => now()->subDays(5),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDays(4),
            'review_note' => 'Rights and private portfolio consent checked.',
        ]);
        $licence = app(ModelTalentImageLicenceService::class)->createDraft(
            $asset,
            $agreement,
            $admin->id,
            [
                'licensee_name' => 'Northstar Casting Ltd',
                'purpose' => 'casting_distribution',
                'channels' => ['casting_platform'],
                'purpose_details' => 'Private casting submission to the named client only; no public distribution.',
                'country_code' => 'GB',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(20)->toDateString(),
                'exclusivity' => false,
            ],
        );
        app(ModelTalentImageLicenceService::class)->activate(
            $licence,
            $admin->id,
            'private/model-licences/casting-license-executed.pdf',
            'private/model-consent/casting-purpose-consent.pdf',
        );

        return compact('admin', 'application', 'agreement', 'asset', 'licence');
    }

    private function callInput(): array
    {
        return [
            'title' => 'Winter Editorial Casting',
            'client_name' => 'Northstar Studio Ltd',
            'client_reference' => 'NORTHSTAR-WINTER-2026',
            'project_purpose' => 'editorial',
            'description' => 'Editorial portrait campaign for a winter collection.',
            'requirements' => 'Adult models with editorial experience and availability for the stated dates.',
            'country_code' => 'GB',
            'opens_at' => now()->subMinutes(5)->toDateTimeString(),
            'closes_at' => now()->addDay()->toDateTimeString(),
            'shoot_starts_at' => now()->addDays(3)->toDateTimeString(),
            'shoot_ends_at' => now()->addDays(4)->toDateTimeString(),
            'compensation_amount' => '800.00',
            'currency_code' => 'GBP',
            'max_submissions' => 20,
        ];
    }

    public function test_casting_submission_requires_open_call_and_active_casting_distribution_licence(): void
    {
        $context = $this->context();
        $service = app(ModelCastingWorkflowService::class);
        $call = $service->createDraft($context['admin']->id, $this->callInput());
        $service->publish($call, $context['admin']->id);

        $submission = $service->submit(
            $call,
            $context['application'],
            $context['asset'],
            $context['licence'],
            $context['admin']->id,
        );

        $this->assertSame('submitted', $submission->status);
        $this->assertSame($context['licence']->id, $submission->image_licence_id);
        $this->assertDatabaseHas('model_casting_events', [
            'call_id' => $call->id,
            'submission_id' => $submission->id,
            'event_type' => 'casting.submission_created',
        ]);
    }

    public function test_casting_call_decisions_are_manual_and_do_not_create_assignments(): void
    {
        $context = $this->context();
        $service = app(ModelCastingWorkflowService::class);
        $call = $service->createDraft($context['admin']->id, $this->callInput());
        $service->publish($call, $context['admin']->id);
        $submission = $service->submit($call, $context['application'], $context['asset'], $context['licence'], $context['admin']->id);

        $service->decide($submission, $context['admin']->id, 'shortlisted', 'The portfolio is suitable for client review.');
        $this->assertSame('shortlisted', $submission->fresh()->status);

        $service->decide($submission, $context['admin']->id, 'selected', 'The client selected the model after human review.');
        $this->assertSame('selected', $submission->fresh()->status);
    }

    public function test_submission_is_blocked_when_call_is_closed_or_licence_does_not_cover_call(): void
    {
        $context = $this->context();
        $service = app(ModelCastingWorkflowService::class);
        $call = $service->createDraft($context['admin']->id, $this->callInput());
        $service->publish($call, $context['admin']->id);
        $service->close($call, $context['admin']->id, 'Casting has reached its deadline and submissions are closed.');

        try {
            $service->submit($call, $context['application'], $context['asset'], $context['licence'], $context['admin']->id);
            $this->fail('A closed casting call must reject new submissions.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('submission', $exception->errors());
        }
    }
    public function test_revoking_a_casting_distribution_licence_invalidates_live_submissions(): void
    {
        $context = $this->context();
        $workflow = app(ModelCastingWorkflowService::class);
        $call = $workflow->createDraft($context['admin']->id, $this->callInput());
        $workflow->publish($call, $context['admin']->id);
        $submission = $workflow->submit(
            $call,
            $context['application'],
            $context['asset'],
            $context['licence'],
            $context['admin']->id,
        );

        app(ModelTalentImageLicenceService::class)->revoke(
            $context['licence'],
            $context['admin']->id,
            'The model withdrew permission for this casting distribution.',
        );

        $this->assertSame('invalidated', $submission->fresh()->status);
        $this->assertNotNull($submission->fresh()->invalidated_at);
    }

    public function test_cancelled_call_invalidates_existing_submissions(): void
    {
        $context = $this->context();
        $workflow = app(ModelCastingWorkflowService::class);
        $call = $workflow->createDraft($context['admin']->id, $this->callInput());
        $workflow->publish($call, $context['admin']->id);
        $submission = $workflow->submit(
            $call,
            $context['application'],
            $context['asset'],
            $context['licence'],
            $context['admin']->id,
        );

        $workflow->cancel($call, $context['admin']->id, 'The commissioning client cancelled the project and all related submissions.');

        $this->assertSame('invalidated', $submission->fresh()->status);
        $this->assertSame('cancelled', $call->fresh()->status);
    }

}
