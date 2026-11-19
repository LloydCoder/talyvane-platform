<?php

namespace App\Services;

use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use App\Models\CountryMarket;
use App\Models\ModelTalentRepresentationAgreement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ModelTalentRepresentationAgreementService
{
    public function createDraft(ModelTalentApplication $application, int $actorId, array $terms): ModelTalentRepresentationAgreement
    {
        return DB::transaction(function () use ($application, $actorId, $terms): ModelTalentRepresentationAgreement {
            $locked = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->assertEligible($locked, (string) ($terms['country_code'] ?? ''));

            $active = ModelTalentRepresentationAgreement::query()
                ->where('application_id', $locked->id)
                ->whereIn('status', ['draft', 'offered', 'executed'])
                ->exists();

            if ($active) {
                throw ValidationException::withMessages([
                    'agreement' => 'An active draft, offer or executed representation agreement already exists for this applicant.',
                ]);
            }

            $version = ((int) ModelTalentRepresentationAgreement::query()
                ->where('application_id', $locked->id)
                ->max('version')) + 1;

            $agreement = ModelTalentRepresentationAgreement::query()->create([
                'application_id' => $locked->id,
                'reference' => 'MRA-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
                'version' => $version,
                'status' => 'draft',
                'terms_snapshot' => $terms,
                'terms_policy_version' => (string) config('talyvane.model_agency.representation_terms_version'),
                'created_by' => $actorId,
            ]);

            $this->event($locked, $actorId, 'application.representation_agreement_drafted', 'Created agreement '.$agreement->reference.' version '.$version.'.');

            return $agreement;
        });
    }

    public function offer(ModelTalentRepresentationAgreement $agreement, int $actorId): void
    {
        DB::transaction(function () use ($agreement, $actorId): void {
            $locked = ModelTalentRepresentationAgreement::query()->lockForUpdate()->findOrFail($agreement->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($locked->application_id);
            $this->assertEligible($application, (string) ($locked->terms_snapshot['country_code'] ?? ''));

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['agreement' => 'Only a draft agreement can be offered.']);
            }

            $version = (string) config('talyvane.model_agency.representation_terms_version');
            if (! config('talyvane.model_agency.representation_terms_approved') || $version === '' || $locked->terms_policy_version !== $version) {
                throw ValidationException::withMessages(['agreement' => 'The current representation terms must be versioned and legally approved before an offer can be issued.']);
            }

            $locked->forceFill(['status' => 'offered', 'offered_by' => $actorId, 'offered_at' => now()])->save();
            $this->event($application, $actorId, 'application.representation_agreement_offered', 'Offered agreement '.$locked->reference.'.');
        });
    }

    public function recordExecution(
        ModelTalentRepresentationAgreement $agreement,
        int $actorId,
        string $documentReference,
        string $effectiveFrom,
        ?string $effectiveUntil,
    ): void {
        DB::transaction(function () use ($agreement, $actorId, $documentReference, $effectiveFrom, $effectiveUntil): void {
            $locked = ModelTalentRepresentationAgreement::query()->lockForUpdate()->findOrFail($agreement->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($locked->application_id);
            $this->assertEligible($application);

            if ($locked->status !== 'offered') {
                throw ValidationException::withMessages(['agreement' => 'Only an outstanding offer can be marked executed.']);
            }

            if ($effectiveUntil !== null && $effectiveUntil < $effectiveFrom) {
                throw ValidationException::withMessages(['effective_until' => 'The end date must not precede the effective date.']);
            }

            $locked->forceFill([
                'status' => 'executed',
                'executed_by' => $actorId,
                'executed_at' => now(),
                'executed_document_reference' => $documentReference,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
            ])->save();

            $this->event($application, $actorId, 'application.representation_executed_recorded', 'Recorded externally executed agreement '.$locked->reference.'. The platform did not perform or validate an electronic signature.');
        });
    }

    public function terminate(ModelTalentRepresentationAgreement $agreement, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($agreement, $actorId, $reason): void {
            $locked = ModelTalentRepresentationAgreement::query()->lockForUpdate()->findOrFail($agreement->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($locked->application_id);

            if ($locked->status !== 'executed') {
                throw ValidationException::withMessages(['agreement' => 'Only an executed agreement can be terminated.']);
            }

            $locked->forceFill([
                'status' => 'terminated',
                'terminated_at' => now(),
                'terminated_by' => $actorId,
                'termination_reason' => $reason,
            ])->save();

            $this->event($application, $actorId, 'application.representation_agreement_terminated', 'Terminated agreement '.$locked->reference.'. '.$reason);
        });
    }

    private function assertEligible(ModelTalentApplication $application, string $countryCode): void
    {
        if ($application->status !== 'accepted'
            || $application->adult_eligibility_verified_at === null
            || $application->legal_hold_at !== null) {
            throw ValidationException::withMessages([
                'agreement' => 'Representation agreements require an accepted application, completed adult-eligibility verification and no active legal hold.',
            ]);
        }

        $market = CountryMarket::query()->where('country_code', $countryCode)->first();
        if (! $market || ! $market->isOperationallyApproved()) {
            throw ValidationException::withMessages([
                'agreement' => 'The agreement territory must be an enabled market with current compliance approval and evidence.',
            ]);
        }
    }

    private function event(ModelTalentApplication $application, int $actorId, string $type, string $reason): void
    {
        ModelTalentApplicationEvent::query()->create([
            'application_id' => $application->id,
            'actor_id' => $actorId,
            'actor_type' => 'administrator',
            'event_type' => $type,
            'from_status' => $application->status,
            'to_status' => $application->status,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
