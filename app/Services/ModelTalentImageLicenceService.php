<?php

namespace App\Services;

use App\Models\CountryMarket;
use App\Models\Currency;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\ModelTalentRepresentationAgreement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ModelTalentImageLicenceService
{
    public function createDraft(
        ModelTalentPortfolioAsset $asset,
        ModelTalentRepresentationAgreement $agreement,
        int $actorId,
        array $terms,
    ): ModelTalentImageLicence {
        return DB::transaction(function () use ($asset, $agreement, $actorId, $terms): ModelTalentImageLicence {
            $this->assertDraftTerms($terms);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($asset->application_id);
            $lockedAsset = ModelTalentPortfolioAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $lockedAgreement = ModelTalentRepresentationAgreement::query()->lockForUpdate()->findOrFail($agreement->id);
            $this->assertEligible($application, $lockedAsset, $lockedAgreement, $terms);
            $this->assertNoConflictingLicence($lockedAsset, $terms);

            $licence = ModelTalentImageLicence::query()->create([
                'application_id' => $application->id,
                'portfolio_asset_id' => $lockedAsset->id,
                'representation_agreement_id' => $lockedAgreement->id,
                'reference' => 'MTL-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
                'status' => 'draft',
                'licensee_name' => trim($terms['licensee_name']),
                'licensee_reference' => isset($terms['licensee_reference']) ? trim($terms['licensee_reference']) : null,
                'purpose' => $terms['purpose'],
                'channels' => array_values(array_unique($terms['channels'])),
                'purpose_details' => trim($terms['purpose_details']),
                'country_code' => $terms['country_code'],
                'starts_on' => $terms['starts_on'],
                'ends_on' => $terms['ends_on'],
                'exclusivity' => (bool) $terms['exclusivity'],
                'compensation_amount' => $terms['compensation_amount'] ?? null,
                'currency_code' => $terms['currency_code'] ?? null,
                'licence_policy_version' => (string) config('talyvane.model_agency.image_licence_policy_version'),
                'created_by' => $actorId,
            ]);

            $this->event($application, $actorId, 'application.image_licence_drafted', 'Created private image usage licence draft '.$licence->reference.'. No image delivery or usage is authorized.');

            return $licence;
        });
    }

    public function activate(
        ModelTalentImageLicence $licence,
        int $actorId,
        string $executionEvidenceReference,
        string $modelConsentEvidenceReference,
    ): void {
        $this->assertPrivateEvidenceReference($executionEvidenceReference, 'private/model-licences/');
        $this->assertPrivateEvidenceReference($modelConsentEvidenceReference, 'private/model-consent/');

        DB::transaction(function () use ($licence, $actorId, $executionEvidenceReference, $modelConsentEvidenceReference): void {
            $current = ModelTalentImageLicence::query()->findOrFail($licence->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($current->application_id);
            $locked = ModelTalentImageLicence::query()->lockForUpdate()->findOrFail($licence->id);
            $asset = ModelTalentPortfolioAsset::query()->lockForUpdate()->findOrFail($locked->portfolio_asset_id);
            $agreement = ModelTalentRepresentationAgreement::query()->lockForUpdate()->findOrFail($locked->representation_agreement_id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['licence' => 'Only a draft image licence can be activated.']);
            }

            $terms = [
                'country_code' => $locked->country_code,
                'starts_on' => $locked->starts_on->format('Y-m-d'),
                'ends_on' => $locked->ends_on->format('Y-m-d'),
                'purpose' => $locked->purpose,
                'channels' => $locked->channels,
                'exclusivity' => $locked->exclusivity,
                'compensation_amount' => $locked->compensation_amount,
                'currency_code' => $locked->currency_code,
            ];
            $this->assertEligible($application, $asset, $agreement, $terms);
            $this->assertNoConflictingLicence($asset, $terms, $locked->id);

            $policyVersion = (string) config('talyvane.model_agency.image_licence_policy_version');
            if (! config('talyvane.model_agency.image_licence_policy_approved')
                || $policyVersion === ''
                || $locked->licence_policy_version !== $policyVersion) {
                throw ValidationException::withMessages(['licence' => 'The image usage licence must use the current explicitly approved policy version.']);
            }

            $locked->forceFill([
                'status' => 'active',
                'activated_by' => $actorId,
                'activated_at' => now(),
                'execution_evidence_reference' => $executionEvidenceReference,
                'model_consent_evidence_reference' => $modelConsentEvidenceReference,
            ])->save();

            $this->event($application, $actorId, 'application.image_licence_activated', 'Activated externally executed, purpose-specific image licence '.$locked->reference.'. The platform did not sign or validate the external instrument.');
        });
    }

    public function revoke(ModelTalentImageLicence $licence, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($licence, $actorId, $reason): void {
            $current = ModelTalentImageLicence::query()->findOrFail($licence->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($current->application_id);
            $locked = ModelTalentImageLicence::query()->lockForUpdate()->findOrFail($licence->id);

            if (! in_array($locked->status, ['draft', 'active'], true)) {
                throw ValidationException::withMessages(['licence' => 'Only a draft or active image licence can be revoked.']);
            }

            $locked->forceFill([
                'status' => 'revoked',
                'revoked_by' => $actorId,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ])->save();

            $this->event($application, $actorId, 'application.image_licence_revoked', 'Revoked image licence '.$locked->reference.'. '.$reason);
            app(ModelCastingWorkflowService::class)->invalidateForLicence($locked, $actorId, $reason);
        });
    }

    public function revokeForAsset(ModelTalentPortfolioAsset $asset, int $actorId, string $reason): void
    {
        $licences = ModelTalentImageLicence::query()
            ->where('portfolio_asset_id', $asset->id)
            ->whereIn('status', ['draft', 'active'])
            ->lockForUpdate()
            ->get();

        foreach ($licences as $licence) {
            $licence->forceFill([
                'status' => 'revoked',
                'revoked_by' => $actorId,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ])->save();

            $this->event($asset->application, $actorId, 'application.image_licence_revoked', 'Consent withdrawal revoked image licence '.$licence->reference.'.');
            app(ModelCastingWorkflowService::class)->invalidateForLicence($licence, $actorId, $reason);
        }
    }

    public function revokeForAgreement(ModelTalentRepresentationAgreement $agreement, int $actorId, string $reason): void
    {
        $licences = ModelTalentImageLicence::query()
            ->where('representation_agreement_id', $agreement->id)
            ->whereIn('status', ['draft', 'active'])
            ->lockForUpdate()
            ->get();

        foreach ($licences as $licence) {
            $licence->forceFill([
                'status' => 'revoked',
                'revoked_by' => $actorId,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ])->save();

            $this->event($agreement->application, $actorId, 'application.image_licence_revoked', 'Representation agreement termination revoked image licence '.$licence->reference.'.');
            app(ModelCastingWorkflowService::class)->invalidateForLicence($licence, $actorId, $reason);
        }
    }

    public function expireDue(): int
    {
        $ids = ModelTalentImageLicence::query()
            ->where('status', 'active')
            ->whereDate('ends_on', '<', now()->toDateString())
            ->pluck('id');

        $expired = 0;
        foreach ($ids as $id) {
            $current = ModelTalentImageLicence::query()->find($id);
            if (! $current) {
                continue;
            }

            DB::transaction(function () use ($id, &$expired): void {
                $current = ModelTalentImageLicence::query()->find($id);
                if (! $current) {
                    return;
                }
                $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($current->application_id);
                $locked = ModelTalentImageLicence::query()->lockForUpdate()->findOrFail($id);

                if ($locked->status !== 'active' || $locked->ends_on->isToday() || $locked->ends_on->isFuture()) {
                    return;
                }

                $locked->forceFill(['status' => 'expired', 'expired_at' => now()])->save();
                $this->event($application, null, 'application.image_licence_expired', 'Image licence '.$locked->reference.' expired at the end of its recorded term.');
                app(ModelCastingWorkflowService::class)->invalidateForLicence($locked, null, 'The image licence expired at the end of its recorded term.');
                $expired++;
            });
        }

        return $expired;
    }

    private function assertEligible(
        ModelTalentApplication $application,
        ModelTalentPortfolioAsset $asset,
        ModelTalentRepresentationAgreement $agreement,
        array $terms,
    ): void {
        if ($application->status !== 'accepted'
            || $application->adult_eligibility_verified_at === null
            || $application->legal_hold_at !== null
            || $asset->application_id !== $application->id
            || $agreement->application_id !== $application->id
            || $asset->status !== 'approved'
            || $asset->consent_withdrawn_at !== null
            || ! config('talyvane.model_agency.portfolio_consent_approved')
            || $asset->consent_policy_version !== (string) config('talyvane.model_agency.portfolio_consent_version')
            || ! config('talyvane.model_agency.representation_terms_approved')
            || $agreement->terms_policy_version !== (string) config('talyvane.model_agency.representation_terms_version')
            || ! $asset->storage_path
            || ! Storage::disk('local')->exists($asset->storage_path)
            || $agreement->effective_from === null
            || $agreement->status !== 'executed') {
            throw ValidationException::withMessages([
                'licence' => 'Image licences require an accepted and verified applicant, an approved private asset with current consent, and an executed representation agreement.',
            ]);
        }

        $countryCode = (string) ($terms['country_code'] ?? '');
        if ($countryCode === '' || $countryCode !== $agreement->country_code) {
            throw ValidationException::withMessages(['licence' => 'The licence territory must match the executed representation agreement territory.']);
        }

        $market = CountryMarket::query()->where('country_code', $countryCode)->lockForUpdate()->first();
        if (! $market || ! $market->isOperationallyApproved()) {
            throw ValidationException::withMessages(['licence' => 'The licence territory must remain enabled and compliance-approved with current evidence.']);
        }

        $version = (string) config('talyvane.model_agency.image_licence_policy_version');
        if (! config('talyvane.model_agency.image_licence_policy_approved') || $version === '') {
            throw ValidationException::withMessages(['licence' => 'A current, versioned image usage licence policy must be explicitly approved.']);
        }

        $start = (string) ($terms['starts_on'] ?? '');
        $end = (string) ($terms['ends_on'] ?? '');
        if ($start < now()->toDateString() || $end < $start) {
            throw ValidationException::withMessages(['licence' => 'The licence term must start today or later and have a valid end date.']);
        }

        if ($agreement->effective_from && $start < $agreement->effective_from->format('Y-m-d')) {
            throw ValidationException::withMessages(['licence' => 'The image licence cannot begin before the representation agreement.']);
        }
        if ($agreement->effective_until && $end > $agreement->effective_until->format('Y-m-d')) {
            throw ValidationException::withMessages(['licence' => 'The image licence cannot extend beyond the representation agreement term.']);
        }

        $amount = $terms['compensation_amount'] ?? null;
        $currency = $terms['currency_code'] ?? null;
        if (($amount === null) !== ($currency === null)) {
            throw ValidationException::withMessages(['licence' => 'Compensation amount and currency must be supplied together.']);
        }
        if ($amount !== null && (! is_numeric($amount) || (float) $amount <= 0)) {
            throw ValidationException::withMessages(['licence' => 'Compensation must be a positive decimal amount.']);
        }
        if ($currency !== null) {
            $enabledCurrency = Currency::query()->where('code', $currency)->lockForUpdate()->first();
            if (! $enabledCurrency || ! $enabledCurrency->is_enabled) {
                throw ValidationException::withMessages(['licence' => 'Choose an enabled ISO currency for the agreed compensation.']);
            }
        }
    }

    private function assertDraftTerms(array $terms): void
    {
        $purposes = ['editorial', 'commercial_campaign', 'e_commerce', 'social_media', 'advertising', 'casting_distribution', 'private_portfolio', 'other'];
        $channels = ['website', 'print', 'social_media', 'email', 'e_commerce', 'casting_platform', 'out_of_home', 'broadcast'];
        $requestedChannels = $terms['channels'] ?? [];

        if (! filled($terms['licensee_name'] ?? null)
            || mb_strlen(trim((string) ($terms['licensee_name'] ?? ''))) > 160
            || ! in_array($terms['purpose'] ?? null, $purposes, true)
            || ! is_array($requestedChannels)
            || $requestedChannels === []
            || count(array_diff($requestedChannels, $channels)) > 0
            || ! filled($terms['purpose_details'] ?? null)
            || mb_strlen(trim((string) ($terms['purpose_details'] ?? ''))) < 20) {
            throw ValidationException::withMessages(['licence' => 'Provide a licensee, allowed purpose, at least one permitted channel and detailed usage restrictions.']);
        }
    }

    private function assertNoConflictingLicence(ModelTalentPortfolioAsset $asset, array $terms, ?int $ignoreId = null): void
    {
        $query = ModelTalentImageLicence::query()
            ->where('portfolio_asset_id', $asset->id)
            ->where('country_code', $terms['country_code'])
            ->where('status', 'active')
            ->whereDate('starts_on', '<=', $terms['ends_on'])
            ->whereDate('ends_on', '>=', $terms['starts_on']);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        $channels = $terms['channels'] ?? [];
        foreach ($query->get() as $existing) {
            $overlap = array_intersect($channels, $existing->channels ?? []);
            if ($overlap !== []
                && ((bool) $terms['exclusivity'] || $existing->exclusivity || $existing->purpose === $terms['purpose'])) {
                throw ValidationException::withMessages(['licence' => 'The requested usage conflicts with an active licence for the same asset, territory, channel and overlapping term.']);
            }
        }
    }

    private function assertPrivateEvidenceReference(string $reference, string $prefix): void
    {
        if (! str_starts_with($reference, $prefix)
            || str_contains($reference, '..')
            || ! preg_match('/^private\/[A-Za-z0-9-]+\/[A-Za-z0-9][A-Za-z0-9._\/-]{10,180}$/', $reference)) {
            throw ValidationException::withMessages(['evidence' => 'Evidence must reference a private, access-controlled storage key.']);
        }
    }

    private function event(ModelTalentApplication $application, ?int $actorId, string $type, string $reason): void
    {
        ModelTalentApplicationEvent::query()->create([
            'application_id' => $application->id,
            'actor_id' => $actorId,
            'actor_type' => $actorId === null ? 'system' : 'administrator',
            'event_type' => $type,
            'from_status' => $application->status,
            'to_status' => $application->status,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
