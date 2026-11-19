<?php

namespace App\Services;

use App\Models\CountryMarket;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use App\Models\ModelTalentPortfolioAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ModelTalentPortfolioAssetService
{
    public function __construct(private readonly ProfileMediaProcessor $processor)
    {
    }

    public function store(
        ModelTalentApplication $application,
        int $actorId,
        UploadedFile $file,
        array $evidence,
    ): ModelTalentPortfolioAsset {
        $this->assertEligible(ModelTalentApplication::query()->findOrFail($application->id), false);
        $image = $this->processor->storeSanitized($file, 'model-portfolio');

        try {
            return DB::transaction(function () use ($application, $actorId, $evidence, $image): ModelTalentPortfolioAsset {
                $locked = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);
                $policyVersion = $this->assertEligible($locked, true);

                if (ModelTalentPortfolioAsset::query()
                    ->where('application_id', $locked->id)
                    ->where('sha256', $image['sha256'])
                    ->exists()) {
                    throw ValidationException::withMessages(['media' => 'This exact image is already recorded in the applicant portfolio.']);
                }

                $asset = ModelTalentPortfolioAsset::query()->create([
                    'application_id' => $locked->id,
                    'uploaded_by' => $actorId,
                    'storage_path' => $image['path'],
                    'mime_type' => 'image/webp',
                    'width' => $image['width'],
                    'height' => $image['height'],
                    'file_size' => $image['size'],
                    'sha256' => $image['sha256'],
                    'status' => 'pending_review',
                    'source_description' => $evidence['source_description'],
                    'consent_scope' => $evidence['consent_scope'],
                    'rights_evidence_reference' => $evidence['rights_evidence_reference'],
                    'consent_evidence_reference' => $evidence['consent_evidence_reference'],
                    'consent_policy_version' => $policyVersion,
                    'consent_recorded_at' => now(),
                ]);

                $this->event($locked, $actorId, 'application.portfolio_asset_uploaded', 'A private portfolio asset was received with externally recorded rights and consent evidence. The image remains private and pending review.');

                return $asset;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($image['path']);
            throw $exception;
        }
    }

    public function review(ModelTalentPortfolioAsset $asset, int $actorId, string $decision, string $note): void
    {
        DB::transaction(function () use ($asset, $actorId, $decision, $note): void {
            $locked = ModelTalentPortfolioAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($locked->application_id);
            $this->assertEligible($application, true);

            if ($locked->status !== 'pending_review' || ! $locked->storage_path || ! Storage::disk('local')->exists($locked->storage_path)) {
                throw ValidationException::withMessages(['decision' => 'Only an intact private asset awaiting review can be approved or rejected.']);
            }

            if (! in_array($decision, ['approved', 'rejected'], true)) {
                throw ValidationException::withMessages(['decision' => 'Choose an allowed review decision.']);
            }

            if ($decision === 'rejected') {
                if (! Storage::disk('local')->delete($locked->storage_path)) {
                    throw new RuntimeException('The rejected portfolio image could not be removed from private storage.');
                }
            }

            $path = $locked->storage_path;
            $locked->forceFill([
                'status' => $decision,
                'storage_path' => $decision === 'rejected' ? null : $path,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->event(
                $application,
                $actorId,
                $decision === 'approved' ? 'application.portfolio_asset_approved' : 'application.portfolio_asset_rejected',
                $decision === 'approved'
                    ? 'A portfolio asset was approved for private agency curation only. No public or campaign usage is authorized.'
                    : 'A portfolio asset was rejected and its private image file removed.',
            );
        });
    }

    public function withdrawConsent(
        ModelTalentPortfolioAsset $asset,
        int $actorId,
        string $reason,
        string $evidenceReference,
    ): void {
        DB::transaction(function () use ($asset, $actorId, $reason, $evidenceReference): void {
            $locked = ModelTalentPortfolioAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($locked->application_id);

            if ($locked->status === 'withdrawn' || $locked->consent_withdrawn_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Consent has already been withdrawn for this asset.']);
            }

            if ($locked->storage_path && Storage::disk('local')->exists($locked->storage_path)) {
                if (! Storage::disk('local')->delete($locked->storage_path)) {
                    throw new RuntimeException('The withdrawn portfolio image could not be removed from private storage.');
                }
            }

            $locked->forceFill([
                'status' => 'withdrawn',
                'storage_path' => null,
                'consent_withdrawn_at' => now(),
                'withdrawal_evidence_reference' => $evidenceReference,
                'withdrawal_reason' => $reason,
            ])->save();

            $this->event($application, $actorId, 'application.portfolio_consent_withdrawn', 'Consent withdrawal was recorded; the private image file was removed and cannot be previewed or used.');
        });
    }

    private function assertEligible(ModelTalentApplication $application, bool $lockMarket): string
    {
        if ($application->status !== 'accepted'
            || $application->adult_eligibility_verified_at === null
            || $application->legal_hold_at !== null) {
            throw ValidationException::withMessages([
                'media' => 'Portfolio assets require an accepted application, completed adult-eligibility verification and no active legal hold.',
            ]);
        }

        $marketQuery = CountryMarket::query()->where('country_code', $application->country_code);
        if ($lockMarket) {
            $marketQuery->lockForUpdate();
        }
        $market = $marketQuery->first();

        if (! $market || ! $market->isOperationallyApproved()) {
            throw ValidationException::withMessages([
                'media' => 'Portfolio media is disabled unless the applicant market is enabled and has current compliance approval and evidence.',
            ]);
        }

        $version = (string) config('talyvane.model_agency.portfolio_consent_version');
        if (! config('talyvane.model_agency.portfolio_consent_approved') || $version === '') {
            throw ValidationException::withMessages([
                'media' => 'The current versioned model portfolio consent policy must be approved before media can be received or reviewed.',
            ]);
        }

        return $version;
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
