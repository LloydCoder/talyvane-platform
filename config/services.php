<?php

return [
    'safeguarding_vault' => [
        'address' => env('SAFEGUARDING_VAULT_ADDRESS'),
        'mount' => env('SAFEGUARDING_VAULT_MOUNT', 'transit'),
        'token' => env('SAFEGUARDING_VAULT_TOKEN'),
        'retention_token' => env('SAFEGUARDING_VAULT_RETENTION_TOKEN'),
        'timeout' => (int) env('SAFEGUARDING_VAULT_TIMEOUT', 5),
    ],
];
