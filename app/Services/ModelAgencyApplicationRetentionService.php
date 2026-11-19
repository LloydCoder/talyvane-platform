<?php

namespace App\Services;

use App\Models\ModelTalentApplication;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ModelAgencyApplicationRetentionService
{
    public function __construct(private readonly ModelAgencyApplicationLifecycleService $lifecycle)
    {
    }

    public function apply(): int
    {
        if (! config('talyvane.model_agency.retention.schedule_approved')) {
            throw new \\RuntimeException('Model agency retention execution is disabled because the operator has not approved the retention schedule.');
        }

        $applicationDays = (int) config('talyvane.model_agency.retention.application_days');
        $acceptedDays = (int) config('talyvane.model_agency.retention.accepted_days');

        if ($applicationDays < 1 || $acceptedDays < 1) {
            throw new \RuntimeException('Model agency retention periods must be configured to positive day counts.');
        }

        $applicationCutoff = now()->subDays($applicationDays);
        $acceptedCutoff = now()->subDays($acceptedDays);
        $anonymized = 0;

        ModelTalentApplication::query()
            ->whereNull('legal_hold_at')
            ->where('status', '!=', 'erased')
            ->where(function ($query) use ($applicationCutoff, $acceptedCutoff): void {
                $query->where(function ($query) use ($acceptedCutoff): void {
                    $query->where('status', 'accepted')->whereRaw('COALESCE(reviewed_at, submitted_at) <= ?', [$acceptedCutoff]);
                })->orWhere(function ($query) use ($applicationCutoff): void {
                    $query->where('status', '!=', 'accepted')->whereRaw('COALESCE(reviewed_at, submitted_at) <= ?', [$applicationCutoff]);
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($applications) use (&$anonymized, $applicationCutoff, $acceptedCutoff): void {
                foreach ($applications as $application) {
                    if ($this->anonymizeIfDue($application->id, $applicationCutoff, $acceptedCutoff)) {
                        $anonymized++;
                    }
                }
            });

        return $anonymized;
    }

    /**
     * Re-evaluate retention eligibility under the same row lock used for anonymization.
     * The initial candidate query is only a performance optimization and is never authority
     * to erase a record after its status, decision timestamp or legal-hold state changes.
     */
    public function anonymizeIfDue(
        int $applicationId,
        CarbonInterface $applicationCutoff,
        CarbonInterface $acceptedCutoff,
    ): bool {
        return DB::transaction(function () use ($applicationId, $applicationCutoff, $acceptedCutoff): bool {
            $application = ModelTalentApplication::query()->lockForUpdate()->find($applicationId);

            if (! $application || $application->status === 'erased' || $application->legal_hold_at !== null) {
                return false;
            }

            $retentionClock = $application->reviewed_at ?? $application->submitted_at;

            if ($retentionClock === null) {
                return false;
            }

            $cutoff = $application->status === 'accepted' ? $acceptedCutoff : $applicationCutoff;

            if ($retentionClock->gt($cutoff)) {
                return false;
            }

            $this->lifecycle->anonymize($application, 'system', null, 'application.retained');

            return true;
        });
    }
}
