<?php

namespace App\Services;

final class MembershipEntitlementCatalog
{
    /**
     * Only entitlements with a complete server-side enforcement path belong here.
     * Adding a catalogue entry alone never grants access.
     */
    public static function all(): array
    {
        return [
            'media.private_gallery' => [
                'name' => 'Private gallery access',
                'description' => 'View approved, purpose-consented private gallery media while the membership and grant are current.',
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
