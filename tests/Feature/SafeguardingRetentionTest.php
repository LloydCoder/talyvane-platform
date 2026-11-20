<?php

namespace Tests\Feature;

use App\Models\SafeguardingCase;
use App\Models\User;
use App\Services\SafeguardingCaseRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Tests\TestCase;

class SafeguardingRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $owner, User $specialist, string $suffix): SafeguardingCase
    {
        $this->actingAs($owner)
            ->post('/admin/safeguarding/reports', [
                'case_type' => 'consent_concern',
                'severity' => 'high',
                'subject_type' => 'model_application',
                'subject_reference' => 'MTA-2026-RET-'.$suffix,
                'summary' => 'Safeguarding case content retained for policy testing.',
                'details' => 'Sensitive report narrative that must be removed when the approved retention period expires.',
                'assigned_to' => $specialist->id,
            ])
            ->assertRedirect('/admin');

        return SafeguardingCase::query()->latest('id')->firstOrFail();
    }

    private function approveRetentionPolicy(): void
    {
        config([
            'talyvane.safeguarding.retention.schedule_approved' => true,
            'talyvane.safeguarding.retention.days' => 30,
            'talyvane.safeguarding.retention.policy_version' => 'safeguarding-retention-v1',
            'talyvane.safeguarding.retention.backup_expiry_days' => 7,
            'talyvane.safeguarding.retention.backup_policy_approved' => true,
        ]);
    }

    public function test_migration_reencrypts_legacy_app_key_content_with_a_per_case_key(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'retention-migration-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'retention-migration-specialist@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->caseFor($owner, $specialist, 'MIGRATION');

        DB::table('safeguarding_case_contents')->where('case_id', $case->id)->delete();
        DB::table('safeguarding_case_events')->where('case_id', $case->id)->update(['note' => null]);
        DB::table('safeguarding_cases')->where('id', $case->id)->update([
            'summary' => Crypt::encryptString('Legacy encrypted summary'),
            'details' => Crypt::encryptString('Legacy encrypted details'),
            'subject_reference' => Crypt::encryptString('MTA-LEGACY-REF'),
            'legal_hold_reason' => Crypt::encryptString('Legacy legal hold rationale'),
            'legal_hold_release_reason' => Crypt::encryptString('Legacy release rationale'),
        ]);

        $migration = require base_path('database/migrations/2026_11_20_000045_create_safeguarding_case_contents.php');
        $migration->up();

        $fresh = $case->fresh();
        $this->assertSame('Legacy encrypted summary', $fresh->summary);
        $this->assertSame('Legacy encrypted details', $fresh->details);
        $this->assertSame('MTA-LEGACY-REF', $fresh->subject_reference);
        $this->assertSame('Legacy legal hold rationale', $fresh->legal_hold_reason);
        $this->assertSame('Legacy release rationale', $fresh->legal_hold_release_reason);
        $this->assertSame('', DB::table('safeguarding_cases')->where('id', $case->id)->value('summary'));
        $this->assertNotNull(DB::table('safeguarding_case_contents')->where('case_id', $case->id)->value('key_ciphertext'));
    }

    public function test_retention_is_disabled_until_a_policy_is_explicitly_approved(): void
    {
        config([
            'talyvane.safeguarding.retention.schedule_approved' => false,
            'talyvane.safeguarding.retention.days' => 30,
            'talyvane.safeguarding.retention.policy_version' => 'safeguarding-retention-v1',
        ]);

        $this->expectException(RuntimeException::class);
        app(SafeguardingCaseRetentionService::class)->apply();
    }

    public function test_retention_refuses_to_run_without_an_approved_backup_expiry_policy(): void
    {
        config([
            'talyvane.safeguarding.retention.schedule_approved' => true,
            'talyvane.safeguarding.retention.days' => 30,
            'talyvane.safeguarding.retention.policy_version' => 'safeguarding-retention-v1',
            'talyvane.safeguarding.retention.backup_expiry_days' => 7,
            'talyvane.safeguarding.retention.backup_policy_approved' => false,
        ]);

        $this->expectException(RuntimeException::class);
        app(SafeguardingCaseRetentionService::class)->apply();
    }

    public function test_due_closed_case_content_is_erased_but_case_and_audit_metadata_are_retained(): void
    {
        $this->approveRetentionPolicy();
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'retention-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'retention-specialist@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->caseFor($owner, $specialist, '01');
        $case->forceFill([
            'status' => 'closed',
            'resolved_at' => now()->subDays(45),
            'closed_at' => now()->subDays(40),
        ])->save();

        $contentsBefore = DB::table('safeguarding_case_contents')->where('case_id', $case->id)->first();
        $this->assertNotNull($contentsBefore->key_ciphertext);
        $this->assertNotSame(
            'Sensitive report narrative that must be removed when the approved retention period expires.',
            $contentsBefore->details_ciphertext,
        );

        $count = app(SafeguardingCaseRetentionService::class)->apply();

        $this->assertSame(1, $count);
        $contentsAfter = DB::table('safeguarding_case_contents')->where('case_id', $case->id)->first();
        $this->assertNull($contentsAfter->key_ciphertext);
        $this->assertNotNull($contentsAfter->retention_erased_at);
        $this->assertSame('safeguarding-retention-v1', $contentsAfter->retention_policy_version);
        $this->assertSame($case->reference, $case->fresh()->reference);
        $this->assertTrue($case->fresh()->content_erased);
        $this->assertNull($case->fresh()->summary);
        $this->assertNull($case->fresh()->details);
        $this->assertDatabaseHas('safeguarding_case_events', [
            'case_id' => $case->id,
            'event_type' => 'case.retention_erased',
        ]);
        $this->assertNull($case->fresh()->events()->where('event_type', 'case.retention_erased')->firstOrFail()->note);
    }

    public function test_approved_retention_runs_even_when_all_optional_features_are_disabled(): void
    {
        $this->approveRetentionPolicy();
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'retention-paused-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'retention-paused-specialist@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->caseFor($owner, $specialist, 'PAUSED');
        $case->forceFill([
            'status' => 'closed',
            'resolved_at' => now()->subDays(45),
            'closed_at' => now()->subDays(40),
        ])->save();

        DB::table('platform_features')->update(['is_enabled' => false]);

        $this->assertSame(1, app(SafeguardingCaseRetentionService::class)->apply());
        $this->assertTrue($case->fresh()->content_erased);
    }

    public function test_legal_hold_and_open_cases_are_excluded_from_retention(): void
    {
        $this->approveRetentionPolicy();
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'retention-owner-hold@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'retention-specialist-hold@example.test',
            'role' => 'safeguarding',
        ]);
        $held = $this->caseFor($owner, $specialist, '02');
        $held->forceFill([
            'status' => 'closed',
            'resolved_at' => now()->subDays(45),
            'closed_at' => now()->subDays(40),
            'legal_hold_at' => now()->subDays(10),
            'legal_hold_by' => $owner->id,
        ])->save();

        $open = $this->caseFor($owner, $specialist, '03');

        $count = app(SafeguardingCaseRetentionService::class)->apply();

        $this->assertSame(0, $count);
        $this->assertNotNull(DB::table('safeguarding_case_contents')->where('case_id', $held->id)->value('key_ciphertext'));
        $this->assertNotNull(DB::table('safeguarding_case_contents')->where('case_id', $open->id)->value('key_ciphertext'));
    }
}
