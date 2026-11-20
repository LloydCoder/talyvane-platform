<?php

namespace Tests\Feature;

use App\Models\SafeguardingCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafeguardingRestoreReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'talyvane.safeguarding.key_provider' => 'vault',
            'talyvane.safeguarding.retention.schedule_approved' => true,
            'talyvane.safeguarding.retention.days' => 30,
            'talyvane.safeguarding.retention.policy_version' => 'restore-retention-v1',
            'talyvane.safeguarding.retention.backup_expiry_days' => 7,
            'talyvane.safeguarding.retention.backup_policy_approved' => true,
            'services.safeguarding_vault.address' => 'https://vault.example.test',
            'services.safeguarding_vault.mount' => 'transit',
            'services.safeguarding_vault.token' => 'application-token',
            'services.safeguarding_vault.retention_token' => 'retention-token',
            'services.safeguarding_vault.timeout' => 5,
        ]);
    }

    private function createDueCase(): SafeguardingCase
    {
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'restore-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'restore-specialist@example.test',
            'role' => 'safeguarding',
        ]);

        $this->actingAs($owner)
            ->post('/admin/safeguarding/reports', [
                'case_type' => 'consent_concern',
                'severity' => 'high',
                'subject_type' => 'model_application',
                'subject_reference' => 'MTA-2026-RESTORE',
                'summary' => 'A sufficiently detailed safeguarding summary for restore tests.',
                'details' => 'A confidential safeguarding narrative used to verify safe restoration behavior.',
                'assigned_to' => $specialist->id,
            ])
            ->assertRedirect('/admin');

        $case = SafeguardingCase::query()->latest('id')->firstOrFail();
        $case->forceFill([
            'status' => 'closed',
            'resolved_at' => now()->subDays(45),
            'closed_at' => now()->subDays(40),
        ])->save();

        return $case;
    }

    public function test_restore_reconciliation_requires_maintenance_mode(): void
    {
        $this->artisan('talyvane:safeguarding:restore-reconcile', [
            'backup_reference' => 'backup-2026-11-20',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('safeguarding_restore_verifications', 0);
    }

    public function test_restore_reconciliation_erases_due_cases_verifies_active_keys_and_records_result(): void
    {
        $plaintextKey = null;

        Http::fake(function (Request $request) use (&$plaintextKey) {
            if ($request->method() === 'LIST') {
                return Http::response(['data' => [
                    'keys' => ['case-orphan', 'preflight-leftover'],
                ]]);
            }

            if (str_contains($request->url(), '/keys/') && str_ends_with($request->url(), '/config')) {
                return Http::response([], 204);
            }

            if (str_contains($request->url(), '/keys/')) {
                return Http::response([], 204);
            }

            if (str_contains($request->url(), '/encrypt/')) {
                $plaintextKey = base64_decode((string) $request['plaintext'], true);

                return Http::response(['data' => ['ciphertext' => 'vault:v1:restore-case-key']]);
            }

            if (str_contains($request->url(), '/decrypt/')) {
                return Http::response(['data' => ['plaintext' => base64_encode((string) $plaintextKey)]]);
            }

            return Http::response([], 404);
        });

        $case = $this->createDueCase();

        Artisan::call('down');
        try {
            $exitCode = Artisan::call('talyvane:safeguarding:restore-reconcile', [
                'backup_reference' => 'backup-2026-11-20',
            ]);
            $output = Artisan::output();
        } finally {
            Artisan::call('up');
        }

        $this->assertSame(0, $exitCode, $output);
        $this->assertDatabaseHas('safeguarding_case_contents', [
            'case_id' => $case->id,
            'key_ciphertext' => null,
            'key_reference' => null,
        ]);
        $this->assertDatabaseHas('safeguarding_restore_verifications', [
            'backup_reference' => 'backup-2026-11-20',
            'retention_cases_erased' => 1,
            'active_keys_verified' => 0,
            'orphan_keys_destroyed' => 2,
        ]);
        $this->assertTrue(DB::table('safeguarding_restore_verifications')->exists());
    }
}
