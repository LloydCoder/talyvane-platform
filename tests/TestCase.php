<?php

namespace Tests;

use App\Models\User;
use App\Models\Destination;
use App\Models\CountryMarket;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{

    protected function approveCountryMarket(Destination $destination): CountryMarket
    {
        return CountryMarket::query()->updateOrCreate(
            ['country_code' => $destination->country_code],
            [
                'name' => $destination->country_name,
                'is_enabled' => true,
                'compliance_status' => 'approved',
                'compliance_reviewed_at' => now(),
                'compliance_reviewed_by' => 'test-fixture',
                'compliance_evidence_reference' => 'test-fixture-only-'.$destination->country_code,
                'compliance_review_summary' => 'Test fixture only; not valid evidence or legal approval for any real market.',
                'market_policy' => ['test_fixture' => true],
            ],
        );
    }

    protected function createAdminUser(array $attributes = []): User
    {
        $user = User::query()->create(array_merge([
            'name' => 'Test Administrator',
            'email' => 'admin@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'admin',
            'is_active' => true,
        ], $attributes));

        $user->forceFill([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_enabled_at' => now(),
            'mfa_last_used_step' => intdiv(time(), 30) - 1,
            'mfa_recovery_codes' => [],
        ])->save();

        return $user;
    }
}
