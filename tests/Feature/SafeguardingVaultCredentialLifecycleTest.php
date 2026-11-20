<?php

namespace Tests\Feature;

use App\Services\EnvFileSecretWriter;
use App\Services\SafeguardingVaultCredentialLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SafeguardingVaultCredentialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $environmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'talyvane.safeguarding.key_provider' => 'local',
            'services.safeguarding_vault.address' => 'https://vault.example.test',
            'services.safeguarding_vault.mount' => 'transit',
            'services.safeguarding_vault.token' => '',
            'services.safeguarding_vault.retention_token' => '',
            'services.safeguarding_vault.timeout' => 5,
        ]);

        $this->environmentPath = tempnam(sys_get_temp_dir(), 'talyvane-vault-env-');
        file_put_contents($this->environmentPath, "APP_NAME=Talyvane\nSAFEGUARDING_VAULT_TOKEN=\"\"\nSAFEGUARDING_VAULT_RETENTION_TOKEN=\"\"\n");
        chmod($this->environmentPath, 0600);

        app()->instance(EnvFileSecretWriter::class, new EnvFileSecretWriter($this->environmentPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->environmentPath.'.secret-update.lock');
        @unlink($this->environmentPath);

        parent::tearDown();
    }

    private function fakeVaultPreflight(): void
    {
        $plaintext = null;

        Http::fake(function (Request $request) use (&$plaintext) {
            if (str_contains($request->url(), '/encrypt/')) {
                $plaintext = base64_decode((string) $request['plaintext'], true);

                return Http::response(['data' => ['ciphertext' => 'vault:v1:preflight']]);
            }

            if (str_contains($request->url(), '/decrypt/')) {
                return Http::response(['data' => ['plaintext' => base64_encode((string) $plaintext)]]);
            }

            if ($request->method() === 'LIST') {
                return Http::response(['data' => ['keys' => []]]);
            }

            return Http::response([], 204);
        });
    }

    public function test_initial_set_verifies_both_credentials_before_persisting_them(): void
    {
        $this->fakeVaultPreflight();

        $result = app(SafeguardingVaultCredentialLifecycle::class)->set(
            'new-application-token',
            'new-retention-token',
        );

        $parsed = \Dotenv\Dotenv::parse(file_get_contents($this->environmentPath));

        $this->assertSame(0, $result['active_case_keys_verified']);
        $this->assertTrue($result['retention_inventory_verified']);
        $this->assertSame('new-application-token', $parsed['SAFEGUARDING_VAULT_TOKEN']);
        $this->assertSame('new-retention-token', $parsed['SAFEGUARDING_VAULT_RETENTION_TOKEN']);
        $this->assertSame('new-application-token', config('services.safeguarding_vault.token'));
        $this->assertSame('new-retention-token', config('services.safeguarding_vault.retention_token'));
    }

    public function test_set_refuses_to_overwrite_existing_credentials_without_rotation(): void
    {
        config([
            'services.safeguarding_vault.token' => 'current-app-token',
            'services.safeguarding_vault.retention_token' => 'current-retention-token',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Use the rotate action instead.');

        app(SafeguardingVaultCredentialLifecycle::class)->set('new-app-token', 'new-retention-token');
    }

    public function test_rotation_refuses_when_runtime_credentials_do_not_match_the_project_environment_file(): void
    {
        config([
            'services.safeguarding_vault.token' => 'injected-app-token',
            'services.safeguarding_vault.retention_token' => 'injected-retention-token',
        ]);

        file_put_contents($this->environmentPath, "APP_NAME=Talyvane\nSAFEGUARDING_VAULT_TOKEN=file-app-token\nSAFEGUARDING_VAULT_RETENTION_TOKEN=file-retention-token\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Update the deployment secret manager instead.');

        app(SafeguardingVaultCredentialLifecycle::class)->rotate('new-app-token', 'new-retention-token');
    }

    public function test_clear_is_refused_while_vault_provider_is_selected(): void
    {
        config([
            'talyvane.safeguarding.key_provider' => 'vault',
            'services.safeguarding_vault.token' => '',
            'services.safeguarding_vault.retention_token' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Switch safeguarding case key storage to the local provider');

        app(SafeguardingVaultCredentialLifecycle::class)->clear();
    }

    public function test_clear_removes_credentials_only_after_provider_is_local_and_no_vault_case_keys_remain(): void
    {
        config([
            'talyvane.safeguarding.key_provider' => 'local',
            'services.safeguarding_vault.token' => 'old-app-token',
            'services.safeguarding_vault.retention_token' => 'old-retention-token',
        ]);
        file_put_contents($this->environmentPath, "APP_NAME=Talyvane\nSAFEGUARDING_VAULT_TOKEN=old-app-token\nSAFEGUARDING_VAULT_RETENTION_TOKEN=old-retention-token\n");

        app(SafeguardingVaultCredentialLifecycle::class)->clear();

        $parsed = \Dotenv\Dotenv::parse(file_get_contents($this->environmentPath));
        $this->assertSame('', $parsed['SAFEGUARDING_VAULT_TOKEN']);
        $this->assertSame('', $parsed['SAFEGUARDING_VAULT_RETENTION_TOKEN']);
        $this->assertSame('', config('services.safeguarding_vault.token'));
        $this->assertSame('', config('services.safeguarding_vault.retention_token'));
    }
}
