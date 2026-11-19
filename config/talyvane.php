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
    'model_agency' => [
        'privacy_notice_version' => env('MODEL_AGENCY_PRIVACY_NOTICE_VERSION'),
        'privacy_notice_approved' => filter_var(env('MODEL_AGENCY_PRIVACY_NOTICE_APPROVED', false), FILTER_VALIDATE_BOOL),
        'adult_eligibility_policy_version' => env('MODEL_AGENCY_ADULT_ELIGIBILITY_POLICY_VERSION'),
        'adult_eligibility_policy_approved' => filter_var(env('MODEL_AGENCY_ADULT_ELIGIBILITY_POLICY_APPROVED', false), FILTER_VALIDATE_BOOL),
        'representation_terms_version' => env('MODEL_AGENCY_REPRESENTATION_TERMS_VERSION'),
        'representation_terms_approved' => filter_var(env('MODEL_AGENCY_REPRESENTATION_TERMS_APPROVED', false), FILTER_VALIDATE_BOOL),
        'retention' => [
            'application_days' => env('MODEL_AGENCY_APPLICATION_RETENTION_DAYS'),
            'accepted_days' => env('MODEL_AGENCY_ACCEPTED_RETENTION_DAYS'),
            'schedule_approved' => filter_var(env('MODEL_AGENCY_RETENTION_SCHEDULE_APPROVED', false), FILTER_VALIDATE_BOOL),
        ],
    ],
];
