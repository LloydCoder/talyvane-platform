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

        try {
            app(SafeguardingCaseKeyVault::class)->reconcile(['case-active']);
            $this->fail('Restore reconciliation must fail when an active key is absent.');
        } catch (RuntimeException $exception) {
            $this->assertSame('An active safeguarding case key is missing from the external vault.', $exception->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }
}
