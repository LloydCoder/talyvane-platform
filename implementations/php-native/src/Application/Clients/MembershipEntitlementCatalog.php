<?php
declare(strict_types=1);

namespace Talyvane\\Application\\Clients;

/**
 * Only entitlements with a complete server-side enforcement path belong here.
 * Display labels and membership tier names never confer authorization.
 */
final class MembershipEntitlementCatalog
{
    public const PRIVATE_GALLERY_POLICY_VERSION='members-only-gallery-v1';

    /** @return array<string,array{name:string,description:string}> */
    public static function all(): array
    {
        return [
            'media.private_gallery'=>[
                'name'=>'Private gallery access',
                'description'=>'View approved, purpose-consented private gallery media while the membership and grant are current.',
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function privateGalleryConsentStatement(?string $agencyName=null): string
    {
        $agency=trim((string)($agencyName??'Talyvane'));
        if ($agency==='' || strlen($agency)>190) { $agency='Talyvane'; }
        return 'I separately agree that '.$agency.' may display this image only in its members-only gallery to eligible clients with a current agency-approved membership, as described in the current privacy notice. This permission is separate from public profile publication and may be withdrawn at any time.';
    }
}
