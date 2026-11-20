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
        if (! in_array($input['case_type'] ?? null, self::CASE_TYPES, true)) {
            throw ValidationException::withMessages(['case_type' => 'Choose a supported safeguarding case category.']);
        }

        if (! in_array($input['severity'] ?? null, ['low', 'medium', 'high', 'critical'], true)) {
            throw ValidationException::withMessages(['severity' => 'Choose a supported severity level.']);
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
        return SafeguardingCase::query()
            ->where('assigned_to', $specialist->id)
            ->latest('created_at')
            ->paginate(20, ['id', 'reference', 'case_type', 'severity', 'status', 'created_at']);
    }

    public function findAssigned(User $specialist, SafeguardingCase $case): SafeguardingCase
    {
        return SafeguardingCase::query()
            ->where('assigned_to', $specialist->id)
            ->whereKey($case->id)
            ->firstOrFail();
    }

    public function recordView(SafeguardingCase $case, User $specialist): void
    {
        SafeguardingCaseAccessEvent::query()->create([
            'case_id' => $case->id,
            'actor_id' => $specialist->id,
            'action' => 'case.viewed',
            'accessed_at' => now(),
        ]);
    }

    public function transition(SafeguardingCase $case, User $specialist, string $target, string $note): void
    {
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
