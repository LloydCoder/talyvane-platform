<?php

return [
    'secrets_vault' => [
        'address' => env('SECRETS_VAULT_ADDRESS'),
        'mount' => env('SECRETS_VAULT_MOUNT', 'secret'),
        'prefix' => env('SECRETS_VAULT_PREFIX', 'talyvane'),
        'token' => env('SECRETS_VAULT_TOKEN'),
        'timeout' => (int) env('SECRETS_VAULT_TIMEOUT', 5),
    ],
    'safeguarding_vault' => [
        'address' => env('SAFEGUARDING_VAULT_ADDRESS'),
        'mount' => env('SAFEGUARDING_VAULT_MOUNT', 'transit'),
        'token' => env('SAFEGUARDING_VAULT_TOKEN'),
        'retention_token' => env('SAFEGUARDING_VAULT_RETENTION_TOKEN'),
        'timeout' => (int) env('SAFEGUARDING_VAULT_TIMEOUT', 5),
    ],
];
