<?php

namespace App\Services;

use App\Models\ModelTalentApplicationEvent;
use App\Models\ModelTalentApplicationRightsRequest;
use App\Models\SitePage;
use App\Notifications\ModelAgencySarExtensionNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ModelAgencySarResponseService
{
    private const REQUIRED_RESPONSE_SECTIONS = [
        'request',
        'applicant',
        'application_history',
        'related_privacy_requests',
        'supplementary_information',
        'external_records',
    ];

    private const REQUIRED_REVIEW_CHECKS = [
        'platform_records_searched',
        'external_records_searched',
        'third_party_redactions_reviewed',
        'supplementary_information_reviewed',
    ];

    public function draft(ModelTalentApplicationRightsRequest $rightsRequest): string
    {
        if ($rightsRequest->request_type !== 'access') {
            throw new RuntimeException('Only applicant access requests can have a subject-access response.');
        }

        $application = $rightsRequest->application;
        $privacyNotice = SitePage::query()->where('slug', 'model-applicant-privacy')->where('is_published', true)->first();

        $payload = [
            'schema_version' => 'talyvane-sar-v1',
            'request' => [
                'application_reference' => $application->reference,
                'request_type' => 'subject_access_request',
                'received_at' => $rightsRequest->created_at?->toIso8601String(),
                'response_due_at' => $rightsRequest->response_due_at?->toIso8601String(),
                'response_status' => $rightsRequest->response_status,
            ],
            'applicant' => [
                'application_reference' => $application->reference,
                'full_name' => $application->full_name,
                'email' => $application->email,
                'phone' => $application->phone,
                'country_code' => $application->country_code,
                'city' => $application->city,
                'portfolio_url' => $application->portfolio_url,
                'experience_summary' => $application->experience_summary,
                'application_status' => $application->status,
                'submitted_at' => $application->submitted_at?->toIso8601String(),
                'adult_eligibility_self_attested_at' => $application->adult_eligibility_confirmed_at?->toIso8601String(),
                'adult_eligibility_policy_version' => $application->adult_eligibility_policy_version,
                'adult_eligibility_policy_accepted_at' => $application->adult_eligibility_policy_accepted_at?->toIso8601String(),
                'privacy_notice_version' => $application->privacy_notice_version,
                'privacy_notice_accepted_at' => $application->privacy_notice_accepted_at?->toIso8601String(),
                'reviewed_at' => $application->reviewed_at?->toIso8601String(),
                'review_note' => $application->review_note,
                'legal_hold_at' => $application->legal_hold_at?->toIso8601String(),
                'legal_hold_reason' => $application->legal_hold_reason,
            ],
            'application_history' => $application->events()
                ->orderBy('occurred_at')
                ->get()
                ->map(fn ($event): array => [
                    'event_type' => $event->event_type,
                    'actor_type' => $event->actor_type,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'reason' => $event->reason,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ])->all(),
            'related_privacy_requests' => $application->rightsRequests()
                ->where('id', '!=', $rightsRequest->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (ModelTalentApplicationRightsRequest $item): array => [
                    'request_type' => $item->request_type,
                    'status' => $item->status,
                    'created_at' => $item->created_at?->toIso8601String(),
                    'verified_at' => $item->verified_at?->toIso8601String(),
                    'completed_at' => $item->completed_at?->toIso8601String(),
                    'response_status' => $item->response_status,
                    'response_responded_at' => $item->response_responded_at?->toIso8601String(),
                ])->all(),
            'supplementary_information' => [
                'published_privacy_notice_version' => $application->privacy_notice_version,
                'published_privacy_notice' => $privacyNotice ? strip_tags((string) $privacyNotice->body) : null,
                'privacy_notice_path' => '/pages/model-applicant-privacy',
                'operator_note' => 'The controller must ensure the response includes applicable purposes, lawful bases, recipient categories, retention, data-subject rights, complaint routes and transfer information. Review the published notice and update this section if required.',
            ],
            'external_records' => [
                'records' => [],
                'operator_note' => 'Search the agency mailboxes, CRM, accounting, communication systems, paper files and all other relevant systems. Add any personal data found outside this platform before preparing the response.',
            ],
            'third_party_review' => [
                'review_required' => true,
                'operator_note' => 'Review every field and event reason for third-party information, legally privileged material and applicable exemptions. Redact only where a lawful basis permits.',
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, bool> $reviewChecklist
     */
    public function prepare(
        ModelTalentApplicationRightsRequest $rightsRequest,
        int $actorId,
        string $content,
        array $reviewChecklist,
    ): string {
        $decoded = json_decode($content, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages(['response_content' => 'The subject-access response must be valid JSON.']);
        }

        foreach (self::REQUIRED_RESPONSE_SECTIONS as $section) {
            if (! array_key_exists($section, $decoded)) {
                throw ValidationException::withMessages(['response_content' => 'The response is missing a required section: '.$section.'.']);
            }
        }

        foreach (self::REQUIRED_REVIEW_CHECKS as $check) {
            if (($reviewChecklist[$check] ?? false) !== true) {
                throw ValidationException::withMessages([$check => 'Every subject-access review check must be confirmed before preparation.']);
            }
        }

        $reference = (string) data_get($decoded, 'applicant.application_reference');
        if (! hash_equals($rightsRequest->application->reference, $reference)) {
            throw ValidationException::withMessages(['response_content' => 'The response applicant reference does not match this request.']);
        }

        $content = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sha256 = hash('sha256', $content);

        DB::transaction(function () use ($rightsRequest, $actorId, $content, $reviewChecklist, $sha256): void {
            $locked = ModelTalentApplicationRightsRequest::query()->lockForUpdate()->findOrFail($rightsRequest->id);
            $application = $locked->application()->lockForUpdate()->firstOrFail();

            if ($locked->request_type !== 'access'
                || ! in_array($locked->status, ['verified', 'completed'], true)
                || $locked->response_status !== 'pending'
                || $application->status === 'erased'
                || $locked->response_due_at === null) {
                throw ValidationException::withMessages(['response_content' => 'This access request is not eligible for response preparation.']);
            }

            $locked->forceFill([
                'response_status' => 'prepared',
                'response_content' => $content,
                'response_sha256' => $sha256,
                'response_reviewed_by' => $actorId,
                'response_reviewed_at' => now(),
                'response_review_checklist' => $reviewChecklist,
            ])->save();

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $application->id,
                'actor_id' => $actorId,
                'actor_type' => 'administrator',
                'event_type' => 'application.sar_prepared',
                'from_status' => $application->status,
                'to_status' => $application->status,
                'reason' => 'Subject-access response prepared. SHA-256: '.$sha256,
                'occurred_at' => now(),
            ]);
        });

        return $sha256;
    }

    public function downloadContent(ModelTalentApplicationRightsRequest $rightsRequest): string
    {
        if ($rightsRequest->request_type !== 'access'
            || $rightsRequest->response_status !== 'prepared'
            || blank($rightsRequest->response_content)
            || blank($rightsRequest->response_sha256)
            || ! hash_equals($rightsRequest->response_sha256, hash('sha256', $rightsRequest->response_content))) {
            throw new RuntimeException('The reviewed subject-access response is unavailable or failed its integrity check.');
        }

        return $rightsRequest->response_content;
    }

    public function fulfil(
        ModelTalentApplicationRightsRequest $rightsRequest,
        int $actorId,
        string $decision,
        string $deliveryMethod,
        string $deliveryReference,
        string $reason,
    ): void {
        DB::transaction(function () use ($rightsRequest, $actorId, $decision, $deliveryMethod, $deliveryReference, $reason): void {
            $locked = ModelTalentApplicationRightsRequest::query()->lockForUpdate()->findOrFail($rightsRequest->id);
            $application = $locked->application()->lockForUpdate()->firstOrFail();

            if ($locked->request_type !== 'access'
                || $locked->response_status !== 'prepared'
                || $application->status === 'erased'
                || blank($locked->response_content)
                || ! hash_equals((string) $locked->response_sha256, hash('sha256', $locked->response_content))) {
                throw ValidationException::withMessages(['decision' => 'Only an intact, reviewed response can be delivered.']);
            }

            $locked->forceFill([
                'response_status' => $decision,
                'response_responded_at' => now(),
                'response_delivery_method' => $deliveryMethod,
                'response_delivery_reference_hash' => hash_hmac('sha256', trim($deliveryReference), (string) config('app.key')),
                'response_decision_reason' => $reason,
                'response_delivered_by' => $actorId,
                'response_content' => null,
            ])->save();

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $application->id,
                'actor_id' => $actorId,
                'actor_type' => 'administrator',
                'event_type' => 'application.sar_'.$decision,
                'from_status' => $application->status,
                'to_status' => $application->status,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
        });
    }

    public function extend(
        ModelTalentApplicationRightsRequest $rightsRequest,
        int $actorId,
        string $reason,
    ): void {
        DB::transaction(function () use ($rightsRequest, $actorId, $reason): void {
            $locked = ModelTalentApplicationRightsRequest::query()->lockForUpdate()->findOrFail($rightsRequest->id);
            $application = $locked->application()->lockForUpdate()->firstOrFail();

            if ($locked->request_type !== 'access'
                || ! in_array($locked->response_status, ['pending', 'prepared'], true)
                || $locked->response_extended_at !== null
                || $locked->response_due_at === null
                || now()->greaterThan($locked->created_at->copy()->addMonthNoOverflow())
                || $application->status === 'erased') {
                throw ValidationException::withMessages(['response_due_at' => 'This request is not eligible for a statutory response extension.']);
            }

            $newDueAt = $locked->response_due_at->addMonthsNoOverflow(2);
            $locked->forceFill([
                'response_due_at' => $newDueAt,
                'response_extended_at' => now(),
                'response_extension_reason' => $reason,
            ])->save();

            Notification::route('mail', $application->email)->notify(
                new ModelAgencySarExtensionNotice($newDueAt->toDayDateTimeString()),
            );

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $application->id,
                'actor_id' => $actorId,
                'actor_type' => 'administrator',
                'event_type' => 'application.sar_extended',
                'from_status' => $application->status,
                'to_status' => $application->status,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
        });
    }
}
