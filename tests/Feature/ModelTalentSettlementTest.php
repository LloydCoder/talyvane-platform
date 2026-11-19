<?php

namespace Tests\Feature;

use App\Models\CountryMarket;
use App\Models\Currency;
use App\Models\ModelCastingCall;
use App\Models\ModelCastingSubmission;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentAssignment;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\ModelTalentRepresentationAgreement;
use App\Models\User;
use App\Services\ModelTalentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModelTalentSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function assignment(string $status = 'completed'): array
    {
        $admin = $this->createAdminUser();
        $verifier = User::query()->create([
            'name' => 'Eligibility Verifier',
            'email' => 'settlement-verifier@example.test',
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
            'reference' => 'MTA-SETTLE-'.strtoupper(bin2hex(random_bytes(3))),
            'full_name' => 'Jordan Example',
            'email' => 'jordan-settlement@example.test',
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
            'reference' => 'MRA-SETTLE-'.strtoupper(bin2hex(random_bytes(3))),
            'version' => 1,
            'status' => 'executed',
            'terms_snapshot' => ['commission_percent' => '15.00'],
            'terms_policy_version' => 'representation-terms-v1',
            'created_by' => $admin->id,
            'offered_by' => $admin->id,
            'offered_at' => now()->subDays(3),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDays(2),
            'executed_document_reference' => 'private/model-agreements/settlement-agreement.pdf',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_until' => now()->addMonths(3)->toDateString(),
        ]);

        $asset = ModelTalentPortfolioAsset::query()->create([
            'application_id' => $application->id,
            'uploaded_by' => $admin->id,
            'storage_path' => 'model-portfolio/settlement.webp',
            'mime_type' => 'image/webp',
            'width' => 1000,
            'height' => 1400,
            'file_size' => 100,
            'sha256' => hash('sha256', 'settlement-image'),
            'status' => 'approved',
            'source_description' => 'Image supplied by the model.',
            'consent_scope' => 'Private casting only.',
            'rights_evidence_reference' => 'private/model-rights/settlement-rights.pdf',
            'consent_evidence_reference' => 'private/model-consent/settlement-consent.pdf',
            'consent_policy_version' => 'portfolio-consent-v1',
            'consent_recorded_at' => now()->subDays(5),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDays(4),
            'review_note' => 'Rights and consent reviewed.',
        ]);

        $licence = ModelTalentImageLicence::query()->create([
            'application_id' => $application->id,
            'portfolio_asset_id' => $asset->id,
            'representation_agreement_id' => $agreement->id,
            'reference' => 'MTL-SETTLE-'.strtoupper(bin2hex(random_bytes(3))),
            'status' => 'active',
            'licensee_name' => 'Northstar Studio Ltd',
            'purpose' => 'casting_distribution',
            'channels' => ['casting_platform'],
            'purpose_details' => 'Private casting distribution only.',
            'country_code' => 'GB',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(20)->toDateString(),
            'exclusivity' => false,
            'licence_policy_version' => 'image-licence-policy-v1',
            'created_by' => $admin->id,
            'activated_by' => $admin->id,
            'activated_at' => now(),
            'execution_evidence_reference' => 'private/model-licences/settlement-licence.pdf',
            'model_consent_evidence_reference' => 'private/model-consent/settlement-licence-consent.pdf',
        ]);

        $call = ModelCastingCall::query()->create([
            'reference' => 'MCC-SETTLE-'.strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Editorial Casting',
            'client_name' => 'Northstar Studio Ltd',
            'project_purpose' => 'editorial',
            'description' => 'Editorial portrait campaign for the winter collection.',
            'requirements' => 'Adult models with editorial experience.',
            'country_code' => 'GB',
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
            'shoot_starts_at' => now()->subDay(),
            'shoot_ends_at' => now()->addDay(),
            'compensation_amount' => '800.0000',
            'currency_code' => 'GBP',
            'max_submissions' => 20,
            'status' => 'closed',
            'casting_policy_version' => 'casting-policy-v1',
            'created_by' => $admin->id,
            'published_by' => $admin->id,
            'published_at' => now()->subDay(),
            'closed_by' => $admin->id,
            'closed_at' => now(),
        ]);

        $submission = ModelCastingSubmission::query()->create([
            'call_id' => $call->id,
            'application_id' => $application->id,
            'portfolio_asset_id' => $asset->id,
            'image_licence_id' => $licence->id,
            'reference' => 'MCS-SETTLE-'.strtoupper(bin2hex(random_bytes(3))),
            'status' => 'selected',
            'submitted_by' => $admin->id,
            'submitted_at' => now()->subDays(2),
            'decision_by' => $admin->id,
            'decision_at' => now()->subDay(),
            'decision_note' => 'Selected after human review.',
        ]);

        $assignment = ModelTalentAssignment::query()->create([
            'reference' => 'MTA-ASSIGN-'.strtoupper(bin2hex(random_bytes(3))),
            'source_submission_id' => $submission->id,
            'application_id' => $application->id,
            'representation_agreement_id' => $agreement->id,
            'country_code' => 'GB',
            'project_purpose' => 'editorial',
            'client_name' => 'Northstar Studio Ltd',
            'brief' => 'Editorial portrait shoot for the winter collection.',
            'location_city' => 'London',
            'shoot_starts_at' => now()->subDays(2),
            'shoot_ends_at' => now()->subDay(),
            'deliverables' => 'Attend one editorial shoot and deliver the agreed services.',
            'compensation_amount' => '800.0000',
            'expense_cap_amount' => '75.0000',
            'currency_code' => 'GBP',
            'commission_percent' => '15.00',
            'assignment_policy_version' => 'assignment-policy-v1',
            'status' => $status,
            'created_by' => $admin->id,
            'offered_by' => $admin->id,
            'offered_at' => now()->subDays(5),
            'acceptance_recorded_by' => $admin->id,
            'accepted_at' => now()->subDays(4),
            'executed_document_reference' => 'private/model-assignments/settlement-assignment.pdf',
            'model_acceptance_evidence_reference' => 'private/model-consent/settlement-acceptance.pdf',
            'confirmed_by' => $admin->id,
            'confirmed_at' => now()->subDays(3),
            'started_by' => $admin->id,
            'started_at' => now()->subDays(2),
            'completed_by' => $admin->id,
            'completed_at' => $status === 'completed' ? now()->subDay() : null,
        ]);

        return compact('admin', 'application', 'agreement', 'asset', 'licence', 'call', 'submission', 'assignment');
    }

    public function test_settlement_snapshots_gross_commission_approved_expenses_and_manual_payment_evidence(): void
    {
        $context = $this->assignment();
        $service = app(ModelTalentSettlementService::class);

        $expenseA = $service->addExpense($context['assignment'], $context['admin']->id, [
            'description' => 'Pre-approved local transport to the shoot location.',
            'amount' => '25.00',
            'currency_code' => 'GBP',
            'evidence_reference' => 'private/model-expenses/transport-receipt-2026.pdf',
        ]);
        $expenseB = $service->addExpense($context['assignment'], $context['admin']->id, [
            'description' => 'Additional documented production expense.',
            'amount' => '50.00',
            'currency_code' => 'GBP',
            'evidence_reference' => 'private/model-expenses/second-expense-receipt.pdf',
        ]);

        $service->reviewExpense($expenseA, $context['admin']->id, 'approved', 'Receipt reviewed and expense approved.');
        $service->reviewExpense($expenseB, $context['admin']->id, 'rejected', 'Expense was not authorized under the assignment terms.');

        $settlement = $service->createDraft($context['assignment'], $context['admin']->id);
        $this->assertSame('800.0000', $settlement->gross_amount);
        $this->assertSame('120.0000', $settlement->commission_amount);
        $this->assertSame('25.0000', $settlement->approved_expenses_amount);
        $this->assertSame('705.0000', $settlement->model_net_amount);

        $service->approve($settlement, $context['admin']->id, 'Settlement calculation and approved expense evidence reviewed.');
        $service->recordPayment(
            $settlement,
            $context['admin']->id,
            'bank_transfer',
            'BANK-TRANSFER-REF-2026-001',
            'private/model-settlements/bank-transfer-proof-2026.pdf',
        );

        $this->assertSame('paid', $settlement->fresh()->status);
        $this->assertSame('bank_transfer', $settlement->fresh()->payment_method);
        $this->assertDatabaseHas('model_talent_settlement_events', [
            'settlement_id' => $settlement->id,
            'event_type' => 'settlement.paid_recorded',
        ]);
    }

    public function test_commission_rounding_is_half_up_to_the_currency_minor_unit(): void
    {
        $context = $this->assignment();
        $context['assignment']->forceFill([
            'compensation_amount' => '100.05',
            'expense_cap_amount' => null,
        ])->save();

        $settlement = app(ModelTalentSettlementService::class)->createDraft(
            $context['assignment'],
            $context['admin']->id,
        );

        $this->assertSame('15.0100', $settlement->commission_amount);
        $this->assertSame('85.0400', $settlement->model_net_amount);
        $this->assertSame('half_up_to_currency_minor_unit', $settlement->calculation_snapshot['rounding']);
    }

    public function test_settlement_cannot_be_created_with_pending_expenses_or_before_assignment_completion(): void
    {
        $context = $this->assignment();
        $service = app(ModelTalentSettlementService::class);
        $expense = $service->addExpense($context['assignment'], $context['admin']->id, [
            'description' => 'Transport expense awaiting agency review.',
            'amount' => '25.00',
            'currency_code' => 'GBP',
            'evidence_reference' => 'private/model-expenses/pending-transport-receipt.pdf',
        ]);

        try {
            $service->createDraft($context['assignment'], $context['admin']->id);
            $this->fail('Pending expenses must be reviewed before settlement calculation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement', $exception->errors());
        }

        $service->reviewExpense($expense, $context['admin']->id, 'approved', 'Expense reviewed.');
        $inProgress = $this->assignment('in_progress');

        try {
            $service->createDraft($inProgress['assignment'], $inProgress['admin']->id);
            $this->fail('An assignment must be completed before settlement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement', $exception->errors());
        }
    }

    public function test_expenses_cannot_exceed_assignment_cap_or_currency_precision(): void
    {
        $context = $this->assignment();
        $service = app(ModelTalentSettlementService::class);

        try {
            $service->addExpense($context['assignment'], $context['admin']->id, [
                'description' => 'Expense exceeds the agreed cap.',
                'amount' => '80.00',
                'currency_code' => 'GBP',
                'evidence_reference' => 'private/model-expenses/over-cap-receipt.pdf',
            ]);
            $this->fail('Expenses above the assignment cap must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expense', $exception->errors());
        }

        try {
            $service->addExpense($context['assignment'], $context['admin']->id, [
                'description' => 'Expense exceeds the ISO currency minor-unit precision.',
                'amount' => '1.001',
                'currency_code' => 'GBP',
                'evidence_reference' => 'private/model-expenses/precision-receipt.pdf',
            ]);
            $this->fail('GBP expenses with more than two decimal places must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expense', $exception->errors());
        }
    }

    public function test_expense_and_settlement_financial_snapshots_cannot_be_mutated_after_creation(): void
    {
        $context = $this->assignment();
        $service = app(ModelTalentSettlementService::class);
        $expense = $service->addExpense($context['assignment'], $context['admin']->id, [
            'description' => 'Transport expense with private receipt evidence.',
            'amount' => '25.00',
            'currency_code' => 'GBP',
            'evidence_reference' => 'private/model-expenses/immutable-expense-receipt.pdf',
        ]);

        try {
            $expense->update(['amount' => '1.00']);
            $this->fail('Submitted expense amounts must be immutable.');
        } catch (\\RuntimeException $exception) {
            $this->assertSame('Expense claim terms and evidence are immutable after submission.', $exception->getMessage());
        }

        $service->reviewExpense($expense, $context['admin']->id, 'approved', 'Receipt and amount verified for reimbursement.');
        $settlement = $service->createDraft($context['assignment'], $context['admin']->id);

        try {
            $settlement->update(['gross_amount' => '1.0000']);
            $this->fail('Settlement financial snapshots must be immutable.');
        } catch (\\RuntimeException $exception) {
            $this->assertSame('Settlement financial snapshots are immutable; use a governed state transition.', $exception->getMessage());
        }
    }

    public function test_paid_settlement_can_be_reversed_only_with_private_evidence_and_keeps_event_history(): void
    {
        $context = $this->assignment();
        $service = app(ModelTalentSettlementService::class);
        $settlement = $service->createDraft($context['assignment'], $context['admin']->id);
        $service->approve($settlement, $context['admin']->id, 'Settlement approved after reconciliation.');

        try {
            $service->reverse($settlement, $context['admin']->id, 'Duplicate payment was reversed by the bank.', 'public/reversal.pdf');
            $this->fail('Reversal evidence must use private storage.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
        }

        $service->recordPayment(
            $settlement,
            $context['admin']->id,
            'bank_transfer',
            'BANK-TRANSFER-REF-2026-002',
            'private/model-settlements/settlement-payment-proof.pdf',
        );
        $service->reverse(
            $settlement,
            $context['admin']->id,
            'The duplicate transfer was returned and reconciled.',
            'private/model-settlements/reversal-proof-2026.pdf',
        );

        $this->assertSame('reversed', $settlement->fresh()->status);
        $this->assertDatabaseHas('model_talent_settlement_events', [
            'settlement_id' => $settlement->id,
            'event_type' => 'settlement.reversed',
        ]);
    }
}
