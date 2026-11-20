<?php

namespace Tests\Feature;

use App\Models\SafeguardingCase;
use App\Models\User;
use App\Services\SafeguardingCaseRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertNull($case->fresh()->summary);
        $this->assertNull($case->fresh()->details);
        $this->assertDatabaseHas('safeguarding_case_events', [
            'case_id' => $case->id,
            'event_type' => 'case.retention_erased',
        ]);
        $this->assertNull($case->fresh()->events()->where('event_type', 'case.retention_erased')->firstOrFail()->note);
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
