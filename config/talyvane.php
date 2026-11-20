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
    'safeguarding' => [
        'retention' => [
            'days' => (int) env('SAFEGUARDING_CASE_RETENTION_DAYS', 0),
            'policy_version' => env('SAFEGUARDING_CASE_RETENTION_POLICY_VERSION'),
            'schedule_approved' => filter_var(env('SAFEGUARDING_CASE_RETENTION_SCHEDULE_APPROVED', false), FILTER_VALIDATE_BOOL),
            'backup_expiry_days' => (int) env('SAFEGUARDING_CASE_BACKUP_EXPIRY_DAYS', 0),
            'backup_policy_approved' => filter_var(env('SAFEGUARDING_CASE_BACKUP_POLICY_APPROVED', false), FILTER_VALIDATE_BOOL),
        ],
    ],
    'model_agency' => [
        'privacy_notice_version' => env('MODEL_AGENCY_PRIVACY_NOTICE_VERSION'),
        'privacy_notice_approved' => filter_var(env('MODEL_AGENCY_PRIVACY_NOTICE_APPROVED', false), FILTER_VALIDATE_BOOL),
        'adult_eligibility_policy_version' => env('MODEL_AGENCY_ADULT_ELIGIBILITY_POLICY_VERSION'),
        'adult_eligibility_policy_approved' => filter_var(env('MODEL_AGENCY_ADULT_ELIGIBILITY_POLICY_APPROVED', false), FILTER_VALIDATE_BOOL),
        'representation_terms_version' => env('MODEL_AGENCY_REPRESENTATION_TERMS_VERSION'),
        'representation_terms_approved' => filter_var(env('MODEL_AGENCY_REPRESENTATION_TERMS_APPROVED', false), FILTER_VALIDATE_BOOL),
        'portfolio_consent_version' => env('MODEL_AGENCY_PORTFOLIO_CONSENT_VERSION'),
        'portfolio_consent_approved' => filter_var(env('MODEL_AGENCY_PORTFOLIO_CONSENT_APPROVED', false), FILTER_VALIDATE_BOOL),
        'image_licence_policy_version' => env('MODEL_AGENCY_IMAGE_LICENCE_POLICY_VERSION'),
        'image_licence_policy_approved' => filter_var(env('MODEL_AGENCY_IMAGE_LICENCE_POLICY_APPROVED', false), FILTER_VALIDATE_BOOL),
        'casting_policy_version' => env('MODEL_AGENCY_CASTING_POLICY_VERSION'),
        'casting_policy_approved' => filter_var(env('MODEL_AGENCY_CASTING_POLICY_APPROVED', false), FILTER_VALIDATE_BOOL),
        'assignment_policy_version' => env('MODEL_AGENCY_ASSIGNMENT_POLICY_VERSION'),
        'assignment_policy_approved' => filter_var(env('MODEL_AGENCY_ASSIGNMENT_POLICY_APPROVED', false), FILTER_VALIDATE_BOOL),
        'settlement_policy_version' => env('MODEL_AGENCY_SETTLEMENT_POLICY_VERSION'),
        'settlement_policy_approved' => filter_var(env('MODEL_AGENCY_SETTLEMENT_POLICY_APPROVED', false), FILTER_VALIDATE_BOOL),
        'retention' => [
            'application_days' => env('MODEL_AGENCY_APPLICATION_RETENTION_DAYS'),
            'accepted_days' => env('MODEL_AGENCY_ACCEPTED_RETENTION_DAYS'),
            'schedule_approved' => filter_var(env('MODEL_AGENCY_RETENTION_SCHEDULE_APPROVED', false), FILTER_VALIDATE_BOOL),
        ],
    ],
];
