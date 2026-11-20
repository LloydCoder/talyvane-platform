<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class SafeguardingVaultCredentialLifecycle
{
    public function __construct(
        private readonly SafeguardingCaseKeyVault $vault,
        private readonly EnvFileSecretWriter $environment,
    ) {
    }

    public function status(): array
    {
        $applicationToken = trim((string) config('services.safeguarding_vault.token'));
        $retentionToken = trim((string) config('services.safeguarding_vault.retention_token'));

        try {
            $this->environment->assertWritable();
            $environmentWritable = true;
        } catch (Throwable) {
            $environmentWritable = false;
        }

        return [
            'key_provider' => (string) config('talyvane.safeguarding.key_provider', 'local'),
            'vault_address_configured' => filled(config('services.safeguarding_vault.address')),
            'application_credential_configured' => $applicationToken !== '',
            'retention_credential_configured' => $retentionToken !== '',
            'credentials_distinct' => $applicationToken !== ''
                && $retentionToken !== ''
                && ! hash_equals($applicationToken, $retentionToken),
            'active_case_keys' => $this->vault->activeCaseKeyCount(),
            'environment_file_writable' => $environmentWritable,
        ];
    }

    public function set(string $applicationToken, string $retentionToken): array
    {
        if (trim((string) config('services.safeguarding_vault.token')) !== ''
            || trim((string) config('services.safeguarding_vault.retention_token')) !== '') {
            throw new RuntimeException('Vault credentials already exist. Use the rotate action instead.');
        }

        return $this->persist($applicationToken, $retentionToken);
    }

    public function rotate(string $applicationToken, string $retentionToken): array
    {
        if (trim((string) config('services.safeguarding_vault.token')) === ''
            || trim((string) config('services.safeguarding_vault.retention_token')) === '') {
            throw new RuntimeException('Both existing Vault credentials must be configured before rotation. Use the set action for initial credentials.');
        }

        return $this->persist($applicationToken, $retentionToken);
    }

    public function clear(): void
    {
        if (strtolower(trim((string) config('talyvane.safeguarding.key_provider', 'local'))) === 'vault') {
            throw new RuntimeException('Switch safeguarding case key storage to the local provider before clearing Vault credentials.');
        }

        if ($this->vault->activeCaseKeyCount() > 0) {
            throw new RuntimeException('Vault credentials cannot be cleared while active safeguarding case keys still depend on the external vault.');
        }

        $this->environment->clearMany([
            'SAFEGUARDING_VAULT_TOKEN',
            'SAFEGUARDING_VAULT_RETENTION_TOKEN',
        ]);

        config([
            'services.safeguarding_vault.token' => '',
            'services.safeguarding_vault.retention_token' => '',
        ]);
    }

    private function persist(string $applicationToken, string $retentionToken): array
    {
        $applicationToken = trim($applicationToken);
        $retentionToken = trim($retentionToken);

        if ($applicationToken === '' || $retentionToken === ''
            || preg_match('/[\r\n\x00]/', $applicationToken)
            || preg_match('/[\r\n\x00]/', $retentionToken)
            || hash_equals($applicationToken, $retentionToken)) {
            throw new RuntimeException('Two non-empty, distinct, single-line Vault credentials are required.');
        }

        $this->environment->assertWritable();

        $original = [
            'services.safeguarding_vault.token' => config('services.safeguarding_vault.token'),
            'services.safeguarding_vault.retention_token' => config('services.safeguarding_vault.retention_token'),
        ];

        config([
            'services.safeguarding_vault.token' => $applicationToken,
            'services.safeguarding_vault.retention_token' => $retentionToken,
        ]);

        try {
            $verification = $this->vault->verifyCredentialsForRotation();
            $this->environment->putMany([
                'SAFEGUARDING_VAULT_TOKEN' => $applicationToken,
                'SAFEGUARDING_VAULT_RETENTION_TOKEN' => $retentionToken,
            ]);
        } catch (Throwable $exception) {
            config($original);

            throw $exception;
        }

        return $verification;
    }
}
