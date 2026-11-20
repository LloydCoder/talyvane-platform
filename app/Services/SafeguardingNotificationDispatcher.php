<?php

namespace App\Services;

use App\Models\SafeguardingCase;
use App\Models\SafeguardingNotificationOutbox;
use App\Models\User;
use App\Notifications\SafeguardingCaseAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class SafeguardingNotificationDispatcher
{
    private const MAX_ATTEMPTS = 8;

    public function dispatchPending(int $limit = 50): int
    {
        if (! config('talyvane.safeguarding.notifications_enabled')) {
            throw new RuntimeException('Safeguarding notifications are disabled until the mail transport has been verified by the deployment operator.');
        }

        SafeguardingNotificationOutbox::query()
            ->where('status', 'processing')
            ->where('processing_at', '<=', now()->subMinutes(15))
            ->update([
                'status' => 'pending',
                'processing_at' => null,
                'next_attempt_at' => now(),
            ]);

        $ids = SafeguardingNotificationOutbox::query()
            ->where('status', 'pending')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min($limit, 200)))
            ->pluck('id');

        $delivered = 0;

        foreach ($ids as $id) {
            $outbox = DB::transaction(function () use ($id): ?SafeguardingNotificationOutbox {
                $locked = SafeguardingNotificationOutbox::query()->lockForUpdate()->find($id);
                if (! $locked || $locked->status !== 'pending' || $locked->next_attempt_at->isFuture()) {
                    return null;
                }

                if ($locked->attempts >= self::MAX_ATTEMPTS) {
                    $locked->forceFill(['status' => 'failed', 'processing_at' => null])->save();

                    return null;
                }

                $locked->forceFill([
                    'status' => 'processing',
                    'attempts' => $locked->attempts + 1,
                    'processing_at' => now(),
                ])->save();

                return $locked->fresh();
            });

            if (! $outbox) {
                continue;
            }

            try {
                $case = SafeguardingCase::query()->findOrFail($outbox->case_id);
                $recipient = $this->resolveRecipient($outbox);

                Notification::route('mail', $recipient)->notify(new SafeguardingCaseAlert(
                    $case->reference,
                    $case->case_type,
                    $case->severity,
                    route('admin.safeguarding.cases.show', $case),
                ));

                $outbox->forceFill([
                    'status' => 'delivered',
                    'processing_at' => null,
                    'delivered_at' => now(),
                    'last_error_code' => null,
                ])->save();

                $delivered++;
            } catch (Throwable $exception) {
                $attempts = (int) $outbox->attempts;
                $delayMinutes = min(5 * (2 ** max(0, $attempts - 1)), 360);
                $outbox->forceFill([
                    'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                    'processing_at' => null,
                    'next_attempt_at' => now()->addMinutes($delayMinutes),
                    'last_error_code' => mb_substr(class_basename($exception), 0, 80),
                ])->save();
            }
        }

        return $delivered;
    }

    private function resolveRecipient(SafeguardingNotificationOutbox $outbox): string
    {
        if ($outbox->recipient_user_id !== null) {
            $user = User::query()->find($outbox->recipient_user_id);
            if (! $user
                || ! $user->is_active
                || $user->role !== 'safeguarding'
                || $user->mfa_enabled_at === null
                || ! filled($user->mfa_secret)) {
                throw new RuntimeException('Safeguarding recipient is no longer eligible for case notifications.');
            }

            $email = $user->email;
        } else {
            $email = $outbox->recipient_email;
        }

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Safeguarding notification recipient address is unavailable or invalid.');
        }

        return $email;
    }
}
