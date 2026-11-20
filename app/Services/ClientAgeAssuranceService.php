<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ClientAgeAssuranceService
{
    /**
     * Fail closed until Phase 5 supplies a provider adapter, market policy and
     * durable verification evidence. A provider name or browser assertion alone
     * is never sufficient to activate adult-content access.
     */
    public function isOperationallyConfigured(): bool
    {
        $provider = (string) config('talyvane.age_assurance.client_provider', '');
        $providerClass = (string) config('talyvane.age_assurance.client_provider_class', '');
        $policyVersion = (string) config('talyvane.age_assurance.client_policy_version', '');

        if (
            $provider !== 'yoti'
            || ! filled($providerClass)
            || ! class_exists($providerClass)
            || ! filled($policyVersion)
            || ! config('talyvane.age_assurance.client_policy_approved')
            || (int) config('talyvane.age_assurance.client_minimum_age', 0) < 18
        ) {
            return false;
        }

        try {
            $providerAdapter = app($providerClass);

            if (! method_exists($providerAdapter, 'isConfigured') || $providerAdapter->isConfigured() !== true) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        if (
            ! filled(config('services.yoti.age_verification_api_key'))
            || ! filled(config('services.yoti.sdk_id'))
            || ! Schema::hasColumns('users', ['country_code', 'market_code'])
            || ! Schema::hasTable('market_age_assurance_policies')
            || ! Schema::hasColumns('market_age_assurance_policies', ['country_code', 'market_code', 'provider', 'minimum_age', 'policy_version', 'is_approved', 'is_active', 'effective_from', 'effective_until'])
            || ! Schema::hasTable('client_age_verifications')
            || ! Schema::hasColumns('client_age_verifications', ['user_id', 'provider', 'provider_reference_hash', 'country_code', 'market_code', 'age_threshold', 'policy_version', 'status', 'verified_at', 'expires_at', 'revoked_at'])
            || ! Schema::hasTable('client_age_verification_events')
            || ! Schema::hasColumns('client_age_verification_events', ['verification_id', 'user_id', 'actor_id', 'event_type', 'provider_reference_hash', 'policy_version', 'occurred_at'])
        ) {
            return false;
        }

        return true;
    }

    public function hasCurrentProof(User $client): bool
    {
        if (
            $client->role !== 'client'
            || ! $client->is_active
            || ! $this->isOperationallyConfigured()
        ) {
            return false;
        }

        $countryCode = strtoupper(trim((string) $client->getAttribute('country_code')));
        $marketCode = trim((string) $client->getAttribute('market_code'));

        if ($countryCode === '' || $marketCode === '') {
            return false;
        }

        $now = now();
        $minimumAge = (int) config('talyvane.age_assurance.client_minimum_age', 18);

        return DB::table('client_age_verifications as verification')
            ->join('market_age_assurance_policies as policy', function ($join): void {
                $join->on('policy.country_code', '=', 'verification.country_code')
                    ->on('policy.market_code', '=', 'verification.market_code')
                    ->on('policy.provider', '=', 'verification.provider')
                    ->on('policy.policy_version', '=', 'verification.policy_version');
            })
            ->where('verification.user_id', $client->id)
            ->where('verification.provider', config('talyvane.age_assurance.client_provider'))
            ->where('verification.country_code', $countryCode)
            ->where('verification.market_code', $marketCode)
            ->where('verification.status', 'verified')
            ->where('verification.age_threshold', '>=', $minimumAge)
            ->whereColumn('verification.age_threshold', '>=', 'policy.minimum_age')
            ->whereNotNull('verification.provider_reference_hash')
            ->where('verification.provider_reference_hash', '<>', '')
            ->whereNotNull('verification.verified_at')
            ->whereNotNull('verification.expires_at')
            ->where('verification.expires_at', '>', $now)
            ->whereNull('verification.revoked_at')
            ->whereExists(function ($events): void {
                $events->selectRaw('1')
                    ->from('client_age_verification_events as evidence')
                    ->whereColumn('evidence.verification_id', 'verification.id')
                    ->whereColumn('evidence.user_id', 'verification.user_id')
                    ->whereColumn('evidence.provider_reference_hash', 'verification.provider_reference_hash')
                    ->whereColumn('evidence.policy_version', 'verification.policy_version')
                    ->where('evidence.event_type', 'verified')
                    ->whereNotNull('evidence.occurred_at');
            })
            ->where('policy.is_approved', true)
            ->where('policy.is_active', true)
            ->where('policy.minimum_age', '>=', $minimumAge)
            ->where('policy.effective_from', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('policy.effective_until')
                    ->orWhere('policy.effective_until', '>', $now);
            })
            ->exists();
    }
}
