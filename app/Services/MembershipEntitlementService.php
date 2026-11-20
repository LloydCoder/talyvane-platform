<?php

namespace App\Services;

use App\Models\ClientMembershipEntitlement;
use App\Models\User;

class MembershipEntitlementService
{
    public function has(User $client, string $key): bool
    {
        // This resolver is an authorization boundary, not merely a data lookup.
        // Re-evaluate the effective feature state here so callers cannot bypass
        // the Super Admin gate by invoking the service outside its HTTP controller.
        if (! app(FeatureRegistry::class)->isOperationallyEnabled('memberships.entitlements')) {
            return false;
        }

        if (
            $client->role !== 'client'
            || ! $client->is_active
            || ! $client->hasVerifiedEmail()
            || ! in_array($key, MembershipEntitlementCatalog::keys(), true)
        ) {
            return false;
        }

        $now = now();

        return ClientMembershipEntitlement::query()
            ->where('entitlement_key', $key)
            ->whereHas('membership', fn ($membership) => $membership
                ->where('user_id', $client->id)
                ->current())
            ->where(fn ($grant) => $grant->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($grant) => $grant->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->exists();
    }
}
