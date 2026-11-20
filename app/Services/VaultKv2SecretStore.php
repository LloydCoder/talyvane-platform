<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class VaultKv2SecretStore
{
    public function isConfigured(): bool
    {
        try {
            $this->client();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function currentVersion(string $secretKey): int
    {
        $path = $this->secretPath($secretKey);

        try {
            $response = $this->client()->get($this->path('metadata/'.$path));

            if ($response->status() === 404) {
                return 0;
            }

            $version = $response->json('data.current_version');

            if (! $response->successful() || ! is_int($version) || $version < 0) {
                throw new RuntimeException('Secret metadata could not be verified.');
            }

            return $version;
        } catch (Throwable) {
            throw new RuntimeException('Secret metadata could not be verified in the configured secret store.');
        }
    }

    public function write(string $secretKey, string $value, int $expectedVersion): int
    {
        if ($value === '' || strlen($value) > 4096 || $expectedVersion < 0) {
            throw new RuntimeException('The secret payload or expected version is invalid.');
        }

        $path = $this->secretPath($secretKey);

        try {
            $response = $this->client()->put($this->path('data/'.$path), [
                'options' => ['cas' => $expectedVersion],
                'data' => ['value' => $value],
            ]);

            $version = $response->json('data.version');

            if (! $response->successful() || ! is_int($version) || $version < 1) {
                throw new RuntimeException('Secret write was rejected.');
            }

            return $version;
        } catch (Throwable) {
            throw new RuntimeException('The secret could not be written to the configured secret store.');
        }
    }

    /**
     * Runtime-only retrieval for approved integration adapters. Never call from a controller.
     */
    public function read(string $secretKey): string
    {
        $path = $this->secretPath($secretKey);

        try {
            $response = $this->client()->get($this->path('data/'.$path));
            $value = $response->json('data.data.value');

            if (! $response->successful() || ! is_string($value) || $value === '') {
                throw new RuntimeException('Secret retrieval failed.');
            }

            return $value;
        } catch (Throwable) {
            throw new RuntimeException('The requested runtime secret is unavailable.');
        }
    }

    /**
     * Permanently removes all KV versions for this path via the KV v2 metadata endpoint.
     */
    public function clear(string $secretKey): void
    {
        $path = $this->secretPath($secretKey);

        try {
            $response = $this->client()->delete($this->path('metadata/'.$path));

            if (! $response->successful() && $response->status() !== 404) {
                throw new RuntimeException('Secret deletion was rejected.');
            }
        } catch (Throwable) {
            throw new RuntimeException('The secret could not be permanently removed from the configured secret store.');
        }
    }

    private function secretPath(string $secretKey): string
    {
        $definition = SecretCatalog::find($secretKey);
        $prefix = trim((string) config('services.secrets_vault.prefix', 'talyvane'), '/');

        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_\/-]{0,79}$/', $prefix)
            || str_contains($prefix, '..')
            || str_contains($prefix, '//')) {
            throw new RuntimeException('The configured secret-store namespace is invalid.');
        }

        return $prefix.'/'.$definition['vault_path'];
    }

    private function client(): PendingRequest
    {
        $address = rtrim(trim((string) config('services.secrets_vault.address')), '/');
        $mount = trim((string) config('services.secrets_vault.mount', 'secret'), '/');
        $token = trim((string) config('services.secrets_vault.token'));
        $timeout = max(1, min((int) config('services.secrets_vault.timeout', 5), 15));
        $parts = parse_url($address);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('Secret storage requires a valid HTTPS endpoint without embedded credentials or query parameters.');
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $mount)
            || $token === ''
            || preg_match('/[\r\n]/', $token)) {
            throw new RuntimeException('Secret storage is not fully configured.');
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
        $mount = trim((string) config('services.secrets_vault.mount', 'secret'));

        return '/v1/'.$mount.'/'.$operation;
    }
}
