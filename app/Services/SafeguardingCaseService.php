<?php

namespace App\Services;

use App\Models\SafeguardingCase;
use App\Models\SafeguardingCaseAccessEvent;
use App\Models\SafeguardingCaseEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SafeguardingCaseService
{
    private const TRANSITIONS = [
        'open' => ['triaged'],
        'triaged' => ['investigating', 'action_required', 'resolved'],
        'investigating' => ['action_required', 'resolved'],
        'action_required' => ['investigating', 'resolved'],
        'resolved' => ['closed'],
        'closed' => [],
    ];

    private const CASE_TYPES = [
        'consent_concern',
        'coercion_or_exploitation',
        'trafficking_concern',
        'minor_safety_concern',
        'nonconsensual_content',
        'threat_or_harassment',
        'privacy_or_data_risk',
        'other',
    ];

    public function create(User $reporter, array $input): SafeguardingCase
    {
        abort_unless($reporter->is_active && in_array($reporter->role, ['super_admin', 'owner', 'admin'], true), 403);
        if (! in_array($input['case_type'] ?? null, self::CASE_TYPES, true)) {
            throw ValidationException::withMessages(['case_type' => 'Choose a supported safeguarding case category.']);
        }

        if (! in_array($input['severity'] ?? null, ['low', 'medium', 'high', 'critical'], true)) {
            throw ValidationException::withMessages(['severity' => 'Choose a supported severity level.']);
        }

        if (mb_strlen(trim((string) ($input['summary'] ?? ''))) < 20
            || mb_strlen(trim((string) ($input['summary'] ?? ''))) > 300
            || mb_strlen(trim((string) ($input['details'] ?? ''))) < 30
            || mb_strlen(trim((string) ($input['details'] ?? ''))) > 5000
            || ! in_array($input['subject_type'] ?? null, [null, 'model_application', 'companion_profile', 'booking', 'client', 'other'], true)
            || mb_strlen(trim((string) ($input['subject_reference'] ?? ''))) > 160) {
            throw ValidationException::withMessages(['details' => 'Provide a concise summary, necessary report details and a supported related-record reference.']);
        }

        return DB::transaction(function () use ($reporter, $input): SafeguardingCase {
            $specialist = User::query()
                ->whereKey($input['assigned_to'])
                ->where('role', 'safeguarding')
                ->where('is_active', true)
                ->whereNotNull('mfa_enabled_at')
                ->whereNotNull('mfa_secret')
                ->lockForUpdate()
                ->first();

            if (! $specialist) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'Assign the report to an active safeguarding specialist with MFA already enrolled.',
                ]);
            }

            $case = SafeguardingCase::query()->create([
                'reference' => 'SGC-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
                'case_type' => $input['case_type'],
                'severity' => $input['severity'],
                'status' => 'open',
                'subject_type' => $input['subject_type'] ?? null,
                'subject_reference' => filled($input['subject_reference'] ?? null) ? trim((string) $input['subject_reference']) : null,
                'summary' => trim((string) $input['summary']),
                'details' => trim((string) $input['details']),
                'assigned_to' => $specialist->id,
                'created_by' => $reporter->id,
            ]);

            $this->event($case, $reporter->id, 'case.created', null, 'open', 'Confidential safeguarding report created and assigned.');

            return $case;
        });
    }

    public function assignedCases(User $specialist)
    {
        $this->assertSpecialist($specialist);
        return SafeguardingCase::query()
            ->where('assigned_to', $specialist->id)
            ->latest('created_at')
            ->paginate(20, ['id', 'reference', 'case_type', 'severity', 'status', 'created_at']);
    }

    public function findAssigned(User $specialist, SafeguardingCase $case): SafeguardingCase
    {
        $this->assertSpecialist($specialist);
        return SafeguardingCase::query()
            ->where('assigned_to', $specialist->id)
            ->whereKey($case->id)
            ->firstOrFail();
    }

    public function recordView(SafeguardingCase $case, User $specialist): void
    {
        $this->assertSpecialist($specialist);
        SafeguardingCaseAccessEvent::query()->create([
            'case_id' => $case->id,
            'actor_id' => $specialist->id,
            'action' => 'case.viewed',
            'accessed_at' => now(),
        ]);
    }

    public function transition(SafeguardingCase $case, User $specialist, string $target, string $note): void
    {
        $this->assertSpecialist($specialist);
        if (mb_strlen(trim($note)) < 20 || mb_strlen(trim($note)) > 1500) {
            throw ValidationException::withMessages(['note' => 'A case action note between 20 and 1,500 characters is required.']);
        }

        DB::transaction(function () use ($case, $specialist, $target, $note): void {
            $locked = SafeguardingCase::query()
                ->where('assigned_to', $specialist->id)
                ->whereKey($case->id)
                ->lockForUpdate()
                ->firstOrFail();

            $allowed = self::TRANSITIONS[$locked->status] ?? [];
            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'This safeguarding case cannot move from its current state to the requested state.',
                ]);
            }

            $from = $locked->status;
            $resolvedAt = in_array($target, ['resolved', 'closed'], true) ? ($locked->resolved_at ?? now()) : null;
            $locked->forceFill([
                'status' => $target,
                'resolved_at' => $resolvedAt,
                'closed_at' => $target === 'closed' ? now() : null,
            ])->save();

            $this->event($locked, $specialist->id, 'case.status_changed', $from, $target, trim($note));
        });
    }

    public function placeLegalHold(SafeguardingCase $case, User $actor, string $reason): void
    {
        $this->assertGovernanceActor($actor);
        $this->assertHoldReason($reason);

        DB::transaction(function () use ($case, $actor, $reason): void {
            $locked = SafeguardingCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->legal_hold_at !== null) {
                throw ValidationException::withMessages(['legal_hold' => 'This case is already under legal hold.']);
            }

            $locked->forceFill([
                'legal_hold_at' => now(),
                'legal_hold_by' => $actor->id,
                'legal_hold_reason' => trim($reason),
                'legal_hold_released_at' => null,
                'legal_hold_released_by' => null,
                'legal_hold_release_reason' => null,
            ])->save();

            $this->event($locked, $actor->id, 'case.legal_hold_placed', $locked->status, $locked->status, trim($reason));
            SafeguardingCaseAccessEvent::query()->create([
                'case_id' => $locked->id,
                'actor_id' => $actor->id,
                'action' => 'case.legal_hold_placed',
                'accessed_at' => now(),
            ]);
        });
    }

    public function releaseLegalHold(SafeguardingCase $case, User $actor, string $reason): void
    {
        $this->assertGovernanceActor($actor);
        $this->assertHoldReason($reason);

        DB::transaction(function () use ($case, $actor, $reason): void {
            $locked = SafeguardingCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($locked->legal_hold_at === null) {
                throw ValidationException::withMessages(['legal_hold' => 'This case is not currently under legal hold.']);
            }

            abort_unless((int) $locked->legal_hold_by !== (int) $actor->id, 403);

            $locked->forceFill([
                'legal_hold_at' => null,
                'legal_hold_by' => null,
                'legal_hold_reason' => null,
                'legal_hold_released_at' => now(),
                'legal_hold_released_by' => $actor->id,
                'legal_hold_release_reason' => trim($reason),
            ])->save();

            $this->event($locked, $actor->id, 'case.legal_hold_released', $locked->status, $locked->status, trim($reason));
            SafeguardingCaseAccessEvent::query()->create([
                'case_id' => $locked->id,
                'actor_id' => $actor->id,
                'action' => 'case.legal_hold_released',
                'accessed_at' => now(),
            ]);
        });
    }

    private function assertGovernanceActor(User $actor): void
    {
        abort_unless($actor->is_active && in_array($actor->role, ['super_admin', 'owner'], true), 403);
    }

    private function assertHoldReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 20 || mb_strlen(trim($reason)) > 1000) {
            throw ValidationException::withMessages(['reason' => 'A reason between 20 and 1,000 characters is required.']);
        }
    }

    private function assertSpecialist(User $specialist): void
    {
        abort_unless(
            $specialist->is_active
                && $specialist->role === 'safeguarding'
                && $specialist->mfa_enabled_at !== null
                && filled($specialist->mfa_secret),
            403,
        );
    }

    private function event(
        SafeguardingCase $case,
        ?int $actorId,
        string $type,
        ?string $from,
        ?string $to,
        string $note,
    ): void {
        SafeguardingCaseEvent::query()->create([
            'case_id' => $case->id,
            'actor_id' => $actorId,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }
}
