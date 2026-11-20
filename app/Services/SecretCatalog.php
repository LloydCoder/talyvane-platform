<?php

namespace App\Services;

use RuntimeException;

final class SecretCatalog
{
    private const SECRETS = [
        'mail.smtp.password' => [
            'label' => 'SMTP password',
            'group' => 'Transactional email',
            'description' => 'Password for the deployment SMTP account. Never displayed after storage.',
            'vault_path' => 'mail/smtp_password',
        ],
        'integrations.email.api_key' => [
            'label' => 'Email delivery API key',
            'group' => 'Integrations',
            'description' => 'API credential for an approved transactional email provider.',
            'vault_path' => 'integrations/email_api_key',
        ],
        'integrations.calendar.client_secret' => [
            'label' => 'Calendar OAuth client secret',
            'group' => 'Integrations',
            'description' => 'Client secret for an approved calendar provider adapter.',
            'vault_path' => 'integrations/calendar_client_secret',
        ],
        'integrations.identity.api_key' => [
            'label' => 'Identity verification API key',
            'group' => 'Integrations',
            'description' => 'API credential for an approved identity or age-assurance provider.',
            'vault_path' => 'integrations/identity_api_key',
        ],
        'integrations.blockchain.rpc_api_key' => [
            'label' => 'Blockchain RPC API key',
            'group' => 'Payments',
            'description' => 'Credential for an approved blockchain RPC or indexing provider.',
            'vault_path' => 'payments/blockchain_rpc_api_key',
        ],
        'integrations.exchange_rates.api_key' => [
            'label' => 'Exchange-rate API key',
            'group' => 'Integrations',
            'description' => 'API credential for an approved exchange-rate data provider.',
            'vault_path' => 'integrations/exchange_rates_api_key',
        ],
        'payments.crypto.webhook_secret' => [
            'label' => 'Crypto payment webhook secret',
            'group' => 'Payments',
            'description' => 'Webhook signing secret for an approved payment integration.',
            'vault_path' => 'payments/crypto_webhook_secret',
        ],
    ];

    public static function all(): array
    {
        return self::SECRETS;
    }

    public static function find(string $key): array
    {
        $secret = self::SECRETS[$key] ?? null;

        if (! is_array($secret)) {
            throw new RuntimeException('The requested secret identifier is not approved for this deployment.');
        }

        return $secret;
    }
}
