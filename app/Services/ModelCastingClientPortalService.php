<?php

namespace App\Services;

use App\Models\CountryMarket;
use App\Models\ModelCastingCall;
use App\Models\ModelCastingClientAccess;
use App\Models\ModelCastingClientReview;
use App\Models\ModelCastingEvent;
use App\Models\ModelCastingSubmission;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\ModelTalentRepresentationAgreement;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ModelCastingClientPortalService
{
    public function grantAccess(
        ModelCastingCall $call,
        User $client,
        int $adminId,
        DateTimeInterface $expiresAt,
        string $reason,
    ): ModelCastingClientAccess {
        $this->assertFeatureAndPolicy();
        if (mb_strlen(trim($reason)) < 20 || mb_strlen(trim($reason)) > 1000) {
            throw ValidationException::withMessages(['access' => 'Provide a grant reason between 20 and 1,000 characters.']);
        }

        return DB::transaction(function () use ($call, $client, $adminId, $expiresAt, $reason): ModelCastingClientAccess {
            $lockedCall = ModelCastingCall::query()->lockForUpdate()->findOrFail($call->id);
            $this->assertApprovedMarket($lockedCall->country_code);

            $maxDays = (int) config('talyvane.model_agency.client_review_access_days', 30);
            $expiry = Carbon::parse($expiresAt);
            if (! in_array($lockedCall->status, ['open', 'closed'], true)
                || $client->role !== 'client'
                || ! $client->is_active
                || $client->email_verified_at === null
                || $expiry->isPast()
                || $expiry->gt(now()->addDays($maxDays))) {
                throw ValidationException::withMessages(['access' => 'Only an active, email-verified client may receive a short-lived grant for a non-cancelled casting call.']);
            }

            $access = ModelCastingClientAccess::query()
                ->where('call_id', $lockedCall->id)
                ->where('client_user_id', $client->id)
                ->lockForUpdate()
                ->first();

            if ($access && $access->isActive()) {
                throw ValidationException::withMessages(['access' => 'This client already has active access to the casting call.']);
            }

            if ($access) {
                $access->forceFill([
                    'granted_by' => $adminId,
                    'granted_at' => now(),
                    'expires_at' => $expiry,
                    'revoked_by' => null,
                    'revoked_at' => null,
                    'reason' => trim($reason),
                ])->save();
            } else {
                $access = ModelCastingClientAccess::query()->create([
                    'call_id' => $lockedCall->id,
                    'client_user_id' => $client->id,
                    'granted_by' => $adminId,
                    'granted_at' => now(),
                    'expires_at' => $expiry,
                    'reason' => trim($reason),
                ]);
            }

            $this->event($lockedCall, null, $adminId, 'administrator', 'casting.client_access_granted', $reason);

            return $access;
        });
    }

    public function revokeAccess(ModelCastingClientAccess $access, int $adminId, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 20 || mb_strlen(trim($reason)) > 1000) {
            throw ValidationException::withMessages(['access' => 'Provide a revocation reason between 20 and 1,000 characters.']);
        }

        DB::transaction(function () use ($access, $adminId, $reason): void {
            $locked = ModelCastingClientAccess::query()->lockForUpdate()->findOrFail($access->id);
            if ($locked->revoked_at !== null) {
                throw ValidationException::withMessages(['access' => 'This client access grant has already been revoked.']);
            }

            $locked->forceFill([
                'revoked_by' => $adminId,
                'revoked_at' => now(),
                'reason' => trim($reason),
            ])->save();

            $this->event($locked->call, null, $adminId, 'administrator', 'casting.client_access_revoked', $reason);
        });
    }

    public function callsFor(User $client)
    {
        $this->assertFeatureAndPolicy();
        abort_unless($client->role === 'client' && $client->is_active && $client->email_verified_at !== null, 404);

        return ModelCastingCall::query()
            ->whereIn('status', ['open', 'closed'])
            ->whereHas('clientAccesses', function ($query) use ($client): void {
                $query->where('client_user_id', $client->id)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now());
            })
            ->orderByDesc('updated_at')
            ->get();
    }

    public function submissionsFor(ModelCastingCall $call, User $client)
    {
        $this->activeAccess($call, $client);

        return ModelCastingSubmission::query()
            ->where('call_id', $call->id)
            ->whereIn('status', ['submitted', 'shortlisted', 'selected'])
            ->with([
                'application:id,reference,experience_summary',
                'portfolioAsset',
                'imageLicence',
                'clientReviews' => fn ($query) => $query->where('client_user_id', $client->id),
            ])
            ->latest('submitted_at')
            ->get()
            ->filter(function (ModelCastingSubmission $submission) use ($client): bool {
                try {
                    $this->assertSubmissionVisible($submission, $client);

                    return true;
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    return false;
                }
            })
            ->values();
    }

    public function authorizeSubmission(ModelCastingSubmission $submission, User $client): ModelCastingSubmission
    {
        $submission->loadMissing(['call', 'application', 'portfolioAsset', 'imageLicence', 'imageLicence.representationAgreement']);
        $this->activeAccess($submission->call, $client);
        $this->assertSubmissionVisible($submission, $client);

        return $submission;
    }

    public function recordReview(
        ModelCastingSubmission $submission,
        User $client,
        string $decision,
        string $note,
    ): ModelCastingClientReview {
        if (! in_array($decision, ['shortlisted', 'declined'], true)
            || mb_strlen(trim($note)) < 20
            || mb_strlen(trim($note)) > 1500) {
            throw ValidationException::withMessages(['review' => 'Choose a valid advisory decision and provide a reason between 20 and 1,500 characters.']);
        }

        return DB::transaction(function () use ($submission, $client, $decision, $note): ModelCastingClientReview {
            $current = ModelCastingSubmission::query()->findOrFail($submission->id);
            $call = ModelCastingCall::query()->lockForUpdate()->findOrFail($current->call_id);
            $access = ModelCastingClientAccess::query()
                ->where('call_id', $call->id)
                ->where('client_user_id', $client->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            abort_unless($access, 404);

            $lockedSubmission = ModelCastingSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $lockedSubmission->loadMissing(['application', 'portfolioAsset', 'imageLicence', 'imageLicence.representationAgreement']);
            $this->assertSubmissionVisible($lockedSubmission, $client);

            if (! in_array($lockedSubmission->status, ['submitted', 'shortlisted', 'selected'], true)) {
                throw ValidationException::withMessages(['review' => 'This casting submission is no longer open for client feedback.']);
            }

            $review = ModelCastingClientReview::query()->updateOrCreate(
                [
                    'submission_id' => $lockedSubmission->id,
                    'client_user_id' => $client->id,
                ],
                [
                    'call_id' => $call->id,
                    'access_id' => $access->id,
                    'decision' => $decision,
                    'note' => trim($note),
                    'reviewed_at' => now(),
                ],
            );

            $this->event(
                $call,
                $lockedSubmission->id,
                $client->id,
                'client',
                'casting.client_reviewed',
                'Advisory client decision: '.$decision.'. '.$note,
            );

            return $review;
        });
    }

    private function activeAccess(ModelCastingCall $call, User $client): ModelCastingClientAccess
    {
        $this->assertFeatureAndPolicy();
        abort_unless($client->role === 'client' && $client->is_active && $client->email_verified_at !== null, 404);
        abort_unless(in_array($call->status, ['open', 'closed'], true), 404);

        $access = ModelCastingClientAccess::query()
            ->where('call_id', $call->id)
            ->where('client_user_id', $client->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        abort_unless($access && $access->isActive(), 404);

        return $access;
    }

    private function assertSubmissionVisible(ModelCastingSubmission $submission, User $client): void
    {
        $this->assertFeatureAndPolicy();

        $call = $submission->call;
        $application = $submission->application;
        $asset = $submission->portfolioAsset;
        $licence = $submission->imageLicence;
        $agreement = $licence?->representationAgreement;

        $valid = $call
            && $application
            && $asset
            && $licence
            && $agreement
            && $submission->status !== 'invalidated'
            && $application->status === 'accepted'
            && $application->adult_eligibility_verified_at !== null
            && $application->legal_hold_at === null
            && $asset->status === 'approved'
            && $asset->consent_withdrawn_at === null
            && $asset->consent_policy_version === (string) config('talyvane.model_agency.portfolio_consent_version')
            && config('talyvane.model_agency.portfolio_consent_approved')
            && $licence->status === 'active'
            && $licence->purpose === 'casting_distribution'
            && in_array('client_review', $licence->channels ?? [], true)
            && $licence->application_id === $application->id
            && $licence->portfolio_asset_id === $asset->id
            && $licence->country_code === $call->country_code
            && $licence->starts_on->format('Y-m-d') <= $call->shoot_starts_at->format('Y-m-d')
            && $licence->ends_on->format('Y-m-d') >= $call->shoot_ends_at->format('Y-m-d')
            && $licence->licence_policy_version === (string) config('talyvane.model_agency.image_licence_policy_version')
            && $licence->model_consent_evidence_reference !== null
            && config('talyvane.model_agency.image_licence_policy_approved')
            && $agreement->status === 'executed'
            && $agreement->country_code === $call->country_code
            && $agreement->terms_policy_version === (string) config('talyvane.model_agency.representation_terms_version')
            && config('talyvane.model_agency.representation_terms_approved')
            && $agreement->effective_from
            && $agreement->effective_from->format('Y-m-d') <= $call->shoot_starts_at->format('Y-m-d')
            && (! $agreement->effective_until || $agreement->effective_until->format('Y-m-d') >= $call->shoot_ends_at->format('Y-m-d'))
            && $call->status !== 'cancelled'
            && config('talyvane.model_agency.casting_policy_approved')
            && $call->casting_policy_version === (string) config('talyvane.model_agency.casting_policy_version')
            && filled($asset->storage_path)
            && Storage::disk('local')->exists($asset->storage_path);

        if (! $valid) {
            abort(404);
        }

        $this->assertApprovedMarket($call->country_code);
    }

    private function assertApprovedMarket(string $countryCode): void
    {
        $market = CountryMarket::query()->where('country_code', $countryCode)->first();
        abort_unless($market && $market->isOperationallyApproved(), 404);
    }

    private function assertFeatureAndPolicy(): void
    {
        $days = (int) config('talyvane.model_agency.client_review_access_days', 30);
        abort_unless(
            app(FeatureRegistry::class)->isEnabled('model-agency.client-casting')
                && config('talyvane.model_agency.client_review_policy_approved')
                && filled(config('talyvane.model_agency.client_review_policy_version'))
                && $days >= 1 && $days <= 30,
            404,
        );
    }

    private function event(
        ModelCastingCall $call,
        ?int $submissionId,
        ?int $actorId,
        string $actorType,
        string $eventType,
        string $reason,
    ): void {
        ModelCastingEvent::query()->create([
            'call_id' => $call->id,
            'submission_id' => $submissionId,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'event_type' => $eventType,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
