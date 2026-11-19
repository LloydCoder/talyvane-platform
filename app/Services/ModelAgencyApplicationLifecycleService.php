<?php

namespace App\Services;

use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ModelAgencyApplicationLifecycleService
{
    public function withdraw(ModelTalentApplication $application, string $actorType = 'applicant', ?int $actorId = null): void
    {
        DB::transaction(function () use ($application, $actorType, $actorId): void {
            $locked = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);

            if (in_array($locked->status, ['withdrawn', 'erased'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This application has already been withdrawn or erased.',
                ]);
            }

            $from = $locked->status;
            $locked->forceFill(['status' => 'withdrawn'])->save();

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $locked->id,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'event_type' => 'application.withdrawn',
                'from_status' => $from,
                'to_status' => 'withdrawn',
                'occurred_at' => now(),
            ]);
        });
    }

    public function anonymize(
        ModelTalentApplication $application,
        string $actorType = 'applicant',
        ?int $actorId = null,
        string $eventType = 'application.erased',
    ): void {
        DB::transaction(function () use ($application, $actorType, $actorId, $eventType): void {
            $locked = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);

            if ($locked->status === 'erased') {
                return;
            }

            if ($locked->legal_hold_at !== null) {
                throw ValidationException::withMessages([
                    'status' => 'This application is subject to a documented legal hold and cannot be erased yet.',
                ]);
            }

            if ($locked->status === 'erased') {
                return;
            }

            $from = $locked->status;

            // Privacy erasure is the narrowly scoped exception to the event model's normal append-only rule.
            DB::table('model_talent_application_events')
                ->where('application_id', $locked->id)
                ->update(['reason' => null]);

            $locked->forceFill([
                'reference' => 'MTA-ERASED-'.$locked->id,
                'full_name' => 'Erased applicant',
                'email' => 'erased-'.$locked->id.'@invalid.example',
                'phone' => null,
                'country_code' => 'ZZ',
                'city' => 'Erased',
                'portfolio_url' => null,
                'experience_summary' => 'Personal data erased under the approved lifecycle process.',
                'status' => 'erased',
                'adult_eligibility_confirmed_at' => null,
                'adult_eligibility_policy_accepted_at' => null,
                'privacy_notice_accepted_at' => null,
                'review_note' => null,
                'legal_hold_at' => null,
                'legal_hold_by' => null,
                'legal_hold_reason' => null,
            ])->save();

            DB::table('model_talent_application_rights_requests')
                ->where('application_id', $locked->id)
                ->whereIn('status', ['pending', 'verified'])
                ->update([
                    'status' => 'completed',
                    'code_hash' => '',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $locked->id,
                'actor_id' => $actorId,
                'actor_type' => $actorType,
                'event_type' => $eventType,
                'from_status' => $from,
                'to_status' => 'erased',
                'occurred_at' => now(),
            ]);
        });
    }
}
