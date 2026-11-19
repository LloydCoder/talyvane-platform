<?php

return [
    'brand' => [
        'brand_name' => env('BRAND_NAME', 'Talyvane'),
        'tagline' => env('BRAND_TAGLINE', 'Independent operations. Considered design.'),
        'contact_email' => env('BRAND_CONTACT_EMAIL', ''),
        'contact_phone' => env('BRAND_CONTACT_PHONE', ''),
        'contact_website' => env('BRAND_CONTACT_WEBSITE', ''),
        'logo_path' => null,
    ],
    'verification' => [
        // Configure only after an approved provider integration is implemented and reviewed.
        'approved_providers' => array_values(array_filter(explode(',', (string) env('VERIFICATION_APPROVED_PROVIDERS', '')))),
    ],
    'privacy' => [
        'profile_consent_version' => env('PROFILE_CONSENT_VERSION', 'profile-publication-v1'),
    ],
];
