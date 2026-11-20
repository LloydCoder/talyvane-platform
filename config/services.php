<?php

return [
    'yoti' => [
        'age_verification_api_key' => env('YOTI_AGE_VERIFICATION_API_KEY'),
        'sdk_id' => env('YOTI_SDK_ID'),
    ],
    'safeguarding_vault' => [
        'address' => env('SAFEGUARDING_VAULT_ADDRESS'),
        'mount' => env('SAFEGUARDING_VAULT_MOUNT', 'transit'),
        'token' => env('SAFEGUARDING_VAULT_TOKEN'),
        'retention_token' => env('SAFEGUARDING_VAULT_RETENTION_TOKEN'),
        'timeout' => (int) env('SAFEGUARDING_VAULT_TIMEOUT', 5),
    ],
];
