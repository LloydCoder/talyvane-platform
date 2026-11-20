<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SafeguardingCaseKeyVault
{
    public function verify(): void
    {
        $keyName = 'preflight-'.str_replace('-', '', (string) \Illuminate\Support\Str::uuid());
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

    public function reconcile(array $activeKeyReferences): array
    {
        $retentionToken = trim((string) config('services.safeguarding_vault.retention_token'));
        $this->assertDistinctRetentionCredential();

        $active = array_values(array_unique($activeKeyReferences));
        foreach ($active as $keyReference) {
            if (! is_string($keyReference) || ! str_starts_with($keyReference, 'case-')) {
                throw new RuntimeException('An active safeguarding key reference is invalid.');
            }

            $this->assertKeyName($keyReference);
        }

        $response = $this->client($retentionToken)->send('LIST', $this->path('keys'));
        $remoteKeys = $response->json('data.keys');

        if (! $response->successful() || ! is_array($remoteKeys)) {
            throw new RuntimeException('External safeguarding key inventory could not be verified.');
        }

        $managedKeys = array_values(array_filter($remoteKeys, fn ($key): bool => is_string($key)
            && (str_starts_with($key, 'case-') || str_starts_with($key, 'preflight-'))));

        $missing = array_values(array_diff($active, $managedKeys));
        if ($missing !== []) {
            throw new RuntimeException('An active safeguarding case key is missing from the external vault.');
        }

        $orphans = array_values(array_diff($managedKeys, $active));
        foreach ($orphans as $keyName) {
            $this->destroyKey($keyName);
        }

        return [
            'active_keys_verified' => count($active),
            'orphan_keys_destroyed' => count($orphans),
        ];
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
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{0,95}$/', $keyName)) {
            throw new RuntimeException('External safeguarding key reference is invalid.');
        }
    }
}
