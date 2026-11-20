<?php

namespace App\Services;

use App\Models\SafeguardingCase;
use App\Models\SafeguardingCaseAccessEvent;
use App\Models\SafeguardingCaseEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SafeguardingCaseRetentionService
{
    public function __construct(private readonly SafeguardingCaseContentService $content)
    {
    }

    public function apply(): int
    {
        if (! config('talyvane.safeguarding.retention.schedule_approved')) {
            throw new RuntimeException('Safeguarding retention is disabled until the operator approves a documented retention schedule.');
        }

        $days = (int) config('talyvane.safeguarding.retention.days');
        $policyVersion = trim((string) config('talyvane.safeguarding.retention.policy_version'));
        $backupExpiryDays = (int) config('talyvane.safeguarding.retention.backup_expiry_days');

        if ($days < 1 || $policyVersion === '') {
            throw new RuntimeException('Safeguarding retention requires a positive day count and a named policy version.');
        }

        if (! config('talyvane.safeguarding.retention.backup_policy_approved') || $backupExpiryDays < 1) {
            throw new RuntimeException('Safeguarding retention requires an independently approved encrypted-backup expiry policy.');
        }

        $cutoff = now()->subDays($days);
        $erased = 0;

        SafeguardingCase::query()
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->where('closed_at', '<=', $cutoff)
            ->whereNull('legal_hold_at')
            ->whereHas('content', fn ($query) => $query
                ->whereNotNull('key_ciphertext')
                ->whereNull('retention_erased_at'))
            ->orderBy('id')
            ->chunkById(50, function ($cases) use (&$erased, $cutoff, $policyVersion): void {
                foreach ($cases as $case) {
                    if ($this->eraseIfDue($case->id, $cutoff, $policyVersion)) {
                        $erased++;
                    }
                }
            });

        return $erased;
    }

    private function eraseIfDue(int $caseId, CarbonInterface $cutoff, string $policyVersion): bool
    {
        return DB::transaction(function () use ($caseId, $cutoff, $policyVersion): bool {
            $case = SafeguardingCase::query()->lockForUpdate()->find($caseId);
            if (! $case
                || $case->status !== 'closed'
                || $case->closed_at === null
                || $case->closed_at->gt($cutoff)
                || $case->legal_hold_at !== null) {
                return false;
            }

            $content = DB::table('safeguarding_case_contents')
                ->where('case_id', $case->id)
                ->lockForUpdate()
                ->first();

            if (! $content || ! $content->key_ciphertext || $content->retention_erased_at !== null) {
                return false;
            }

            SafeguardingCaseEvent::query()->create([
                'case_id' => $case->id,
                'actor_id' => null,
                'event_type' => 'case.retention_erased',
                'from_status' => $case->status,
                'to_status' => $case->status,
                'note' => 'Sensitive case content key removed from the primary database under approved policy '.$policyVersion.'. Encrypted backup copies remain subject to the separately approved backup expiry schedule.',
                'occurred_at' => now(),
            ]);

            $this->content->eraseKey($case->id, $policyVersion);

            SafeguardingCaseAccessEvent::query()->create([
                'case_id' => $case->id,
                'actor_id' => null,
                'action' => 'case.retention_erased',
                'accessed_at' => now(),
            ]);

            return true;
        });
    }
}
