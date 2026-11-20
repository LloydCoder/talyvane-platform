<?php

namespace App\Services;

use App\Models\CountryMarket;
use App\Models\Currency;
use App\Models\ModelCastingCall;
use App\Models\ModelCastingEvent;
use App\Models\ModelCastingSubmission;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\ModelTalentRepresentationAgreement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ModelCastingWorkflowService
{
    public function createDraft(int $actorId, array $terms): ModelCastingCall
    {
        app(FeatureRegistry::class)->assertEnabled('model-agency.casting');
        $this->assertCallTerms($terms);

        return DB::transaction(function () use ($actorId, $terms): ModelCastingCall {
            $market = CountryMarket::query()->where('country_code', $terms['country_code'])->lockForUpdate()->first();
            if (! $market || ! $market->isOperationallyApproved()) {
                throw ValidationException::withMessages(['call' => 'Casting calls require an enabled market with current compliance approval and evidence.']);
            }

            $policyVersion = $this->approvedPolicyVersion();
            $amount = $terms['compensation_amount'] ?? null;
            $currency = $terms['currency_code'] ?? null;
            if (($amount === null) !== ($currency === null)) {
                throw ValidationException::withMessages(['call' => 'Compensation amount and currency must be supplied together.']);
            }
            if ($amount !== null) {
                $enabledCurrency = Currency::query()->where('code', $currency)->lockForUpdate()->first();
                if (! $enabledCurrency || ! $enabledCurrency->is_enabled || (float) $amount <= 0) {
                    throw ValidationException::withMessages(['call' => 'Choose an enabled currency and positive compensation amount.']);
                }
            }

            $call = ModelCastingCall::query()->create([
                'reference' => 'MCC-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
                'title' => trim($terms['title']),
                'client_name' => trim($terms['client_name']),
                'client_reference' => isset($terms['client_reference']) ? trim($terms['client_reference']) : null,
                'project_purpose' => $terms['project_purpose'],
                'description' => trim($terms['description']),
                'requirements' => trim($terms['requirements']),
                'country_code' => $terms['country_code'],
                'opens_at' => $terms['opens_at'],
                'closes_at' => $terms['closes_at'],
                'shoot_starts_at' => $terms['shoot_starts_at'],
                'shoot_ends_at' => $terms['shoot_ends_at'],
                'compensation_amount' => $amount,
                'currency_code' => $currency,
                'max_submissions' => (int) $terms['max_submissions'],
                'status' => 'draft',
                'casting_policy_version' => $policyVersion,
                'created_by' => $actorId,
            ]);

            $this->event($call, null, $actorId, 'casting.call_created', 'Created a private casting brief. It is not publicly listed or distributed to clients.');

            return $call;
        });
    }

    public function publish(ModelCastingCall $call, int $actorId): void
    {
        app(FeatureRegistry::class)->assertEnabled('model-agency.casting');
        DB::transaction(function () use ($call, $actorId): void {
            $locked = ModelCastingCall::query()->lockForUpdate()->findOrFail($call->id);
            $this->assertPublishable($locked);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['call' => 'Only a draft casting call can be opened.']);
            }

            $locked->forceFill([
                'status' => 'open',
                'published_by' => $actorId,
                'published_at' => now(),
            ])->save();

            $this->event($locked, null, $actorId, 'casting.call_published', 'Opened a private agency casting call. It is not publicly listed.');
        });
    }

    public function close(ModelCastingCall $call, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($call, $actorId, $reason): void {
            $locked = ModelCastingCall::query()->lockForUpdate()->findOrFail($call->id);
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['call' => 'Only an open casting call can be closed.']);
            }

            $locked->forceFill([
                'status' => 'closed',
                'closed_by' => $actorId,
                'closed_at' => now(),
            ])->save();

            $this->event($locked, null, $actorId, 'casting.call_closed', $reason);
        });
    }

    public function cancel(ModelCastingCall $call, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($call, $actorId, $reason): void {
            $locked = ModelCastingCall::query()->lockForUpdate()->findOrFail($call->id);
            if (! in_array($locked->status, ['draft', 'open'], true)) {
                throw ValidationException::withMessages(['call' => 'Only a draft or open casting call can be cancelled.']);
            }

            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actorId,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $submissions = ModelCastingSubmission::query()
                ->where('call_id', $locked->id)
                ->whereIn('status', ['submitted', 'shortlisted', 'selected'])
                ->lockForUpdate()
                ->get();

            foreach ($submissions as $submission) {
                $submission->forceFill([
                    'status' => 'invalidated',
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'The casting call was cancelled: '.$reason,
                ])->save();
                $this->event($locked, $submission->id, $actorId, 'casting.submission_invalidated', 'Submission invalidated because its casting call was cancelled.');
            }

            $this->event($locked, null, $actorId, 'casting.call_cancelled', $reason);
        });
    }

    public function submit(
        ModelCastingCall $call,
        ModelTalentApplication $application,
        ModelTalentPortfolioAsset $asset,
        ModelTalentImageLicence $licence,
        int $actorId,
    ): ModelCastingSubmission {
        return DB::transaction(function () use ($call, $application, $asset, $licence, $actorId): ModelCastingSubmission {
            $lockedApplication = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);
            $lockedCall = ModelCastingCall::query()->lockForUpdate()->findOrFail($call->id);
            $lockedAsset = ModelTalentPortfolioAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $lockedLicence = ModelTalentImageLicence::query()->lockForUpdate()->findOrFail($licence->id);

            $this->assertSubmissionEligible($lockedCall, $lockedApplication, $lockedAsset, $lockedLicence);

            if (ModelCastingSubmission::query()->where('call_id', $lockedCall->id)->where('application_id', $lockedApplication->id)->exists()) {
                throw ValidationException::withMessages(['submission' => 'This model already has a submission for the casting call.']);
            }

            if ($lockedCall->submissions()->whereIn('status', ['submitted', 'shortlisted', 'selected'])->count() >= $lockedCall->max_submissions) {
                throw ValidationException::withMessages(['submission' => 'The casting call has reached its submission limit.']);
            }

            $submission = ModelCastingSubmission::query()->create([
                'call_id' => $lockedCall->id,
                'application_id' => $lockedApplication->id,
                'portfolio_asset_id' => $lockedAsset->id,
                'image_licence_id' => $lockedLicence->id,
                'reference' => 'MCS-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
                'status' => 'submitted',
                'submitted_by' => $actorId,
                'submitted_at' => now(),
            ]);

            $this->event($lockedCall, $submission->id, $actorId, 'casting.submission_created', 'Submitted an eligible model to a private casting call using an active casting-distribution licence.');

            return $submission;
        });
    }

    public function decide(ModelCastingSubmission $submission, int $actorId, string $decision, string $note): void
    {
        if (in_array($decision, ['shortlisted', 'selected'], true)) {
            app(FeatureRegistry::class)->assertEnabled('model-agency.casting');
        }
        DB::transaction(function () use ($submission, $actorId, $decision, $note): void {
            $current = ModelCastingSubmission::query()->findOrFail($submission->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($current->application_id);
            $call = ModelCastingCall::query()->lockForUpdate()->findOrFail($current->call_id);
            $locked = ModelCastingSubmission::query()->lockForUpdate()->findOrFail($submission->id);

            if (! in_array($decision, ['shortlisted', 'selected', 'declined'], true)
                || ! in_array($locked->status, ['submitted', 'shortlisted'], true)) {
                throw ValidationException::withMessages(['decision' => 'This casting submission cannot receive the requested decision.']);
            }

            if (in_array($decision, ['shortlisted', 'selected'], true)) {
                $licence = ModelTalentImageLicence::query()->lockForUpdate()->findOrFail($locked->image_licence_id);
                $asset = ModelTalentPortfolioAsset::query()->lockForUpdate()->findOrFail($locked->portfolio_asset_id);
                $agreement = ModelTalentRepresentationAgreement::query()->lockForUpdate()->find($licence->representation_agreement_id);

                if ($application->status !== 'accepted'
                    || $application->adult_eligibility_verified_at === null
                    || $application->legal_hold_at !== null
                    || $licence->status !== 'active'
                    || $licence->purpose !== 'casting_distribution'
                    || ! in_array('casting_platform', $licence->channels ?? [], true)
                    || $licence->country_code !== $call->country_code
                    || $licence->starts_on->format('Y-m-d') > $call->shoot_starts_at->format('Y-m-d')
                    || $licence->ends_on->format('Y-m-d') < $call->shoot_ends_at->format('Y-m-d')
                    || $asset->status !== 'approved'
                    || $asset->consent_withdrawn_at !== null
                    || ! config('talyvane.model_agency.portfolio_consent_approved')
                    || $asset->consent_policy_version !== (string) config('talyvane.model_agency.portfolio_consent_version')
                    || ! config('talyvane.model_agency.representation_terms_approved')
                    || ! $asset->storage_path
                    || ! Storage::disk('local')->exists($asset->storage_path)
                    || ! $agreement
                    || $agreement->application_id !== $application->id
                    || $agreement->status !== 'executed'
                    || $agreement->terms_policy_version !== (string) config('talyvane.model_agency.representation_terms_version')
                    || $agreement->country_code !== $call->country_code
                    || ! CountryMarket::query()->where('country_code', $call->country_code)->lockForUpdate()->first()?->isOperationallyApproved()
                    || ! config('talyvane.model_agency.casting_policy_approved')
                    || $call->casting_policy_version !== (string) config('talyvane.model_agency.casting_policy_version')
                    || ! config('talyvane.model_agency.image_licence_policy_approved')
                    || $licence->licence_policy_version !== (string) config('talyvane.model_agency.image_licence_policy_version')
                    || $licence->model_consent_evidence_reference === null) {
                    throw ValidationException::withMessages(['decision' => 'Shortlisting or selection requires a currently eligible model, approved asset, approved market and active casting licence.']);
                }
            }

            $locked->forceFill([
                'status' => $decision,
                'decision_by' => $actorId,
                'decision_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->event($call, $locked->id, $actorId, 'casting.submission_'.$decision, 'Manual agency decision: '.$decision.'. '.$note);
        });
    }

    public function withdraw(ModelCastingSubmission $submission, int $actorId, string $reason, string $evidenceReference): void
    {
        if (! str_starts_with($evidenceReference, 'private/model-consent/') || str_contains($evidenceReference, '..')) {
            throw ValidationException::withMessages(['evidence' => 'Withdrawal evidence must use a private model-consent storage reference.']);
        }

        DB::transaction(function () use ($submission, $actorId, $reason, $evidenceReference): void {
            $current = ModelCastingSubmission::query()->findOrFail($submission->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($current->application_id);
            $call = ModelCastingCall::query()->lockForUpdate()->findOrFail($current->call_id);
            $locked = ModelCastingSubmission::query()->lockForUpdate()->findOrFail($submission->id);

            if (! in_array($locked->status, ['submitted', 'shortlisted', 'selected'], true)) {
                throw ValidationException::withMessages(['reason' => 'Only a live casting submission can be withdrawn.']);
            }

            $locked->forceFill([
                'status' => 'withdrawn',
                'decision_by' => $actorId,
                'decision_at' => now(),
                'decision_note' => $reason,
                'withdrawal_evidence_reference' => $evidenceReference,
            ])->save();

            $this->event($call, $locked->id, $actorId, 'casting.submission_withdrawn', 'Model submission withdrawal recorded: '.$reason);
        });
    }

    public function invalidateForLicence(ModelTalentImageLicence $licence, ?int $actorId, string $reason): void
    {
        if (! Schema::hasTable('model_casting_submissions') || ! Schema::hasTable('model_casting_events')) {
            return;
        }

        $submissions = ModelCastingSubmission::query()
            ->where('image_licence_id', $licence->id)
            ->whereIn('status', ['submitted', 'shortlisted', 'selected'])
            ->lockForUpdate()
            ->get();

        foreach ($submissions as $submission) {
            $submission->forceFill([
                'status' => 'invalidated',
                'invalidated_at' => now(),
                'invalidation_reason' => $reason,
            ])->save();

            $this->event(
                $submission->call,
                $submission->id,
                $actorId,
                'casting.submission_invalidated',
                'Underlying image licence is no longer active. '.$reason,
            );
        }
    }

    private function assertCallTerms(array $terms): void
    {
        $purposes = ['editorial', 'commercial_campaign', 'e_commerce', 'social_media', 'advertising', 'other'];
        if (! filled($terms['title'] ?? null)
            || mb_strlen((string) $terms['title']) > 180
            || ! filled($terms['client_name'] ?? null)
            || ! in_array($terms['project_purpose'] ?? null, $purposes, true)
            || mb_strlen(trim((string) ($terms['description'] ?? ''))) < 20
            || mb_strlen(trim((string) ($terms['requirements'] ?? ''))) < 20
            || ! isset($terms['opens_at'], $terms['closes_at'], $terms['shoot_starts_at'], $terms['shoot_ends_at'])
            || (int) ($terms['max_submissions'] ?? 0) < 1
            || (int) ($terms['max_submissions'] ?? 0) > 500) {
            throw ValidationException::withMessages(['call' => 'Provide a complete casting brief, purpose, dates and valid submission limit.']);
        }

        if (strtotime($terms['closes_at']) <= strtotime($terms['opens_at'])
            || strtotime($terms['shoot_starts_at']) < strtotime($terms['closes_at'])
            || strtotime($terms['shoot_ends_at']) < strtotime($terms['shoot_starts_at'])) {
            throw ValidationException::withMessages(['call' => 'Casting close and shoot dates must form a valid chronological window.']);
        }
    }

    private function assertPublishable(ModelCastingCall $call): void
    {
        $version = $this->approvedPolicyVersion();
        if ($call->casting_policy_version !== $version) {
            throw ValidationException::withMessages(['call' => 'This casting call was drafted under an outdated casting policy version.']);
        }

        $market = CountryMarket::query()->where('country_code', $call->country_code)->lockForUpdate()->first();
        if (! $market || ! $market->isOperationallyApproved()) {
            throw ValidationException::withMessages(['call' => 'The casting territory is no longer enabled and compliance-approved.']);
        }

        if ($call->closes_at->isPast() || $call->shoot_starts_at->lessThan($call->closes_at) || $call->shoot_ends_at->lessThan($call->shoot_starts_at)) {
            throw ValidationException::withMessages(['call' => 'The casting call dates are no longer valid for publication.']);
        }
    }

    private function assertSubmissionEligible(
        ModelCastingCall $call,
        ModelTalentApplication $application,
        ModelTalentPortfolioAsset $asset,
        ModelTalentImageLicence $licence,
    ): void {
        if ($call->status !== 'open'
            || $call->opens_at->isFuture()
            || $call->closes_at->isPast()
            || ! config('talyvane.model_agency.casting_policy_approved')
            || $call->casting_policy_version !== (string) config('talyvane.model_agency.casting_policy_version')
            || ! config('talyvane.model_agency.image_licence_policy_approved')
            || $licence->licence_policy_version !== (string) config('talyvane.model_agency.image_licence_policy_version')
            || ! config('talyvane.model_agency.portfolio_consent_approved')
            || $asset->consent_policy_version !== (string) config('talyvane.model_agency.portfolio_consent_version')
            || ! config('talyvane.model_agency.representation_terms_approved')
            || $application->status !== 'accepted'
            || $application->adult_eligibility_verified_at === null
            || $application->legal_hold_at !== null
            || $asset->application_id !== $application->id
            || $asset->status !== 'approved'
            || $asset->consent_withdrawn_at !== null
            || ! $asset->storage_path
            || ! Storage::disk('local')->exists($asset->storage_path)
            || $licence->status !== 'active'
            || $licence->portfolio_asset_id !== $asset->id
            || $licence->application_id !== $application->id
            || $licence->purpose !== 'casting_distribution'
            || ! in_array('casting_platform', $licence->channels ?? [], true)
            || $licence->country_code !== $call->country_code
            || $licence->starts_on->format('Y-m-d') > $call->shoot_starts_at->format('Y-m-d')
            || $licence->ends_on->format('Y-m-d') < $call->shoot_ends_at->format('Y-m-d')
            || $licence->model_consent_evidence_reference === null) {
            throw ValidationException::withMessages(['submission' => 'Casting submissions require an open call, eligible verified model, approved private asset and active matching casting-distribution licence.']);
        }

        $market = CountryMarket::query()->where('country_code', $call->country_code)->lockForUpdate()->first();
        if (! $market || ! $market->isOperationallyApproved()) {
            throw ValidationException::withMessages(['submission' => 'The casting market is no longer enabled and compliance-approved.']);
        }

        $agreement = ModelTalentRepresentationAgreement::query()->lockForUpdate()->find($licence->representation_agreement_id);
        if (! $agreement
            || $agreement->status !== 'executed'
            || $agreement->country_code !== $call->country_code
            || ! $agreement->effective_from
            || $agreement->application_id !== $application->id
            || ! config('talyvane.model_agency.representation_terms_approved')
            || $agreement->terms_policy_version !== (string) config('talyvane.model_agency.representation_terms_version')) {
            throw ValidationException::withMessages(['submission' => 'An executed representation agreement for the casting territory is required.']);
        }
    }

    private function approvedPolicyVersion(): string
    {
        $version = (string) config('talyvane.model_agency.casting_policy_version');
        if (! config('talyvane.model_agency.casting_policy_approved') || $version === '') {
            throw ValidationException::withMessages(['call' => 'A current, versioned casting policy must be explicitly approved before calls can be created or published.']);
        }

        return $version;
    }

    private function event(ModelCastingCall $call, ?int $submissionId, ?int $actorId, string $type, string $reason): void
    {
        ModelCastingEvent::query()->create([
            'call_id' => $call->id,
            'submission_id' => $submissionId,
            'actor_id' => $actorId,
            'actor_type' => $actorId === null ? 'system' : 'administrator',
            'event_type' => $type,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
