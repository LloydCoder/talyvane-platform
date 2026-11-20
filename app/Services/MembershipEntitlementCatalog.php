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

    public static function privateGalleryConsentStatement(?string $agencyName = null): string
    {
        $agency = trim((string) ($agencyName ?? config('talyvane.brand.brand_name', 'Talyvane')));

        return 'I separately agree that '.$agency.' may display this image only in its members-only gallery to eligible clients with a current agency-approved membership, as described in the current privacy notice. This permission is separate from public profile publication and may be withdrawn at any time.';
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
