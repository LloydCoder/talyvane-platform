<?php

namespace App\Services;

use App\Models\PlatformSecret;
use RuntimeException;

class PlatformSecretResolver
{
    public function __construct(private readonly VaultKv2SecretStore $vault)
    {
    }

    /**
     * Resolve an approved secret for a trusted server-side integration adapter only.
     * Never call this from controllers, views, logs or client-facing serialization.
     */
    public function resolve(string $secretKey): string
    {
        if (! array_key_exists($secretKey, SecretCatalog::all())) {
            throw new RuntimeException('The requested runtime secret is not approved.');
        }

        $record = PlatformSecret::query()
            ->where('secret_key', $secretKey)
            ->where('status', 'configured')
            ->where('vault_version', '>', 0)
            ->first();

        if (! $record) {
            throw new RuntimeException('The requested runtime secret is not configured.');
        }

        return $this->vault->read($secretKey, (int) $record->vault_version);
    }
}
