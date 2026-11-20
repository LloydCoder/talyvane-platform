<?php

namespace Tests\Feature;

use App\Models\SafeguardingCase;
use App\Models\User;
use App\Services\SafeguardingCaseContentService;
use App\Services\SafeguardingCaseRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SafeguardingExternalKeyVaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'talyvane.safeguarding.key_provider' => 'vault',
            'talyvane.safeguarding.key_namespace' => 'testtenant',
            'services.safeguarding_vault.address' => 'https://vault.example.test',
            'services.safeguarding_vault.mount' => 'transit',
            'services.safeguarding_vault.token' => 'application-token',
            'services.safeguarding_vault.retention_token' => 'retention-token',
            'services.safeguarding_vault.timeout' => 5,
        ]);
    }

    private function createCase(User $owner, User $specialist): SafeguardingCase
    {
        $this->actingAs($owner)
            ->post('/admin/safeguarding/reports', [
                'case_type' => 'consent_concern',
                'severity' => 'high',
                'subject_type' => 'model_application',
                'subject_reference' => 'MTA-2026-VAULT',
                'summary' => 'Vault-backed safeguarding summary.',
                'details' => 'Vault-backed confidential case details.',
                'assigned_to' => $specialist->id,
            ])
            ->assertRedirect('/admin');

        return SafeguardingCase::query()->latest('id')->firstOrFail();
    }

    private function actors(string $suffix): array
    {
        return [
            $this->createAdminUser([
                'name' => 'Agency Owner',
                'email' => 'vault-owner-'.$suffix.'@example.test',
                'role' => 'owner',
            ]),
            $this->createAdminUser([
                'name' => 'Safeguarding Specialist',
                'email' => 'vault-specialist-'.$suffix.'@example.test',
                'role' => 'safeguarding',
            ]),
        ];
    }

    public function test_each_safeguarding_case_must_have_a_unique_external_key_reference(): void
    {
        $plaintextKey = null;
        Http::fake(function (Request $request) use (&$plaintextKey) {
            if (str_contains($request->url(), '/encrypt/')) {
                $plaintextKey = $request['plaintext'];

                return Http::response(['data' => ['ciphertext' => 'vault:v1:case-key-ciphertext']]);
            }

            if (str_contains($request->url(), '/decrypt/')) {
                return Http::response(['data' => ['plaintext' => $plaintextKey]]);
            }

            return Http::response([], 204);
        });

        [$owner, $specialist] = $this->actors('unique-key');
        $first = $this->createCase($owner, $specialist);
        $second = $this->createCase($owner, $specialist);
        $firstReference = DB::table('safeguarding_case_contents')->where('case_id', $first->id)->value('key_reference');

        $this->expectException(QueryException::class);
        DB::table('safeguarding_case_contents')->where('case_id', $second->id)->update([
            'key_reference' => $firstReference,
        ]);
    }

    public function test_case_content_uses_a_vault_managed_key_and_remains_readable(): void
    {
        $plaintextKey = null;

        Http::fake(function (Request $request) use (&$plaintextKey) {
            if (str_ends_with($request->url(), '/keys') || str_contains($request->url(), '/keys/case-')) {
                return Http::response([], 204);
            }

            if (str_contains($request->url(), '/encrypt/')) {
                $plaintextKey = base64_decode((string) $request['plaintext'], true);

                return Http::response(['data' => ['ciphertext' => 'vault:v1:case-key-ciphertext']]);
            }

            if (str_contains($request->url(), '/decrypt/')) {
                return Http::response(['data' => ['plaintext' => base64_encode((string) $plaintextKey)]]);
            }

            return Http::response([], 404);
        });

        [$owner, $specialist] = $this->actors('read');
        $case = $this->createCase($owner, $specialist);
        $stored = DB::table('safeguarding_case_contents')->where('case_id', $case->id)->first();

        $this->assertSame('vault', $stored->key_provider);
        $this->assertStringStartsWith('case-', $stored->key_reference);
        $this->assertSame('vault:v1:case-key-ciphertext', $stored->key_ciphertext);
        $this->assertSame('Vault-backed safeguarding summary.', app(SafeguardingCaseContentService::class)->read($case)['summary']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/decrypt/'));
    }

    public function test_vault_destruction_failure_prevents_local_retention_tombstone(): void
    {
        $plaintextKey = null;

        Http::fake(function (Request $request) use (&$plaintextKey) {
            if (str_contains($request->url(), '/keys/') && str_ends_with($request->url(), '/config')) {
                return Http::response([], 403);
            }

            if (str_contains($request->url(), '/keys/')) {
                return Http::response([], 204);
            }

            if (str_contains($request->url(), '/encrypt/')) {
                $plaintextKey = base64_decode((string) $request['plaintext'], true);

                return Http::response(['data' => ['ciphertext' => 'vault:v1:case-key-ciphertext']]);
            }

            if (str_contains($request->url(), '/decrypt/')) {
                return Http::response(['data' => ['plaintext' => base64_encode((string) $plaintextKey)]]);
            }

            return Http::response([], 404);
        });

        config([
            'talyvane.safeguarding.retention.schedule_approved' => true,
            'talyvane.safeguarding.retention.days' => 30,
            'talyvane.safeguarding.retention.policy_version' => 'vault-retention-v1',
            'talyvane.safeguarding.retention.backup_expiry_days' => 7,
            'talyvane.safeguarding.retention.backup_policy_approved' => true,
        ]);

        [$owner, $specialist] = $this->actors('erase-failure');
        $case = $this->createCase($owner, $specialist);
        $case->forceFill([
            'status' => 'closed',
            'resolved_at' => now()->subDays(45),
            'closed_at' => now()->subDays(40),
        ])->save();

        try {
            app(SafeguardingCaseRetentionService::class)->apply();
            $this->fail('Retention must not mark content erased when Vault key destruction fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('External safeguarding key deletion could not be authorized.', $exception->getMessage());
        }

        $stored = DB::table('safeguarding_case_contents')->where('case_id', $case->id)->first();
        $this->assertSame('vault:v1:case-key-ciphertext', $stored->key_ciphertext);
        $this->assertNull($stored->retention_erased_at);
        $this->assertDatabaseMissing('safeguarding_case_events', [
            'case_id' => $case->id,
            'event_type' => 'case.retention_erased',
        ]);
    }

    public function test_unknown_stored_key_provider_fails_closed(): void
    {
        config(['talyvane.safeguarding.key_provider' => 'local']);
        [$owner, $specialist] = $this->actors('unknown-provider');
        $case = $this->createCase($owner, $specialist);

        DB::table('safeguarding_case_contents')->where('case_id', $case->id)->update([
            'key_provider' => 'unknown',
        ]);

        $this->expectException(RuntimeException::class);
        app(SafeguardingCaseContentService::class)->read($case);
    }

    public function test_retention_destroys_the_remote_case_key_before_marking_content_erased(): void
    {
        $plaintextKey = null;

        Http::fake(function (Request $request) use (&$plaintextKey) {
            if (str_contains($request->url(), '/keys/') && str_ends_with($request->url(), '/config')) {
                return Http::response([], 204);
            }

            if (str_contains($request->url(), '/keys/')) {
                return Http::response([], 204);
            }

            if (str_contains($request->url(), '/encrypt/')) {
                $plaintextKey = base64_decode((string) $request['plaintext'], true);

                return Http::response(['data' => ['ciphertext' => 'vault:v1:case-key-ciphertext']]);
            }

            if (str_contains($request->url(), '/decrypt/')) {
                return Http::response(['data' => ['plaintext' => base64_encode((string) $plaintextKey)]]);
            }

            return Http::response([], 404);
        });

        config([
            'talyvane.safeguarding.retention.schedule_approved' => true,
            'talyvane.safeguarding.retention.days' => 30,
            'talyvane.safeguarding.retention.policy_version' => 'vault-retention-v1',
            'talyvane.safeguarding.retention.backup_expiry_days' => 7,
            'talyvane.safeguarding.retention.backup_policy_approved' => true,
        ]);

        [$owner, $specialist] = $this->actors('erase');
        $case = $this->createCase($owner, $specialist);
        $case->forceFill([
            'status' => 'closed',
            'resolved_at' => now()->subDays(45),
            'closed_at' => now()->subDays(40),
        ])->save();

        $this->assertSame(1, app(SafeguardingCaseRetentionService::class)->apply());

        $stored = DB::table('safeguarding_case_contents')->where('case_id', $case->id)->first();
        $this->assertNull($stored->key_ciphertext);
        $this->assertNull($stored->key_reference);
        $this->assertNotNull($stored->retention_erased_at);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/keys/') && $request->method() === 'DELETE'
                && $request->hasHeader('X-Vault-Token', 'retention-token');
        });
    }
}
