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

    public function test_it_creates_non_exportable_case_keys_and_uses_transit_encryption(): void
    {
        Http::fake([
            'https://vault.example.test/v1/transit/keys/case-42' => Http::response([], 204),
            'https://vault.example.test/v1/transit/encrypt/case-42' => Http::response([
                'data' => ['ciphertext' => 'vault:v1:wrapped-key'],
            ]),
            'https://vault.example.test/v1/transit/decrypt/case-42' => Http::response([
                'data' => ['plaintext' => base64_encode('case-data-key')],
            ]),
        ]);

        $vault = app(SafeguardingCaseKeyVault::class);
        $vault->createKey('case-42');
        $wrapped = $vault->encrypt('case-42', 'case-data-key');

        $this->assertSame('vault:v1:wrapped-key', $wrapped);
        $this->assertSame('case-data-key', $vault->decrypt('case-42', $wrapped));

        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/keys/case-42')
                && $request->method() === 'POST'
                && $request['type'] === 'aes256-gcm96'
                && $request['exportable'] === false
                && $request['allow_plaintext_backup'] === false;
        });
    }

    public function test_restore_reconciliation_destroys_only_orphaned_talyvane_keys(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'LIST') {
                return Http::response(['data' => [
                    'keys' => ['case-active', 'case-orphan', 'preflight-leftover', 'unrelated-key'],
                ]]);
            }

            return Http::response([], 204);
        });

        $result = app(SafeguardingCaseKeyVault::class)->reconcile(['case-active']);

        $this->assertSame(['active_keys_verified' => 1, 'orphan_keys_destroyed' => 2], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'LIST'
            && $request->hasHeader('X-Vault-Token', 'retention-token'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/keys/case-orphan'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/keys/preflight-leftover'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/keys/unrelated-key'));
    }

    public function test_restore_reconciliation_fails_before_deleting_anything_if_an_active_key_is_missing(): void
    {
        Http::fake([
            'https://vault.example.test/v1/transit/keys' => Http::response(['data' => [
                'keys' => ['case-orphan'],
            ]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An active safeguarding case key is missing from the external vault.');

        app(SafeguardingCaseKeyVault::class)->reconcile(['case-active']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_preflight_verifies_round_trip_and_destroys_its_temporary_key(): void
    {
        $plaintextKey = null;

        Http::fake(function (Request $request) use (&$plaintextKey) {
            if (str_contains($request->url(), '/encrypt/')) {
                $plaintextKey = base64_decode((string) $request['plaintext'], true);

                return Http::response(['data' => ['ciphertext' => 'vault:v1:preflight-ciphertext']]);
            }

            if (str_contains($request->url(), '/decrypt/')) {
                return Http::response(['data' => ['plaintext' => base64_encode((string) $plaintextKey)]]);
            }

            return Http::response([], 204);
        });

        app(SafeguardingCaseKeyVault::class)->verify();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->hasHeader('X-Vault-Token', 'retention-token'));
    }

    public function test_key_creation_requires_a_distinct_retention_credential(): void
    {
        config(['services.safeguarding_vault.retention_token' => 'application-token']);

        $this->expectException(RuntimeException::class);
        app(SafeguardingCaseKeyVault::class)->createKey('case-42');
    }

    public function test_key_destruction_requires_the_separate_retention_credential(): void
    {
        Http::fake([
            'https://vault.example.test/v1/transit/keys/case-42/config' => Http::response([], 204),
            'https://vault.example.test/v1/transit/keys/case-42' => Http::response([], 204),
        ]);

        app(SafeguardingCaseKeyVault::class)->destroyKey('case-42');

        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/keys/case-42/config')
                && $request->method() === 'POST'
                && $request->hasHeader('X-Vault-Token', 'retention-token')
                && $request['deletion_allowed'] === true;
        });
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/keys/case-42')
                && $request->method() === 'DELETE'
                && $request->hasHeader('X-Vault-Token', 'retention-token');
        });
    }

    public function test_key_destruction_fails_closed_without_a_distinct_retention_credential(): void
    {
        config(['services.safeguarding_vault.retention_token' => '']);

        $this->expectException(RuntimeException::class);
        app(SafeguardingCaseKeyVault::class)->destroyKey('case-42');
    }

    public function test_it_rejects_vault_endpoints_with_embedded_credentials(): void
    {
        config(['services.safeguarding_vault.address' => 'https://embedded-token@vault.example.test']);

        $this->expectException(RuntimeException::class);
        app(SafeguardingCaseKeyVault::class)->createKey('case-42');
    }

    public function test_it_rejects_non_https_vault_endpoints(): void
    {
        config(['services.safeguarding_vault.address' => 'http://vault.example.test']);

        $this->expectException(RuntimeException::class);
        app(SafeguardingCaseKeyVault::class)->createKey('case-42');
    }
}
