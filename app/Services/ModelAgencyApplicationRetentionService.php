<?php

namespace App\Services;

use App\Models\ModelTalentApplication;

class ModelAgencyApplicationRetentionService
{
    public function __construct(private readonly ModelAgencyApplicationLifecycleService $lifecycle)
    {
    }

    public function apply(): int
    {
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
            ->chunkById(100, function ($applications) use (&$anonymized): void {
                foreach ($applications as $application) {
                    $this->lifecycle->anonymize($application, 'system', null, 'application.retained');
                    $anonymized++;
                }
            });

        return $anonymized;
    }
}
