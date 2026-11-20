<?php

namespace Tests\Feature;

use App\Models\SafeguardingCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SafeguardingCaseAccessTest extends TestCase
{
    use RefreshDatabase;

    private function safeguardingUser(string $email = 'safeguarding@example.test'): User
    {
        return $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => $email,
            'role' => 'safeguarding',
        ]);
    }

    private function createReport(User $reporter, User $specialist, string $reference = 'MTA-2026-SAFE01'): SafeguardingCase
    {
        $this->actingAs($reporter)
            ->post('/admin/safeguarding/reports', [
                'case_type' => 'consent_concern',
                'severity' => 'high',
                'subject_type' => 'model_application',
                'subject_reference' => $reference,
                'summary' => 'Consent concern requires immediate safeguarding review.',
                'details' => 'A detailed confidential report for the designated safeguarding specialist.',
                'assigned_to' => $specialist->id,
            ])
            ->assertRedirect('/admin');

        return SafeguardingCase::query()->latest('id')->firstOrFail();
    }

    public function test_agency_admin_can_report_a_case_but_cannot_read_its_confidential_contents(): void
    {
        $reporter = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'safety-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->safeguardingUser();

        $case = $this->createReport($reporter, $specialist);

        $this->assertSame($specialist->id, $case->assigned_to);
        $this->assertSame($reporter->id, $case->created_by);
        $this->assertNotSame(
            'Consent concern requires immediate safeguarding review.',
            DB::table('safeguarding_cases')->where('id', $case->id)->value('summary'),
        );
        $this->assertNotSame(
            'A detailed confidential report for the designated safeguarding specialist.',
            DB::table('safeguarding_cases')->where('id', $case->id)->value('details'),
        );

        $this->actingAs($reporter)
            ->get('/admin/safeguarding/cases/'.$case->id)
            ->assertForbidden();
    }

    public function test_safeguarding_specialist_can_access_only_assigned_cases_and_every_detail_view_is_audited(): void
    {
        $reporter = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'safety-owner-2@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->safeguardingUser('safeguarding-assigned@example.test');
        $otherSpecialist = $this->safeguardingUser('safeguarding-other@example.test');

        $assignedCase = $this->createReport($reporter, $specialist, 'MTA-2026-SAFE02');
        $otherCase = $this->createReport($reporter, $otherSpecialist, 'MTA-2026-SAFE03');

        $this->actingAs($specialist)
            ->get('/admin/safeguarding/cases')
            ->assertOk()
            ->assertSee($assignedCase->reference)
            ->assertDontSee($otherCase->reference);

        $this->actingAs($specialist)
            ->get('/admin/safeguarding/cases/'.$assignedCase->id)
            ->assertOk()
            ->assertSee('Consent concern requires immediate safeguarding review.')
            ->assertSee('A detailed confidential report for the designated safeguarding specialist.');

        $this->actingAs($specialist)
            ->get('/admin/safeguarding/cases/'.$otherCase->id)
            ->assertNotFound();

        $this->assertDatabaseHas('safeguarding_case_access_events', [
            'case_id' => $assignedCase->id,
            'actor_id' => $specialist->id,
            'action' => 'case.viewed',
        ]);
    }

    public function test_safeguarding_specialist_can_transition_an_assigned_case_with_an_append_only_event(): void
    {
        $reporter = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'safety-owner-3@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->safeguardingUser('safeguarding-transition@example.test');
        $case = $this->createReport($reporter, $specialist, 'MTA-2026-SAFE04');

        $this->actingAs($specialist)
            ->patch('/admin/safeguarding/cases/'.$case->id, [
                'status' => 'triaged',
                'note' => 'Initial safeguarding assessment completed and next steps recorded.',
            ])
            ->assertRedirect('/admin/safeguarding/cases/'.$case->id);

        $this->assertSame('triaged', $case->fresh()->status);
        $this->assertDatabaseHas('safeguarding_case_events', [
            'case_id' => $case->id,
            'actor_id' => $specialist->id,
            'event_type' => 'case.status_changed',
            'to_status' => 'triaged',
        ]);
    }

    public function test_safeguarding_specialist_cannot_access_other_admin_workspaces(): void
    {
        $specialist = $this->safeguardingUser('safeguarding-boundary@example.test');

        $this->actingAs($specialist)->get('/admin')->assertForbidden();
        $this->actingAs($specialist)->get('/admin/model-applications')->assertForbidden();
        $this->actingAs($specialist)->get('/admin/model-settlements')->assertForbidden();
        $this->actingAs($specialist)->get('/admin/adult-eligibility')->assertForbidden();
    }

    public function test_invalid_case_transition_is_rejected_without_mutating_the_case(): void
    {
        $reporter = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'safety-owner-invalid-transition@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->safeguardingUser('safeguarding-invalid-transition@example.test');
        $case = $this->createReport($reporter, $specialist, 'MTA-2026-SAFE06');

        $this->actingAs($specialist)
            ->from('/admin/safeguarding/cases/'.$case->id)
            ->patch('/admin/safeguarding/cases/'.$case->id, [
                'status' => 'closed',
                'note' => 'Attempt to close a case without completing triage.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('open', $case->fresh()->status);
    }

    public function test_safeguarding_case_events_are_append_only(): void
    {
        $reporter = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'safety-owner-append-only@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->safeguardingUser('safeguarding-append-only@example.test');
        $case = $this->createReport($reporter, $specialist, 'MTA-2026-SAFE07');
        $event = $case->events()->firstOrFail();

        $this->expectException(\LogicException::class);
        $event->forceFill(['event_type' => 'case.tampered'])->save();
    }

    public function test_case_cannot_be_assigned_to_an_inactive_or_mfa_unenrolled_specialist(): void
    {
        $reporter = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'safety-owner-4@example.test',
            'role' => 'owner',
        ]);
        $specialist = User::query()->create([
            'name' => 'Unverified Safeguarding Specialist',
            'email' => 'safeguarding-unenrolled@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'safeguarding',
            'is_active' => true,
        ]);

        $this->actingAs($reporter)
            ->from('/admin/safeguarding/reports/create')
            ->post('/admin/safeguarding/reports', [
                'case_type' => 'consent_concern',
                'severity' => 'high',
                'subject_type' => 'model_application',
                'subject_reference' => 'MTA-2026-SAFE05',
                'summary' => 'Consent concern requires immediate safeguarding review.',
                'details' => 'A detailed confidential report for the designated safeguarding specialist.',
                'assigned_to' => $specialist->id,
            ])
            ->assertSessionHasErrors('assigned_to');

        $this->assertDatabaseCount('safeguarding_cases', 0);
    }
}
