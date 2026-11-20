<?php

namespace Tests\Unit;

use App\Services\VaultKv2SecretStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class VaultKv2SecretStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.secrets_vault.address' => 'https://vault.example.test',
            'services.secrets_vault.mount' => 'secret',
            'services.secrets_vault.prefix' => 'talyvane',
            'services.secrets_vault.token' => 'test-vault-token',
            'services.secrets_vault.timeout' => 5,
        ]);
    }

    public function test_write_uses_compare_and_set_and_returns_only_the_vault_version(): void
    {
        Http::fake([
            'vault.example.test/v1/secret/data/talyvane/mail/smtp_password' => Http::response([
                'data' => ['version' => 7],
            ], 200),
        ]);

        $version = app(VaultKv2SecretStore::class)->write(
            'mail.smtp.password',
            'super-secret-value-123',
            6,
        );

        $this->assertSame(7, $version);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/v1/secret/data/talyvane/mail/smtp_password')
            && $request['options']['cas'] === 6
            && $request['data']['value'] === 'super-secret-value-123'
            && $request->hasHeader('X-Vault-Token', 'test-vault-token'));
    }

    public function test_read_returns_a_secret_only_to_the_internal_runtime_service(): void
    {
        Http::fake([
            'vault.example.test/v1/secret/data/talyvane/mail/smtp_password' => Http::response([
                'data' => [
                    'data' => ['value' => 'runtime-secret-value'],
                    'metadata' => ['version' => 3],
                ],
            ], 200),
        ]);

        $this->assertSame('runtime-secret-value', app(VaultKv2SecretStore::class)->read('mail.smtp.password'));
    }

    public function test_clear_permanently_deletes_all_secret_versions_from_vault_metadata(): void
    {
        Http::fake([
            'vault.example.test/v1/secret/metadata/talyvane/mail/smtp_password' => Http::response([], 204),
        ]);

        app(VaultKv2SecretStore::class)->clear('mail.smtp.password');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v1/secret/metadata/talyvane/mail/smtp_password'));
    }

    public function test_current_version_returns_zero_for_a_secret_that_does_not_exist(): void
    {
        Http::fake([
            'vault.example.test/v1/secret/metadata/talyvane/mail/smtp_password' => Http::response([
                'errors' => ['not found'],
            ], 404),
        ]);

        $this->assertSame(0, app(VaultKv2SecretStore::class)->currentVersion('mail.smtp.password'));
    }

    public function test_non_https_vault_endpoint_fails_closed_before_network_access(): void
    {
        config(['services.secrets_vault.address' => 'http://vault.example.test']);

        try {
            app(VaultKv2SecretStore::class)->currentVersion('mail.smtp.password');
            $this->fail('Non-HTTPS secret storage must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTPS', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_unknown_secret_identifiers_are_rejected_without_network_access(): void
    {
        try {
            app(VaultKv2SecretStore::class)->read('arbitrary.path.injected');
            $this->fail('Only catalogued secret identifiers may be resolved.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not approved', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
