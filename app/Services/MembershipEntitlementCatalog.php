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

    public static function privateGalleryConsentStatement(): string
    {
        return 'I separately agree that this image may be displayed only in the members-only gallery to eligible clients with a current agency-approved membership. This permission is separate from public profile publication and may be withdrawn at any time.';
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
