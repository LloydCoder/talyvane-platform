<?php

namespace App\\Services;

use App\\Models\\ClientMembership;
use App\\Models\\ClientMembershipEvent;
use App\\Models\\User;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;

class ClientMembershipService
{
    public function assign(User $client, User $actor, array $attributes): ClientMembership
    {
        return DB::transaction(function () use ($client, $actor, $attributes): ClientMembership {
            app(FeatureRegistry::class)->assertOperationallyEnabled('memberships');

            $lockedClient = User::query()->lockForUpdate()->findOrFail($client->id);
            abort_unless($lockedClient->role === 'client', 404);

            $correlationId = (string) Str::uuid();
            $activeMemberships = ClientMembership::query()
                ->where('user_id', $lockedClient->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($activeMemberships as $previous) {
                $before = $this->snapshot($previous);
                $previous->forceFill(['status' => 'superseded'])->save();

                $this->recordEvent(
                    $previous,
                    $lockedClient,
                    $actor,
                    'membership.superseded',
                    $before,
                    $this->snapshot($previous),
                    'Previous active membership superseded by a new agency assignment.',
                    $correlationId,
                );
            }

            $membership = ClientMembership::query()->create([
                ...$attributes,
                'user_id' => $lockedClient->id,
                'assigned_by' => $actor->id,
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'] ?? null,
            ]);

            $this->recordEvent(
                $membership,
                $lockedClient,
                $actor,
                $membership->status === 'suspended' ? 'membership.suspended' : 'membership.assigned',
                null,
                $this->snapshot($membership),
                'Membership assigned by an authorized agency operator.',
                $correlationId,
            );

            return $membership;
        });
    }

    private function snapshot(ClientMembership $membership): array
    {
        return [
            'tier_name' => $membership->tier_name,
            'benefits_summary' => $membership->benefits_summary,
            'status' => $membership->status,
            'starts_at' => $membership->starts_at?->toIso8601String(),
            'ends_at' => $membership->ends_at?->toIso8601String(),
        ];
    }

    private function recordEvent(
        ClientMembership $membership,
        User $client,
        User $actor,
        string $eventType,
        ?array $before,
        ?array $after,
        string $reason,
        string $correlationId,
    ): void {
        ClientMembershipEvent::query()->create([
            'membership_id' => $membership->id,
            'client_id' => $client->id,
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'event_type' => $eventType,
            'before_state' => $before,
            'after_state' => $after,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'created_at' => now(),
        ]);
    }
}
