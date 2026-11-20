<?php

namespace Tests\Feature;

use App\Models\SafeguardingCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SafeguardingLegalHoldTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $owner, User $specialist): SafeguardingCase
    {
        $this->actingAs($owner)
            ->post('/admin/safeguarding/reports', [
                'case_type' => 'consent_concern',
                'severity' => 'critical',
                'subject_type' => 'model_application',
                'subject_reference' => 'MTA-2026-HOLD01',
                'summary' => 'Urgent safeguarding concern requiring legal preservation.',
                'details' => 'Confidential facts that must remain available under an approved legal hold.',
                'assigned_to' => $specialist->id,
            ])
            ->assertRedirect('/admin');

        return SafeguardingCase::query()->latest('id')->firstOrFail();
    }

    public function test_governance_register_exposes_only_case_metadata_not_confidential_content(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'legal-hold-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'legal-hold-specialist@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->caseFor($owner, $specialist);

        $this->actingAs($owner)
            ->get('/admin/safeguarding/governance')
            ->assertOk()
            ->assertSee($case->reference)
            ->assertSee('CRITICAL')
            ->assertDontSee('Urgent safeguarding concern requiring legal preservation.')
            ->assertDontSee('Confidential facts that must remain available under an approved legal hold.');

        $this->assertDatabaseHas('safeguarding_case_access_events', [
            'case_id' => $case->id,
            'actor_id' => $owner->id,
            'action' => 'case.governance_viewed',
        ]);
    }

    public function test_legal_hold_requires_a_distinct_privileged_releaser_and_preserves_an_audit_trail(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'legal-hold-maker@example.test',
            'role' => 'owner',
        ]);
        $superAdmin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'legal-hold-checker@example.test',
            'role' => 'super_admin',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'legal-hold-assigned@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->caseFor($owner, $specialist);

        $this->actingAs($owner)
            ->post('/admin/safeguarding/governance/'.$case->id.'/legal-hold', [
                'reason' => 'Preserve records for an active legal and safeguarding review.',
            ])
            ->assertRedirect('/admin/safeguarding/governance');

        $this->assertNotNull($case->fresh()->legal_hold_at);
        $this->assertSame($owner->id, $case->fresh()->legal_hold_by);

        $this->actingAs($owner)
            ->post('/admin/safeguarding/governance/'.$case->id.'/release-legal-hold', [
                'reason' => 'Attempted self-release must not be accepted by the governance workflow.',
            ])
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->post('/admin/safeguarding/governance/'.$case->id.'/release-legal-hold', [
                'reason' => 'Independent review confirms the preservation hold can now be released.',
            ])
            ->assertRedirect('/admin/safeguarding/governance');

        $fresh = $case->fresh();
        $this->assertNull($fresh->legal_hold_at);
        $this->assertSame($superAdmin->id, $fresh->legal_hold_released_by);
        $this->assertNotNull($fresh->legal_hold_released_at);

        $this->assertDatabaseHas('safeguarding_case_events', [
            'case_id' => $case->id,
            'actor_id' => $owner->id,
            'event_type' => 'case.legal_hold_placed',
        ]);
        $this->assertDatabaseHas('safeguarding_case_events', [
            'case_id' => $case->id,
            'actor_id' => $superAdmin->id,
            'event_type' => 'case.legal_hold_released',
        ]);
    }

    public function test_regular_admin_and_safeguarding_specialist_cannot_access_governance_controls(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Agency Administrator',
            'email' => 'legal-hold-admin@example.test',
            'role' => 'admin',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'legal-hold-specialist-boundary@example.test',
            'role' => 'safeguarding',
        ]);

        $this->actingAs($admin)->get('/admin/safeguarding/governance')->assertForbidden();
        $this->actingAs($specialist)->get('/admin/safeguarding/governance')->assertForbidden();
    }
}
