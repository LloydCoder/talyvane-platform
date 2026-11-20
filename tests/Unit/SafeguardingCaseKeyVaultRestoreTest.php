<?php

namespace Tests\Unit;

use App\Services\SafeguardingCaseKeyVault;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SafeguardingCaseKeyVaultRestoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.safeguarding_vault.address' => 'https://vault.example.test',
            'services.safeguarding_vault.mount' => 'transit',
            'services.safeguarding_vault.token' => 'application-token',
            'services.safeguarding_vault.retention_token' => 'retention-token',
            'services.safeguarding_vault.timeout' => 5,
        ]);
    }

    public function test_restore_reconciliation_decrypt_verifies_active_keys_before_destroying_orphans_and_verifies_inventory_afterward(): void
    {
        $deleted = [];
        $operations = [];
        $plaintext = random_bytes(32);
        $allKeys = ['case-active', 'case-orphan', 'preflight-leftover', 'unrelated-key'];

        Http::fake(function (Request $request) use (&$deleted, &$operations, $plaintext, $allKeys) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
            $operations[] = $request->method().' '.$path;

            if ($request->method() === 'LIST') {
                return Http::response(['data' => [
                    'keys' => array_values(array_diff($allKeys, $deleted)),
                ]]);
            }

            if (str_contains($path, '/decrypt/')) {
                return Http::response(['data' => ['plaintext' => base64_encode($plaintext)]]);
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/config')) {
                return Http::response([], 204);
            }

            if ($request->method() === 'DELETE' && preg_match('#/keys/([^/]+)$#', $path, $matches)) {
                $deleted[] = $matches[1];

                return Http::response([], 204);
            }

            return Http::response([], 404);
        });

        $result = app(SafeguardingCaseKeyVault::class)->reconcile([
            'case-active' => 'vault:v1:active-case-ciphertext',
        ]);

        $this->assertSame(['active_keys_verified' => 1, 'orphan_keys_destroyed' => 2], $result);
        $decryptIndex = array_search(true, array_map(fn (string $operation): bool => str_contains($operation, '/decrypt/case-active'), $operations), true);
        $firstDeleteIndex = array_search(true, array_map(fn (string $operation): bool => str_starts_with($operation, 'DELETE '), $operations), true);
        $this->assertIsInt($decryptIndex);
        $this->assertIsInt($firstDeleteIndex);
        $this->assertLessThan($firstDeleteIndex, $decryptIndex);
        $this->assertSame(2, count(array_filter($operations, fn (string $operation): bool => str_starts_with($operation, 'LIST '))));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'LIST'
            && $request->hasHeader('X-Vault-Token', 'retention-token'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/decrypt/case-active'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/keys/case-orphan'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/keys/preflight-leftover'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/keys/unrelated-key'));
    }

    public function test_restore_reconciliation_never_deletes_orphans_when_an_active_ciphertext_cannot_be_decrypted(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'LIST') {
                return Http::response(['data' => ['keys' => ['case-active', 'case-orphan']]]);
            }

            if (str_contains($request->url(), '/decrypt/')) {
                return Http::response([], 500);
            }

            return Http::response([], 204);
        });

        try {
            app(SafeguardingCaseKeyVault::class)->reconcile([
                'case-active' => 'vault:v1:unreadable-ciphertext',
            ]);
            $this->fail('Restore reconciliation must fail if an active case key cannot decrypt its stored ciphertext.');
        } catch (RuntimeException $exception) {
            $this->assertSame('An active safeguarding case key could not decrypt its stored ciphertext.', $exception->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_restore_reconciliation_fails_if_vault_still_lists_an_orphan_after_delete(): void
    {
        $plaintext = random_bytes(32);
        Http::fake(function (Request $request) use ($plaintext) {
            if ($request->method() === 'LIST') {
                return Http::response(['data' => ['keys' => ['case-active', 'case-orphan']]]);
            }

            if (str_contains($request->url(), '/decrypt/')) {
                return Http::response(['data' => ['plaintext' => base64_encode($plaintext)]]);
            }

            return Http::response([], 204);
        });

        try {
            app(SafeguardingCaseKeyVault::class)->reconcile([
                'case-active' => 'vault:v1:active-case-ciphertext',
            ]);
            $this->fail('Restore reconciliation must verify that deleted orphan keys are absent from the final Vault inventory.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Orphan safeguarding case keys remain in the external vault after reconciliation.', $exception->getMessage());
        }
    }

    public function test_restore_reconciliation_fails_before_deleting_anything_if_an_active_key_is_missing(): void
    {
        Http::fake([
            'https://vault.example.test/v1/transit/keys' => Http::response(['data' => [
                'keys' => ['case-orphan'],
            ]]),
        ]);

        try {
            app(SafeguardingCaseKeyVault::class)->reconcile(['case-active' => 'vault:v1:active-case-ciphertext']);
            $this->fail('Restore reconciliation must fail when an active key is absent.');
        } catch (RuntimeException $exception) {
            $this->assertSame('An active safeguarding case key is missing from the external vault.', $exception->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }
}
