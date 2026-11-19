<?php

namespace Tests\Feature;

use App\Models\CountryMarket;
use App\Models\Currency;
use App\Models\ModelCastingCall;
use App\Models\ModelCastingSubmission;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\ModelTalentRepresentationAgreement;
use App\Models\User;
use App\Services\ModelCastingWorkflowService;
use App\Services\ModelTalentAssignmentService;
use App\Services\ModelTalentImageLicenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModelTalentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $decision = 'selected'): array
    {
        config([
            'talyvane.model_agency.image_licence_policy_version' => 'image-licence-policy-v1',
            'talyvane.model_agency.image_licence_policy_approved' => true,
            'talyvane.model_agency.portfolio_consent_version' => 'portfolio-consent-v1',
            'talyvane.model_agency.portfolio_consent_approved' => true,
            'talyvane.model_agency.representation_terms_version' => 'representation-terms-v1',
            'talyvane.model_agency.representation_terms_approved' => true,
            'talyvane.model_agency.casting_policy_version' => 'casting-policy-v1',
            'talyvane.model_agency.casting_policy_approved' => true,
            'talyvane.model_agency.assignment_policy_version' => 'assignment-policy-v1',
            'talyvane.model_agency.assignment_policy_approved' => true,
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

        $market = CountryMarket::query()->create([
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
            'reference' => 'MTA-2026-ASSIGN'.strtoupper(bin2hex(random_bytes(2))),
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Commercial and editorial modelling experience.',
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
            'reference' => 'MRA-2026-ASSIGN'.strtoupper(bin2hex(random_bytes(2))),
            'version' => 1,
            'status' => 'executed',
            'terms_snapshot' => [
                'representation_scope' => 'Commercial and editorial model representation.',
                'commission_percent' => '15.00',
            ],
            'terms_policy_version' => 'representation-terms-v1',
            'created_by' => $admin->id,
            'offered_by' => $admin->id,
            'offered_at' => now()->subDays(3),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDays(2),
            'executed_document_reference' => 'private/model-agreements/signed-representation-assignment.pdf',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_until' => now()->addMonths(3)->toDateString(),
        ]);

        Storage::disk('local')->put('model-portfolio/assignment-asset.webp', 'sanitized-image-bytes');
        $asset = ModelTalentPortfolioAsset::query()->create([
            'application_id' => $application->id,
            'uploaded_by' => $admin->id,
            'storage_path' => 'model-portfolio/assignment-asset.webp',
            'mime_type' => 'image/webp',
            'width' => 1000,
            'height' => 1400,
            'file_size' => 100,
            'sha256' => hash('sha256', 'sanitized-image-bytes'),
            'status' => 'approved',
            'source_description' => 'Image supplied directly by the represented model.',
            'consent_scope' => 'Private agency portfolio curation only.',
            'rights_evidence_reference' => 'private/model-rights/assignment-rights-evidence.pdf',
            'consent_evidence_reference' => 'private/model-consent/assignment-portfolio-consent.pdf',
            'consent_policy_version' => 'portfolio-consent-v1',
            'consent_recorded_at' => now()->subDays(5),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDays(4),
            'review_note' => 'Rights and private portfolio consent checked.',
        ]);

        $licenceService = app(ModelTalentImageLicenceService::class);
        $licence = $licenceService->createDraft($asset, $agreement, $admin->id, [
            'licensee_name' => 'Northstar Casting Ltd',
            'purpose' => 'casting_distribution',
            'channels' => ['casting_platform'],
            'purpose_details' => 'Private casting submission only; no public distribution.',
            'country_code' => 'GB',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(20)->toDateString(),
            'exclusivity' => false,
        ]);
        $licenceService->activate(
            $licence,
            $admin->id,
            'private/model-licences/assignment-casting-licence.pdf',
            'private/model-consent/assignment-casting-consent.pdf',
        );

        $callService = app(ModelCastingWorkflowService::class);
        $call = $callService->createDraft($admin->id, [
            'title' => 'Winter Editorial Casting',
            'client_name' => 'Northstar Studio Ltd',
            'project_purpose' => 'editorial',
            'description' => 'Editorial portrait campaign for a winter collection.',
            'requirements' => 'Adult models with editorial experience and availability.',
            'country_code' => 'GB',
            'opens_at' => now()->subMinutes(5)->toDateTimeString(),
            'closes_at' => now()->addDay()->toDateTimeString(),
            'shoot_starts_at' => now()->addDays(3)->toDateTimeString(),
            'shoot_ends_at' => now()->addDays(4)->toDateTimeString(),
            'compensation_amount' => '800.00',
            'currency_code' => 'GBP',
            'max_submissions' => 20,
        ]);
        $callService->publish($call, $admin->id);
        $submission = $callService->submit($call, $application, $asset, $licence, $admin->id);
        $callService->decide($submission, $admin->id, $decision, 'Human agency decision recorded after review.');

        return compact('admin', 'application', 'agreement', 'asset', 'licence', 'call', 'submission', 'market');
    }

    private function assignmentTerms(): array
    {
        return [
            'project_purpose' => 'editorial',
            'client_name' => 'Northstar Studio Ltd',
            'client_reference' => 'NORTHSTAR-WINTER-ASSIGNMENT',
            'brief' => 'Editorial portrait shoot for the winter collection.',
            'location_city' => 'London',
            'shoot_starts_at' => now()->addDays(3)->toDateTimeString(),
            'shoot_ends_at' => now()->addDays(4)->toDateTimeString(),
            'deliverables' => 'Attend one editorial shoot and provide the agreed professional services.',
            'compensation_amount' => '800.00',
            'currency_code' => 'GBP',
            'expense_cap_amount' => '75.00',
        ];
    }

    public function test_selected_model_assignment_requires_external_contract_and_model_acceptance_before_confirmation(): void
    {
        $context = $this->context();
        $service = app(ModelTalentAssignmentService::class);
        $assignment = $service->createDraft($context['submission'], $context['admin']->id, $this->assignmentTerms());

        $this->assertSame('draft', $assignment->status);
        $this->assertSame('15.00', $assignment->commission_percent);

        $service->offer($assignment, $context['admin']->id);
        $this->assertSame('offered', $assignment->fresh()->status);

        $service->recordAcceptance(
            $assignment,
            $context['admin']->id,
            'private/model-assignments/signed-assignment-contract.pdf',
            'private/model-consent/assignment-acceptance-evidence.pdf',
        );
        $this->assertSame('accepted', $assignment->fresh()->status);

        $service->confirm($assignment, $context['admin']->id);
        $this->assertSame('confirmed', $assignment->fresh()->status);

        $service->start($assignment, $context['admin']->id);
        $this->assertSame('in_progress', $assignment->fresh()->status);

        $service->complete($assignment, $context['admin']->id, 'The agreed modelling services were completed and recorded by the agency.');
        $this->assertSame('completed', $assignment->fresh()->status);

        $this->assertDatabaseHas('model_talent_assignment_events', [
            'assignment_id' => $assignment->id,
            'event_type' => 'assignment.completed',
        ]);
    }

    public function test_assignment_cannot_be_created_from_unselected_or_ineligible_casting_submission(): void
    {
        $context = $this->context('shortlisted');

        try {
            app(ModelTalentAssignmentService::class)->createDraft(
                $context['submission'],
                $context['admin']->id,
                $this->assignmentTerms(),
            );
            $this->fail('A shortlisted but not selected model cannot receive an assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }

        $context['application']->forceFill(['legal_hold_at' => now()])->save();
        $context['submission']->forceFill(['status' => 'selected'])->save();

        try {
            app(ModelTalentAssignmentService::class)->createDraft(
                $context['submission'],
                $context['admin']->id,
                $this->assignmentTerms(),
            );
            $this->fail('A model under legal hold cannot receive a new assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }
    }

    public function test_assignment_rejects_invalid_external_evidence_and_cancelled_assignments_cannot_be_confirmed(): void
    {
        $context = $this->context();
        $service = app(ModelTalentAssignmentService::class);
        $assignment = $service->createDraft($context['submission'], $context['admin']->id, $this->assignmentTerms());
        $service->offer($assignment, $context['admin']->id);

        try {
            $service->recordAcceptance($assignment, $context['admin']->id, 'public/signed.pdf', 'private/model-consent/assignment-acceptance-evidence.pdf');
            $this->fail('Public or non-private contract evidence must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
        }

        $service->cancel($assignment, $context['admin']->id, 'The client cancelled the commission before the model accepted it.');
        $this->assertSame('cancelled', $assignment->fresh()->status);

        try {
            $service->confirm($assignment, $context['admin']->id);
            $this->fail('A cancelled assignment cannot be confirmed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }
    }

    public function test_assignment_rejects_commission_precision_that_would_require_rounding(): void
    {
        $context = $this->context();
        $context['agreement']->forceFill([
            'terms_snapshot' => [
                'representation_scope' => 'Commercial and editorial model representation.',
                'commission_percent' => '15.555',
            ],
        ])->save();

        try {
            app(ModelTalentAssignmentService::class)->createDraft(
                $context['submission'],
                $context['admin']->id,
                $this->assignmentTerms(),
            );
            $this->fail('Commission percentages with unsupported precision must not be rounded silently.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }
    }

    public function test_assignment_activation_and_offer_fail_closed_when_policy_approval_is_withdrawn(): void
    {
        $context = $this->context();
        config(['talyvane.model_agency.assignment_policy_approved' => false]);

        try {
            app(ModelTalentAssignmentService::class)->createDraft(
                $context['submission'],
                $context['admin']->id,
                $this->assignmentTerms(),
            );
            $this->fail('Assignment creation must fail closed when the current policy is not approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }
    }

    public function test_model_cannot_receive_overlapping_active_assignments(): void
    {
        $context = $this->context();
        $service = app(ModelTalentAssignmentService::class);
        $first = $service->createDraft($context['submission'], $context['admin']->id, $this->assignmentTerms());
        $service->offer($first, $context['admin']->id);
        $service->recordAcceptance(
            $first,
            $context['admin']->id,
            'private/model-assignments/first-signed-assignment.pdf',
            'private/model-consent/first-assignment-acceptance.pdf',
        );
        $service->confirm($first, $context['admin']->id);

        $callService = app(ModelCastingWorkflowService::class);
        $secondCall = $callService->createDraft($context['admin']->id, [
            'title' => 'Second Editorial Casting',
            'client_name' => 'Northstar Studio Ltd',
            'project_purpose' => 'editorial',
            'description' => 'Second editorial portrait commission in the same date window.',
            'requirements' => 'Adult models with editorial experience and availability.',
            'country_code' => 'GB',
            'opens_at' => now()->subMinutes(5)->toDateTimeString(),
            'closes_at' => now()->addDay()->toDateTimeString(),
            'shoot_starts_at' => now()->addDays(3)->toDateTimeString(),
            'shoot_ends_at' => now()->addDays(4)->toDateTimeString(),
            'compensation_amount' => '800.00',
            'currency_code' => 'GBP',
            'max_submissions' => 20,
        ]);
        $callService->publish($secondCall, $context['admin']->id);
        $secondSubmission = $callService->submit(
            $secondCall,
            $context['application'],
            $context['asset'],
            $context['licence'],
            $context['admin']->id,
        );
        $callService->decide($secondSubmission, $context['admin']->id, 'selected', 'A second human selection for overlap validation.');

        try {
            $service->createDraft($secondSubmission, $context['admin']->id, $this->assignmentTerms());
            $this->fail('An overlapping active assignment for the same model must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }
    }

    public function test_assignment_event_ledger_rejects_model_level_mutation(): void
    {
        $context = $this->context();
        $assignment = app(ModelTalentAssignmentService::class)->createDraft(
            $context['submission'],
            $context['admin']->id,
            $this->assignmentTerms(),
        );
        $event = $assignment->events()->firstOrFail();

        try {
            $event->update(['reason' => 'Tampered assignment event.']);
            $this->fail('Assignment event updates must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Model assignment audit events are append-only.', $exception->getMessage());
        }
    }
}
