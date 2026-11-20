<?php

namespace App\Services;

use App\Models\SafeguardingCase;
use App\Models\SafeguardingCaseAccessEvent;
use App\Models\SafeguardingCaseEvent;
use App\Models\SafeguardingNotificationOutbox;
use App\Models\User;

class SafeguardingNotificationOutboxService
{
    public function enqueueCaseCreated(SafeguardingCase $case, User $specialist): void
    {
        $this->enqueue(
            $case,
            'case_assigned',
            'user:'.$specialist->id,
            $specialist->id,
            null,
        );

        $requiresEscalation = $case->severity === 'critical' || $case->case_type === 'minor_safety_concern';
        if (! $requiresEscalation) {
            return;
        }

        $contacts = array_values(array_unique(array_filter(
            config('talyvane.safeguarding.emergency_contact_emails', []),
            fn ($email): bool => is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false,
        )));

        foreach ($contacts as $email) {
            $this->enqueue(
                $case,
                'critical_escalation',
                'email:'.strtolower(trim($email)),
                null,
                trim($email),
            );
        }

        if ($contacts === []) {
            SafeguardingCaseEvent::query()->create([
                'case_id' => $case->id,
                'actor_id' => null,
                'event_type' => 'case.escalation_contact_unconfigured',
                'from_status' => $case->status,
                'to_status' => $case->status,
                'note' => 'No emergency escalation email contacts were configured when this critical safeguarding case was created.',
                'occurred_at' => now(),
            ]);

            SafeguardingCaseAccessEvent::query()->create([
                'case_id' => $case->id,
                'actor_id' => null,
                'action' => 'case.escalation_unconfigured',
                'accessed_at' => now(),
            ]);
        }
    }

    private function enqueue(
        SafeguardingCase $case,
        string $type,
        string $recipientIdentity,
        ?int $recipientUserId,
        ?string $recipientEmail,
    ): void {
        $recipientKey = hash_hmac('sha256', $recipientIdentity, (string) config('app.key'));

        SafeguardingNotificationOutbox::query()->firstOrCreate(
            [
                'case_id' => $case->id,
                'notification_type' => $type,
                'recipient_key' => $recipientKey,
            ],
            [
                'recipient_user_id' => $recipientUserId,
                'recipient_email' => $recipientEmail,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
            ],
        );
    }
}
