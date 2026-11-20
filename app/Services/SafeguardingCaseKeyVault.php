<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SafeguardingCaseKeyVault
{
    public function verify(): void
    {
        $keyName = $this->newPreflightKeyReference();
        $plaintext = random_bytes(32);
        $created = false;

        try {
            $this->createKey($keyName);
            $created = true;
            $ciphertext = $this->encrypt($keyName, $plaintext);
            $decrypted = $this->decrypt($keyName, $ciphertext);

            if (! hash_equals($plaintext, $decrypted)) {
                throw new RuntimeException('External safeguarding key round-trip verification failed.');
            }
        } finally {
            if ($created) {
                $this->destroyKey($keyName);
            }
        }
    }

    public function createKey(string $keyName): void
    {
        $this->assertKeyName($keyName);
        $this->assertDistinctRetentionCredential();

        $response = $this->client()->post($this->path('keys/'.$keyName), [
            'type' => 'aes256-gcm96',
            'exportable' => false,
            'allow_plaintext_backup' => false,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('External safeguarding key creation failed.');
        }
    }

    public function encrypt(string $keyName, string $plaintext): string
    {
        $this->assertKeyName($keyName);

        $response = $this->client()->post($this->path('encrypt/'.$keyName), [
            'plaintext' => base64_encode($plaintext),
        ]);

        $ciphertext = $response->json('data.ciphertext');

        if (! $response->successful() || ! is_string($ciphertext) || ! str_starts_with($ciphertext, 'vault:v')) {
            throw new RuntimeException('External safeguarding key encryption failed.');
        }

        return $ciphertext;
    }

    public function decrypt(string $keyName, string $ciphertext): string
    {
        $this->assertKeyName($keyName);

        if (! str_starts_with($ciphertext, 'vault:v')) {
            throw new RuntimeException('External safeguarding key ciphertext is invalid.');
        }

        $response = $this->client()->post($this->path('decrypt/'.$keyName), [
            'ciphertext' => $ciphertext,
        ]);

        $plaintext = $response->json('data.plaintext');
        $decoded = is_string($plaintext) ? base64_decode($plaintext, true) : false;

        if (! $response->successful() || ! is_string($decoded)) {
            throw new RuntimeException('External safeguarding key decryption failed.');
        }

        return $decoded;
    }

    public function newCaseKeyReference(): string
    {
        return $this->caseKeyPrefix().Str::uuid()->toString();
    }

    private function newPreflightKeyReference(): string
    {
        return $this->preflightKeyPrefix().Str::uuid()->toString();
    }

    public function reconcile(array $activeKeyCiphertexts): array
    {
        $retentionToken = trim((string) config('services.safeguarding_vault.retention_token'));
        $this->assertDistinctRetentionCredential();

        foreach ($activeKeyCiphertexts as $keyReference => $ciphertext) {
            if (! is_string($keyReference)
                || ! str_starts_with($keyReference, 'case-')
                || ! is_string($ciphertext)
                || ! str_starts_with($ciphertext, 'vault:v')) {
                throw new RuntimeException('An active safeguarding key reference or ciphertext is invalid.');
            }

            $this->assertKeyName($keyReference);
        }

        $activeKeys = array_keys($activeKeyCiphertexts);
        $remoteKeys = $this->remoteKeyInventory($retentionToken);
        $missing = array_values(array_diff($activeKeys, $remoteKeys));

        if ($missing !== []) {
            throw new RuntimeException('An active safeguarding case key is missing from the external vault.');
        }

        // A key name in the Vault inventory is not proof that restored key material
        // can decrypt the ciphertext actually stored for a live case.
        foreach ($activeKeyCiphertexts as $keyReference => $ciphertext) {
            try {
                $plaintext = $this->decrypt($keyReference, $ciphertext);
            } catch (\Throwable $exception) {
                throw new RuntimeException(
                    'An active safeguarding case key could not decrypt its stored ciphertext.',
                    0,
                    $exception,
                );
            }

            if (strlen($plaintext) !== 32) {
                throw new RuntimeException('An active safeguarding case key could not decrypt its stored ciphertext.');
            }

            if (function_exists('sodium_memzero')) {
                sodium_memzero($plaintext);
            }
        }

        // Only keys carrying this deployment's namespace may be garbage-collected.
        // Legacy un-namespaced active keys are verified but never treated as orphans,
        // because they cannot be safely attributed across shared Vault mounts.
        $managedKeys = $this->managedKeyInventory($remoteKeys);
        $orphans = array_values(array_diff($managedKeys, $activeKeys));
        foreach ($orphans as $keyName) {
            $this->destroyKey($keyName);
        }

        // Verify the remote post-condition. A successful DELETE response alone is
        // insufficient evidence that every managed orphan has actually disappeared.
        $remainingRemoteKeys = $this->remoteKeyInventory($retentionToken);
        $missingAfterCleanup = array_values(array_diff($activeKeys, $remainingRemoteKeys));
        if ($missingAfterCleanup !== []) {
            throw new RuntimeException('An active safeguarding case key disappeared during restore reconciliation.');
        }

        $remainingManagedKeys = $this->managedKeyInventory($remainingRemoteKeys);
        $remainingOrphans = array_values(array_diff($remainingManagedKeys, $activeKeys));
        if ($remainingOrphans !== []) {
            throw new RuntimeException('Orphan safeguarding case keys remain in the external vault after reconciliation.');
        }

        return [
            'active_keys_verified' => count($activeKeys),
            'orphan_keys_destroyed' => count($orphans),
        ];
    }

    private function remoteKeyInventory(string $retentionToken): array
    {
        $response = $this->client($retentionToken)->send('LIST', $this->path('keys'));
        $remoteKeys = $response->json('data.keys');

        if (! $response->successful() || ! is_array($remoteKeys)) {
            throw new RuntimeException('External safeguarding key inventory could not be verified.');
        }

        return array_values(array_unique(array_filter($remoteKeys, fn ($key): bool => is_string($key))));
    }

    private function managedKeyInventory(array $remoteKeys): array
    {
        $casePrefix = $this->caseKeyPrefix();
        $preflightPrefix = $this->preflightKeyPrefix();

        return array_values(array_filter($remoteKeys, fn (string $key): bool => str_starts_with($key, $casePrefix)
            || str_starts_with($key, $preflightPrefix)));
    }

    private function keyNamespace(): string
    {
        $namespace = strtolower(trim((string) config('talyvane.safeguarding.key_namespace')));
        if ($namespace === '') {
            $applicationKey = trim((string) config('app.key'));
            if ($applicationKey === '') {
                throw new RuntimeException('A stable application key or explicit safeguarding key namespace is required.');
            }

            $namespace = substr(hash('sha256', $applicationKey), 0, 16);
        }

        if (! preg_match('/^[a-z0-9]{8,32}$/', $namespace)) {
            throw new RuntimeException('The safeguarding key namespace must contain 8 to 32 lowercase letters or digits.');
        }

        return $namespace;
    }

    private function caseKeyPrefix(): string
    {
        return 'case-'.$this->keyNamespace().'-';
    }

    private function preflightKeyPrefix(): string
    {
        return 'preflight-'.$this->keyNamespace().'-';
    }

    public function destroyKey(string $keyName): void
    {
        $this->assertKeyName($keyName);
        $retentionToken = trim((string) config('services.safeguarding_vault.retention_token'));
        $this->assertDistinctRetentionCredential();

        $client = $this->client($retentionToken);
        $configResponse = $client->post($this->path('keys/'.$keyName.'/config'), [
            'deletion_allowed' => true,
        ]);

        if ($configResponse->status() === 404) {
            return;
        }

        if (! $configResponse->successful()) {
            throw new RuntimeException('External safeguarding key deletion could not be authorized.');
        }

        $deleteResponse = $client->delete($this->path('keys/'.$keyName));

        if (! $deleteResponse->successful() && $deleteResponse->status() !== 404) {
            throw new RuntimeException('External safeguarding key destruction failed.');
        }
    }

    private function client(?string $token = null): PendingRequest
    {
        $address = rtrim(trim((string) config('services.safeguarding_vault.address')), '/');
        $mount = trim((string) config('services.safeguarding_vault.mount', 'transit'), '/');
        $token ??= trim((string) config('services.safeguarding_vault.token'));
        $timeout = max(1, min((int) config('services.safeguarding_vault.timeout', 5), 15));

        $parts = parse_url($address);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('External safeguarding key storage requires a valid HTTPS endpoint without embedded credentials or query parameters.');
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $mount) || $token === '' || preg_match('/[\\r\\n]/', $token)) {
            throw new RuntimeException('External safeguarding key storage is not fully configured.');
        }

        return Http::baseUrl($address)
            ->withHeaders(['X-Vault-Token' => $token])
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withOptions(['verify' => true, 'allow_redirects' => false]);
    }

    private function path(string $operation): string
    {
        $mount = trim((string) config('services.safeguarding_vault.mount', 'transit'), '/');

        return '/v1/'.$mount.'/'.$operation;
    }

    private function assertDistinctRetentionCredential(): void
    {
        $retentionToken = trim((string) config('services.safeguarding_vault.retention_token'));
        $applicationToken = trim((string) config('services.safeguarding_vault.token'));

        if ($retentionToken === '' || $applicationToken === '' || hash_equals($applicationToken, $retentionToken)) {
            throw new RuntimeException('A distinct external safeguarding retention credential is required.');
        }
    }

    private function assertKeyName(string $keyName): void
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        $namespace = preg_quote($this->keyNamespace(), '/');
        $valid = preg_match('/^(?:case-'.$namespace.'-'.$uuid.'|preflight-'.$namespace.'-'.$uuid.'|case-'.$uuid.'|preflight-'.$uuid.')$/', $keyName);

        if (! $valid) {
            throw new RuntimeException('External safeguarding key reference is invalid or belongs to another deployment.');
        }
    }
}
