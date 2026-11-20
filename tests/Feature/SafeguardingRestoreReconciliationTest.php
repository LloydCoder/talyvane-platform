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
            'talyvane.safeguarding.key_namespace' => 'testtenant',
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

    private function createActiveCase(): SafeguardingCase
    {
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'restore-active-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'restore-active-specialist@example.test',
            'role' => 'safeguarding',
        ]);

        $this->actingAs($owner)
            ->post('/admin/safeguarding/reports', [
                'case_type' => 'consent_concern',
                'severity' => 'high',
                'subject_type' => 'model_application',
                'subject_reference' => 'MTA-2026-RESTORE-ACTIVE',
                'summary' => 'An active safeguarding summary for restore tests.',
                'details' => 'An active confidential narrative used to verify restored key usability.',
                'assigned_to' => $specialist->id,
            ])
            ->assertRedirect('/admin');

        return SafeguardingCase::query()->latest('id')->firstOrFail();
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
        $createdKeys = [];
        $deletedKeys = [];
        $plaintextByKey = [];

        Http::fake(function (Request $request) use (&$createdKeys, &$deletedKeys, &$plaintextByKey) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?: '';

            if ($request->method() === 'LIST') {
                $remoteKeys = array_merge(
                    array_values(array_diff($createdKeys, $deletedKeys)),
                    array_values(array_diff(['case-testtenant-orphan', 'preflight-testtenant-leftover'], $deletedKeys)),
                );

                return Http::response(['data' => ['keys' => $remoteKeys]]);
            }

            if ($request->method() === 'POST' && preg_match('#/keys/(case-[^/]+)$#', $path, $matches)) {
                $createdKeys[] = $matches[1];

                return Http::response([], 204);
            }

            if (str_ends_with($path, '/config')) {
                return Http::response([], 204);
            }

            if (str_contains($path, '/encrypt/')) {
                preg_match('#/encrypt/(case-[^/]+)$#', $path, $matches);
                $keyName = $matches[1] ?? '';
                $plaintextByKey[$keyName] = base64_decode((string) $request['plaintext'], true);

                return Http::response(['data' => ['ciphertext' => 'vault:v1:restore-'.$keyName]]);
            }

            if (str_contains($path, '/decrypt/')) {
                preg_match('#/decrypt/(case-[^/]+)$#', $path, $matches);
                $keyName = $matches[1] ?? '';

                return Http::response(['data' => ['plaintext' => base64_encode($plaintextByKey[$keyName] ?? '')]]);
            }

            if ($request->method() === 'DELETE' && preg_match('#/keys/([^/]+)$#', $path, $matches)) {
                $deletedKeys[] = $matches[1];

                return Http::response([], 204);
            }

            return Http::response([], 404);
        });

        $dueCase = $this->createDueCase();
        $activeCase = $this->createActiveCase();

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
            'case_id' => $dueCase->id,
            'key_ciphertext' => null,
            'key_reference' => null,
        ]);
        $this->assertDatabaseHas('safeguarding_case_contents', [
            'case_id' => $activeCase->id,
            'key_provider' => 'vault',
        ]);
        $this->assertDatabaseHas('safeguarding_restore_verifications', [
            'backup_reference' => 'backup-2026-11-20',
            'retention_cases_erased' => 1,
            'active_keys_verified' => 1,
            'orphan_keys_destroyed' => 2,
        ]);
        $this->assertTrue(DB::table('safeguarding_restore_verifications')->exists());
    }
}
