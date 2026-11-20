<?php

namespace App\Services;

/**
 * Controls that are security, privacy, safeguarding or financial-integrity invariants.
 *
 * These are deliberately not feature flags. Their enforcement must not be removable
 * through deployment configuration or a Super Admin feature toggle.
 */
final class HardSafeguards
{
    private const RESERVED_KEYS = [
        'adult-eligibility.assurance',
        'consent.withdrawal',
        'privacy.media-takedown',
        'privacy.data-rights',
        'safeguarding.case-access',
        'safeguarding.legal-holds',
        'safeguarding.retention',
        'safeguarding.notification-escalation',
        'payments.ledger-integrity',
        'audit.immutability',
    ];

    public static function contains(string $key): bool
    {
        return in_array($key, self::RESERVED_KEYS, true);
    }

    public static function keys(): array
    {
        return self::RESERVED_KEYS;
    }

    public static function blocker(): string
    {
        return 'Hard safeguards are not configurable feature flags.';
    }
}
