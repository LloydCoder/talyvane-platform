<?php

namespace App\Services;

use App\Models\CountryMarket;
use App\Models\Currency;
use App\Models\ModelCastingCall;
use App\Models\ModelCastingSubmission;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentAssignment;
use App\Models\ModelTalentAssignmentEvent;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\ModelTalentRepresentationAgreement;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ModelTalentAssignmentService
{
    public function createDraft(ModelCastingSubmission $submission, int $actorId, array $terms): ModelTalentAssignment
    {
        $this->assertTerms($terms);

        return DB::transaction(function () use ($submission, $actorId, $terms): ModelTalentAssignment {
            $current = ModelCastingSubmission::query()->findOrFail($submission->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($current->application_id);
            $call = ModelCastingCall::query()->lockForUpdate()->findOrFail($current->call_id);
            $lockedSubmission = ModelCastingSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $licence = ModelTalentImageLicence::query()->lockForUpdate()->findOrFail($lockedSubmission->image_licence_id);
            $asset = ModelTalentPortfolioAsset::query()->lockForUpdate()->findOrFail($lockedSubmission->portfolio_asset_id);
            $agreement = ModelTalentRepresentationAgreement::query()->lockForUpdate()->findOrFail($licence->representation_agreement_id);

            $commission = $this->assertEligible($lockedSubmission, $application, $call, $licence, $asset, $agreement, $terms);
            $this->assertNoScheduleConflict($application->id, $terms['shoot_starts_at'], $terms['shoot_ends_at']);

            $amount = $terms['compensation_amount'] ?? null;
            $currency = $terms['currency_code'] ?? null;
            if (($amount === null) !== ($currency === null)) {
                throw ValidationException::withMessages(['assignment' => 'Compensation amount and currency must be supplied together.']);
            }
            if ($amount !== null) {
                $enabledCurrency = Currency::query()->where('code', $currency)->lockForUpdate()->first();
                if (! $enabledCurrency || ! $enabledCurrency->is_enabled || (float) $amount <= 0) {
                    throw ValidationException::withMessages(['assignment' => 'Choose an enabled currency and positive agreed compensation.']);
                }
            }

            $expenseCap = $terms['expense_cap_amount'] ?? null;
            if ($expenseCap !== null && (! is_numeric($expenseCap) || (float) $expenseCap < 0 || $currency === null)) {
                throw ValidationException::withMessages(['assignment' => 'An expense cap must be non-negative and use the assignment compensation currency.']);
            }

            $assignment = ModelTalentAssignment::query()->create([
                'reference' => 'MTA-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
                'source_submission_id' => $lockedSubmission->id,
                'application_id' => $application->id,
                'representation_agreement_id' => $agreement->id,
                'country_code' => $call->country_code,
                'project_purpose' => $terms['project_purpose'],
                'client_name' => trim($terms['client_name']),
                'client_reference' => isset($terms['client_reference']) ? trim($terms['client_reference']) : null,
                'brief' => trim($terms['brief']),
                'location_city' => isset($terms['location_city']) ? trim($terms['location_city']) : null,
                'shoot_starts_at' => $terms['shoot_starts_at'],
                'shoot_ends_at' => $terms['shoot_ends_at'],
                'deliverables' => trim($terms['deliverables']),
                'compensation_amount' => $amount,
                'expense_cap_amount' => $expenseCap,
                'currency_code' => $currency,
                'commission_percent' => $commission,
                'assignment_policy_version' => $this->approvedPolicyVersion(),
                'status' => 'draft',
                'created_by' => $actorId,
            ]);

            $this->event($assignment, $actorId, 'assignment.drafted', 'Created a draft model assignment from a human-selected casting submission.');

            return $assignment;
        });
    }

    public function offer(ModelTalentAssignment $assignment, int $actorId): void
    {
        DB::transaction(function () use ($assignment, $actorId): void {
            $locked = $this->lockAssignmentContext($assignment);
            $this->assertCurrentEligibility($locked);

            if ($locked['assignment']->status !== 'draft') {
                throw ValidationException::withMessages(['assignment' => 'Only a draft assignment can be offered.']);
            }

            $this->assertNoScheduleConflict(
                $locked['application']->id,
                $locked['assignment']->shoot_starts_at,
                $locked['assignment']->shoot_ends_at,
                $locked['assignment']->id,
            );

            $locked['assignment']->forceFill([
                'status' => 'offered',
                'offered_by' => $actorId,
                'offered_at' => now(),
            ])->save();

            $this->event($locked['assignment'], $actorId, 'assignment.offered', 'Recorded an external model assignment offer. No automated notice or electronic signature was issued.');
        });
    }

    public function recordAcceptance(
        ModelTalentAssignment $assignment,
        int $actorId,
        string $executedDocumentReference,
        string $modelAcceptanceEvidenceReference,
    ): void {
        $this->assertEvidenceReference($executedDocumentReference, 'private/model-assignments/');
        $this->assertEvidenceReference($modelAcceptanceEvidenceReference, 'private/model-consent/');

        DB::transaction(function () use ($assignment, $actorId, $executedDocumentReference, $modelAcceptanceEvidenceReference): void {
            $locked = $this->lockAssignmentContext($assignment);
            $this->assertCurrentEligibility($locked);

            if ($locked['assignment']->status !== 'offered') {
                throw ValidationException::withMessages(['assignment' => 'Only an outstanding assignment offer can record model acceptance.']);
            }

            $locked['assignment']->forceFill([
                'status' => 'accepted',
                'acceptance_recorded_by' => $actorId,
                'accepted_at' => now(),
                'executed_document_reference' => $executedDocumentReference,
                'model_acceptance_evidence_reference' => $modelAcceptanceEvidenceReference,
            ])->save();

            $this->event($locked['assignment'], $actorId, 'assignment.acceptance_recorded', 'Recorded external assignment execution and model acceptance evidence. The platform did not sign or independently validate either instrument.');
        });
    }

    public function confirm(ModelTalentAssignment $assignment, int $actorId): void
    {
        DB::transaction(function () use ($assignment, $actorId): void {
            $locked = $this->lockAssignmentContext($assignment);
            $this->assertCurrentEligibility($locked);

            if ($locked['assignment']->status !== 'accepted'
                || ! $locked['assignment']->executed_document_reference
                || ! $locked['assignment']->model_acceptance_evidence_reference) {
                throw ValidationException::withMessages(['assignment' => 'Only an externally executed and model-accepted assignment can be confirmed.']);
            }

            $this->assertNoScheduleConflict(
                $locked['application']->id,
                $locked['assignment']->shoot_starts_at,
                $locked['assignment']->shoot_ends_at,
                $locked['assignment']->id,
            );

            $locked['assignment']->forceFill([
                'status' => 'confirmed',
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
            ])->save();

            $this->event($locked['assignment'], $actorId, 'assignment.confirmed', 'Confirmed the model assignment after rechecking eligibility, agreement, market and schedule.');
        });
    }

    public function start(ModelTalentAssignment $assignment, int $actorId): void
    {
        DB::transaction(function () use ($assignment, $actorId): void {
            $locked = $this->lockAssignmentContext($assignment);
            $this->assertCurrentEligibility($locked);

            if ($locked['assignment']->status !== 'confirmed') {
                throw ValidationException::withMessages(['assignment' => 'Only a confirmed assignment can start.']);
            }

            $locked['assignment']->forceFill([
                'status' => 'in_progress',
                'started_by' => $actorId,
                'started_at' => now(),
            ])->save();

            $this->event($locked['assignment'], $actorId, 'assignment.started', 'Recorded the start of the model assignment.');
        });
    }

    public function complete(ModelTalentAssignment $assignment, int $actorId, string $completionNote): void
    {
        DB::transaction(function () use ($assignment, $actorId, $completionNote): void {
            $locked = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($locked->status !== 'in_progress') {
                throw ValidationException::withMessages(['assignment' => 'Only an in-progress assignment can be completed.']);
            }

            $locked->forceFill([
                'status' => 'completed',
                'completed_by' => $actorId,
                'completed_at' => now(),
            ])->save();

            $this->event($locked, $actorId, 'assignment.completed', $completionNote);
        });
    }

    public function decline(ModelTalentAssignment $assignment, int $actorId, string $reason, string $evidenceReference): void
    {
        $this->assertEvidenceReference($evidenceReference, 'private/model-consent/');

        DB::transaction(function () use ($assignment, $actorId, $reason, $evidenceReference): void {
            $locked = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($locked->status !== 'offered') {
                throw ValidationException::withMessages(['assignment' => 'Only an outstanding offer can be declined.']);
            }

            $locked->forceFill([
                'status' => 'declined',
                'declined_by' => $actorId,
                'declined_at' => now(),
                'model_decline_evidence_reference' => $evidenceReference,
                'decline_reason' => $reason,
            ])->save();

            $this->event($locked, $actorId, 'assignment.declined', $reason);
        });
    }

    public function cancel(ModelTalentAssignment $assignment, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($assignment, $actorId, $reason): void {
            $locked = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if (! in_array($locked->status, ['draft', 'offered', 'accepted', 'confirmed', 'in_progress'], true)) {
                throw ValidationException::withMessages(['assignment' => 'This assignment can no longer be cancelled.']);
            }

            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->event($locked, $actorId, 'assignment.cancelled', $reason);
        });
    }

    public function dispute(ModelTalentAssignment $assignment, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($assignment, $actorId, $reason): void {
            $locked = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if (! in_array($locked->status, ['accepted', 'confirmed', 'in_progress', 'completed'], true)) {
                throw ValidationException::withMessages(['assignment' => 'Only an accepted or performed assignment can be disputed.']);
            }

            $locked->forceFill([
                'status' => 'disputed',
                'disputed_by' => $actorId,
                'disputed_at' => now(),
                'dispute_reason' => $reason,
            ])->save();

            $this->event($locked, $actorId, 'assignment.disputed', $reason);
        });
    }

    private function lockAssignmentContext(ModelTalentAssignment $assignment): array
    {
        $current = ModelTalentAssignment::query()->findOrFail($assignment->id);
        $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($current->application_id);
        $submission = ModelCastingSubmission::query()->lockForUpdate()->findOrFail($current->source_submission_id);
        $call = ModelCastingCall::query()->lockForUpdate()->findOrFail($submission->call_id);
        $locked = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
        $licence = ModelTalentImageLicence::query()->lockForUpdate()->findOrFail($submission->image_licence_id);
        $asset = ModelTalentPortfolioAsset::query()->lockForUpdate()->findOrFail($submission->portfolio_asset_id);
        $agreement = ModelTalentRepresentationAgreement::query()->lockForUpdate()->findOrFail($locked->representation_agreement_id);

        return compact('locked', 'application', 'submission', 'call', 'licence', 'asset', 'agreement')
            + ['assignment' => $locked];
    }

    private function assertCurrentEligibility(array $context): void
    {
        $this->assertEligible(
            $context['submission'],
            $context['application'],
            $context['call'],
            $context['licence'],
            $context['asset'],
            $context['agreement'],
            [
                'shoot_starts_at' => $context['assignment']->shoot_starts_at,
                'shoot_ends_at' => $context['assignment']->shoot_ends_at,
            ],
        );

        if ($context['assignment']->assignment_policy_version !== $this->approvedPolicyVersion()) {
            throw ValidationException::withMessages(['assignment' => 'This assignment was created under an outdated assignment policy version.']);
        }
    }

    private function assertEligible(
        ModelCastingSubmission $submission,
        ModelTalentApplication $application,
        ModelCastingCall $call,
        ModelTalentImageLicence $licence,
        ModelTalentPortfolioAsset $asset,
        ModelTalentRepresentationAgreement $agreement,
        array $terms,
    ): string {
        $start = $terms['shoot_starts_at'] instanceof \DateTimeInterface
            ? $terms['shoot_starts_at']->format('Y-m-d H:i:s')
            : (string) $terms['shoot_starts_at'];
        $end = $terms['shoot_ends_at'] instanceof \DateTimeInterface
            ? $terms['shoot_ends_at']->format('Y-m-d H:i:s')
            : (string) $terms['shoot_ends_at'];

        if ($submission->status !== 'selected'
            || $call->status === 'cancelled'
            || $application->status !== 'accepted'
            || $application->adult_eligibility_verified_at === null
            || $application->legal_hold_at !== null
            || $asset->application_id !== $application->id
            || $asset->status !== 'approved'
            || $asset->consent_withdrawn_at !== null
            || ! $asset->storage_path
            || ! Storage::disk('local')->exists($asset->storage_path)
            || $licence->status !== 'active'
            || $licence->purpose !== 'casting_distribution'
            || ! in_array('casting_platform', $licence->channels ?? [], true)
            || $licence->application_id !== $application->id
            || $licence->portfolio_asset_id !== $asset->id
            || $licence->country_code !== $call->country_code
            || $licence->starts_on->format('Y-m-d') > $call->shoot_starts_at->format('Y-m-d')
            || $licence->ends_on->format('Y-m-d') < $call->shoot_ends_at->format('Y-m-d')
            || $asset->consent_policy_version !== (string) config('talyvane.model_agency.portfolio_consent_version')
            || ! config('talyvane.model_agency.portfolio_consent_approved')
            || ! config('talyvane.model_agency.image_licence_policy_approved')
            || $licence->licence_policy_version !== (string) config('talyvane.model_agency.image_licence_policy_version')
            || $licence->model_consent_evidence_reference === null
            || ! config('talyvane.model_agency.representation_terms_approved')
            || $agreement->application_id !== $application->id
            || $agreement->status !== 'executed'
            || $agreement->country_code !== $call->country_code
            || $agreement->terms_policy_version !== (string) config('talyvane.model_agency.representation_terms_version')
            || ! $agreement->effective_from
            || $agreement->effective_from->format('Y-m-d') > substr($start, 0, 10)
            || ($agreement->effective_until && $agreement->effective_until->format('Y-m-d') < substr($end, 0, 10))
            || $call->country_code !== $licence->country_code
            || $call->project_purpose !== ($terms['project_purpose'] ?? $call->project_purpose)
            || $call->shoot_starts_at->format('Y-m-d H:i:s') > $start
            || $call->shoot_ends_at->format('Y-m-d H:i:s') < $end
            || (isset($terms['client_name']) && mb_strtolower(trim((string) $terms['client_name'])) !== mb_strtolower((string) $call->client_name))
            || ! config('talyvane.model_agency.casting_policy_approved')
            || $call->casting_policy_version !== (string) config('talyvane.model_agency.casting_policy_version')) {
            throw ValidationException::withMessages(['assignment' => 'An assignment requires a selected, verified model, current consent, active casting licence, executed representation agreement and valid market.']);
        }

        $market = CountryMarket::query()->where('country_code', $call->country_code)->lockForUpdate()->first();
        if (! $market || ! $market->isOperationallyApproved()) {
            throw ValidationException::withMessages(['assignment' => 'The assignment territory must remain enabled and compliance-approved.']);
        }

        $snapshot = $agreement->terms_snapshot ?? [];
        $commission = $snapshot['commission_percent'] ?? null;
        try {
            $commissionMoney = Money::fromMajor((string) $commission, 'USD', 2);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['assignment' => 'The executed representation agreement must contain a commission percentage with no more than two decimal places.']);
        }

        if ($commissionMoney->minorUnits() < 0 || $commissionMoney->minorUnits() > 10000) {
            throw ValidationException::withMessages(['assignment' => 'The executed representation agreement commission percentage must be between 0 and 100%.']);
        }

        return $commissionMoney->toMajor();
    }

    private function assertNoScheduleConflict(int $applicationId, mixed $start, mixed $end, ?int $ignoreId = null): void
    {
        $startValue = $start instanceof \DateTimeInterface ? $start->format('Y-m-d H:i:s') : (string) $start;
        $endValue = $end instanceof \DateTimeInterface ? $end->format('Y-m-d H:i:s') : (string) $end;

        $query = ModelTalentAssignment::query()
            ->where('application_id', $applicationId)
            ->whereIn('status', ['offered', 'accepted', 'confirmed', 'in_progress'])
            ->where('shoot_starts_at', '<', $endValue)
            ->where('shoot_ends_at', '>', $startValue);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['assignment' => 'The model already has an overlapping active assignment.']);
        }
    }

    private function assertTerms(array $terms): void
    {
        $purposes = ['editorial', 'commercial_campaign', 'e_commerce', 'social_media', 'advertising', 'other'];
        if (! in_array($terms['project_purpose'] ?? null, $purposes, true)
            || ! filled($terms['client_name'] ?? null)
            || mb_strlen(trim((string) ($terms['client_name'] ?? ''))) > 160
            || mb_strlen(trim((string) ($terms['brief'] ?? ''))) < 20
            || mb_strlen(trim((string) ($terms['deliverables'] ?? ''))) < 20
            || ! isset($terms['shoot_starts_at'], $terms['shoot_ends_at'])
            || strtotime((string) $terms['shoot_ends_at']) < strtotime((string) $terms['shoot_starts_at'])) {
            throw ValidationException::withMessages(['assignment' => 'Provide a complete assignment brief, client, purpose, deliverables and valid shoot dates.']);
        }
    }

    private function approvedPolicyVersion(): string
    {
        $version = (string) config('talyvane.model_agency.assignment_policy_version');
        if (! config('talyvane.model_agency.assignment_policy_approved') || $version === '') {
            throw ValidationException::withMessages(['assignment' => 'A current, versioned model assignment policy must be explicitly approved.']);
        }

        return $version;
    }

    private function assertEvidenceReference(string $reference, string $prefix): void
    {
        if (! str_starts_with($reference, $prefix)
            || str_contains($reference, '..')
            || ! preg_match('/^private\/[A-Za-z0-9-]+\/[A-Za-z0-9][A-Za-z0-9._\/-]{10,180}$/', $reference)) {
            throw ValidationException::withMessages(['evidence' => 'Assignment evidence must reference a private, access-controlled storage key.']);
        }
    }

    private function event(ModelTalentAssignment $assignment, int $actorId, string $type, string $reason): void
    {
        ModelTalentAssignmentEvent::query()->create([
            'assignment_id' => $assignment->id,
            'actor_id' => $actorId,
            'actor_type' => 'administrator',
            'event_type' => $type,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
