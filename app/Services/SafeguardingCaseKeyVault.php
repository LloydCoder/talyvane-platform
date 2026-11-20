<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function verifyCredentialsForRotation(): array
    {
        $this->assertDistinctRetentionCredential();

        if (! Schema::hasTable('safeguarding_case_contents')) {
            throw new RuntimeException('Safeguarding case storage is not migrated; credential rotation cannot be verified safely.');
        }

        $this->verify();

        $rows = DB::table('safeguarding_case_contents')
            ->where('key_provider', 'vault')
            ->whereNotNull('key_ciphertext')
            ->whereNull('retention_erased_at')
            ->get(['key_reference', 'key_ciphertext']);

        $activeReferences = [];

        foreach ($rows as $row) {
            if (! is_string($row->key_reference) || $row->key_reference === '') {
                throw new RuntimeException('An active safeguarding case key reference is missing; credential rotation is unsafe.');
            }

            $this->assertKeyName($row->key_reference);
            $dataKey = $this->decrypt($row->key_reference, (string) $row->key_ciphertext);

            if (strlen($dataKey) !== 32) {
                throw new RuntimeException('An active safeguarding case data key could not be verified with the candidate application credential.');
            }

            $activeReferences[] = $row->key_reference;
        }

        $activeReferences = array_values(array_unique($activeReferences));
        $this->verifyRetentionInventory($activeReferences);

        return [
            'active_case_keys_verified' => count($activeReferences),
            'retention_inventory_verified' => true,
        ];
    }

    public function activeCaseKeyCount(): int
    {
        if (! Schema::hasTable('safeguarding_case_contents')) {
            return 0;
        }

        return DB::table('safeguarding_case_contents')
            ->where('key_provider', 'vault')
            ->whereNotNull('key_ciphertext')
            ->whereNull('retention_erased_at')
            ->count();
    }

    private function verifyRetentionInventory(array $activeKeyReferences): void
    {
        $retentionToken = trim((string) config('services.safeguarding_vault.retention_token'));
        $this->assertDistinctRetentionCredential();

        $response = $this->client($retentionToken)->send('LIST', $this->path('keys'));
        $remoteKeys = $response->json('data.keys');

        if (! $response->successful() || ! is_array($remoteKeys)) {
            throw new RuntimeException('The candidate retention credential could not verify the external safeguarding key inventory.');
        }

        $missing = array_values(array_diff($activeKeyReferences, $remoteKeys));

        if ($missing !== []) {
            throw new RuntimeException('An active safeguarding case key is missing from the external vault.');
        }
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
