<?php

namespace Tests\Feature;

use App\Models\PlatformSecret;
use App\Models\PlatformSecretAuditEvent;
use App\Models\User;
use App\Services\PlatformSecretResolver;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class SecretManagementControllerTest extends TestCase
{
    use RefreshDatabase;

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

    private function superAdmin(): User
    {
        return $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'secret-admin@example.test',
            'role' => 'super_admin',
        ]);
    }

    private function stepUpCode(User $user): string
    {
        return app(TotpService::class)->codeForStep($user->mfa_secret, intdiv(time(), 30));
    }

    public function test_super_admin_can_set_a_secret_without_persisting_its_value_in_the_application_database(): void
    {
        $admin = $this->superAdmin();
        Http::fake([
            'vault.example.test/v1/secret/metadata/talyvane/mail/smtp_password' => Http::response(['errors' => ['not found']], 404),
            'vault.example.test/v1/secret/data/talyvane/mail/smtp_password' => Http::response(['data' => ['version' => 1]], 200),
        ]);

        $this->actingAs($admin)
            ->post('/admin/secrets/mail.smtp.password', [
                'secret_value' => 'super-secret-value-123',
                'reason' => 'Configure verified transactional SMTP delivery for this deployment.',
                'password' => 'Strong-Testing-Password-934!',
                'code' => $this->stepUpCode($admin),
            ])
            ->assertRedirect('/admin/secrets');

        $this->assertDatabaseHas('platform_secrets', [
            'secret_key' => 'mail.smtp.password',
            'status' => 'configured',
            'vault_version' => 1,
        ]);
        $this->assertDatabaseHas('platform_secret_audit_events', [
            'secret_key' => 'mail.smtp.password',
            'action' => 'secret.set',
            'vault_version' => 1,
        ]);
        $this->assertFalse(Schema::hasColumn('platform_secrets', 'secret_value'));
        $this->assertDatabaseMissing('platform_secret_audit_events', [
            'secret_key' => 'mail.smtp.password',
            'reason' => 'super-secret-value-123',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/v1/secret/data/talyvane/mail/smtp_password')
            && $request['data']['value'] === 'super-secret-value-123');

        $this->actingAs($admin)
            ->get('/admin/secrets')
            ->assertOk()
            ->assertSee('mail.smtp.password')
            ->assertSee('Configured')
            ->assertDontSee('super-secret-value-123');
    }

    public function test_super_admin_can_clear_a_secret_and_the_vault_metadata_is_deleted(): void
    {
        $admin = $this->superAdmin();
        PlatformSecret::query()->create([
            'secret_key' => 'mail.smtp.password',
            'vault_path' => 'talyvane/mail/smtp_password',
            'status' => 'configured',
            'vault_version' => 3,
            'configured_by' => $admin->id,
            'last_rotated_at' => now()->subDay(),
        ]);
        Http::fake([
            'vault.example.test/v1/secret/metadata/talyvane/mail/smtp_password' => Http::response([], 204),
        ]);

        $this->actingAs($admin)
            ->delete('/admin/secrets/mail.smtp.password', [
                'reason' => 'Remove the retired SMTP credential after provider rotation.',
                'password' => 'Strong-Testing-Password-934!',
                'code' => $this->stepUpCode($admin),
            ])
            ->assertRedirect('/admin/secrets');

        $this->assertDatabaseHas('platform_secrets', [
            'secret_key' => 'mail.smtp.password',
            'status' => 'cleared',
            'vault_version' => 0,
        ]);
        $this->assertDatabaseHas('platform_secret_audit_events', [
            'secret_key' => 'mail.smtp.password',
            'action' => 'secret.cleared',
        ]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/v1/secret/metadata/talyvane/mail/smtp_password'));
    }

    public function test_failed_rotation_keeps_the_previous_version_active_and_resolvable(): void
    {
        $admin = $this->superAdmin();
        PlatformSecret::query()->create([
            'secret_key' => 'mail.smtp.password',
            'vault_path' => 'talyvane/mail/smtp_password',
            'status' => 'configured',
            'vault_version' => 2,
            'configured_by' => $admin->id,
            'last_rotated_at' => now()->subDay(),
        ]);
        Http::fake([
            'vault.example.test/v1/secret/metadata/talyvane/mail/smtp_password' => Http::response([
                'data' => ['current_version' => 2],
            ], 200),
            'vault.example.test/v1/secret/data/talyvane/mail/smtp_password' => Http::response([
                'errors' => ['simulated Vault write failure'],
            ], 500),
        ]);

        $this->actingAs($admin)
            ->from('/admin/secrets')
            ->post('/admin/secrets/mail.smtp.password', [
                'secret_value' => 'replacement-secret-value-123',
                'reason' => 'Rotate the SMTP credential after the scheduled provider key renewal.',
                'password' => 'Strong-Testing-Password-934!',
                'code' => $this->stepUpCode($admin),
            ])
            ->assertRedirect('/admin/secrets')
            ->assertSessionHasErrors('secret_value');

        $this->assertDatabaseHas('platform_secrets', [
            'secret_key' => 'mail.smtp.password',
            'status' => 'configured',
            'vault_version' => 2,
        ]);
        $this->assertDatabaseHas('platform_secret_audit_events', [
            'secret_key' => 'mail.smtp.password',
            'action' => 'secret.write_failed',
        ]);

        Http::fake([
            'vault.example.test/v1/secret/data/talyvane/mail/smtp_password*' => Http::response([
                'data' => [
                    'data' => ['value' => 'previous-version-secret'],
                    'metadata' => ['version' => 2],
                ],
            ], 200),
        ]);

        $this->assertSame('previous-version-secret', app(PlatformSecretResolver::class)->resolve('mail.smtp.password'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'version=2'));
    }

    public function test_secret_audit_events_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $admin = $this->superAdmin();
        PlatformSecret::query()->create([
            'secret_key' => 'mail.smtp.password',
            'vault_path' => 'talyvane/mail/smtp_password',
            'status' => 'configured',
            'vault_version' => 1,
            'configured_by' => $admin->id,
        ]);
        $event = PlatformSecretAuditEvent::query()->create([
            'secret_key' => 'mail.smtp.password',
            'actor_id' => $admin->id,
            'actor_email' => $admin->email,
            'action' => 'secret.set',
            'vault_version' => 1,
            'reason' => 'Initial approved deployment credential configuration.',
            'correlation_id' => 'test-correlation-id',
            'created_at' => now(),
        ]);

        try {
            $event->update(['reason' => 'tampered']);
            $this->fail('Secret audit events must be append-only.');
        } catch (LogicException) {
            $this->assertDatabaseHas('platform_secret_audit_events', [
                'id' => $event->id,
                'reason' => 'Initial approved deployment credential configuration.',
            ]);
        }

        try {
            $event->delete();
            $this->fail('Secret audit events must not be deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('platform_secret_audit_events', ['id' => $event->id]);
        }
    }

    public function test_unknown_secret_identifier_is_not_routable(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post('/admin/secrets/arbitrary.path.injected', [
                'secret_value' => 'never-accepted-secret-value',
                'reason' => 'Attempt to set a secret outside the approved catalog.',
                'password' => 'Strong-Testing-Password-934!',
                'code' => $this->stepUpCode($admin),
            ])
            ->assertNotFound();
    }

    public function test_secret_management_is_unavailable_when_vault_is_not_configured(): void
    {
        config(['services.secrets_vault.token' => null]);

        $this->actingAs($this->superAdmin())
            ->get('/admin/secrets')
            ->assertOk()
            ->assertSee('Secret storage is not configured');
    }

    public function test_runtime_secret_resolver_requires_configured_metadata_before_vault_access(): void
    {
        PlatformSecret::query()->create([
            'secret_key' => 'mail.smtp.password',
            'vault_path' => 'talyvane/mail/smtp_password',
            'status' => 'pending',
            'vault_version' => 0,
        ]);

        try {
            app(PlatformSecretResolver::class)->resolve('mail.smtp.password');
            $this->fail('Pending secret metadata must not be resolved by runtime adapters.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }

        Http::assertNothingSent();

        PlatformSecret::query()->where('secret_key', 'mail.smtp.password')->update([
            'status' => 'configured',
            'vault_version' => 2,
        ]);
        Http::fake([
            'vault.example.test/v1/secret/data/talyvane/mail/smtp_password' => Http::response([
                'data' => [
                    'data' => ['value' => 'runtime-only-secret'],
                    'metadata' => ['version' => 2],
                ],
            ], 200),
        ]);

        $this->assertSame('runtime-only-secret', app(PlatformSecretResolver::class)->resolve('mail.smtp.password'));
    }

    public function test_non_designated_super_admin_role_escalation_cannot_open_secret_management(): void
    {
        $this->superAdmin();
        $ordinary = $this->createAdminUser([
            'name' => 'Ordinary Administrator',
            'email' => 'ordinary-secret-admin@example.test',
            'role' => 'admin',
        ]);
        DB::table('users')->where('id', $ordinary->id)->update(['role' => 'super_admin']);

        $this->actingAs($ordinary->fresh())
            ->get('/admin/secrets')
            ->assertForbidden();
    }
}
