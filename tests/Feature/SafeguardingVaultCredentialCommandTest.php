<?php

namespace Tests\Feature;

use App\Services\EnvFileSecretWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafeguardingVaultCredentialCommandTest extends TestCase
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

        $this->environmentPath = tempnam(sys_get_temp_dir(), 'talyvane-vault-command-');
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

    public function test_status_reports_credential_presence_without_disclosing_secret_values(): void
    {
        config([
            'services.safeguarding_vault.token' => 'never-print-this-application-token',
            'services.safeguarding_vault.retention_token' => 'never-print-this-retention-token',
        ]);

        $this->artisan('talyvane:safeguarding:vault-credentials status')
            ->expectsOutputToContain('Application credential')
            ->expectsOutputToContain('Retention credential')
            ->doesntExpectOutput('never-print-this-application-token')
            ->doesntExpectOutput('never-print-this-retention-token')
            ->assertExitCode(0);
    }

    public function test_set_command_uses_hidden_prompts_and_does_not_echo_candidate_credentials(): void
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

        $this->artisan('talyvane:safeguarding:vault-credentials set')
            ->expectsQuestion('Safeguarding Vault application credential', 'hidden-app-token')
            ->expectsQuestion('Safeguarding Vault retention credential', 'hidden-retention-token')
            ->expectsOutputToContain('credentials set successfully')
            ->doesntExpectOutput('hidden-app-token')
            ->doesntExpectOutput('hidden-retention-token')
            ->assertExitCode(0);

        $parsed = \Dotenv\Dotenv::parse(file_get_contents($this->environmentPath));
        $this->assertSame('hidden-app-token', $parsed['SAFEGUARDING_VAULT_TOKEN']);
        $this->assertSame('hidden-retention-token', $parsed['SAFEGUARDING_VAULT_RETENTION_TOKEN']);
    }

    public function test_clear_command_requires_confirmation_and_clears_only_after_safe_provider_switch(): void
    {
        config([
            'services.safeguarding_vault.token' => 'old-app-token',
            'services.safeguarding_vault.retention_token' => 'old-retention-token',
        ]);
        file_put_contents($this->environmentPath, "APP_NAME=Talyvane\nSAFEGUARDING_VAULT_TOKEN=old-app-token\nSAFEGUARDING_VAULT_RETENTION_TOKEN=old-retention-token\n");

        $this->artisan('talyvane:safeguarding:vault-credentials clear')
            ->expectsConfirmation('Clear both Vault credentials? This is allowed only after switching to local key storage and removing all active Vault-backed case keys.', 'yes')
            ->expectsOutputToContain('credentials cleared')
            ->assertExitCode(0);

        $parsed = \Dotenv\Dotenv::parse(file_get_contents($this->environmentPath));
        $this->assertSame('', $parsed['SAFEGUARDING_VAULT_TOKEN']);
        $this->assertSame('', $parsed['SAFEGUARDING_VAULT_RETENTION_TOKEN']);
    }
}
