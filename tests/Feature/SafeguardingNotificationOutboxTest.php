<?php

namespace Tests\Feature;

use App\Models\SafeguardingCase;
use App\Models\User;
use App\Notifications\SafeguardingCaseAlert;
use App\Services\SafeguardingNotificationDispatcher;
use App\Services\SafeguardingNotificationOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use RuntimeException;

class SafeguardingNotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function createCase(User $owner, User $specialist, string $severity = 'high'): SafeguardingCase
    {
        $this->actingAs($owner)
            ->post('/admin/safeguarding/reports', [
                'case_type' => 'consent_concern',
                'severity' => $severity,
                'subject_type' => 'model_application',
                'subject_reference' => 'MTA-2026-NOTIFY',
                'summary' => 'Notification test case summary requiring private handling.',
                'details' => 'Highly confidential case narrative must never appear in outbound notification content.',
                'assigned_to' => $specialist->id,
            ])
            ->assertRedirect('/admin');

        return SafeguardingCase::query()->latest('id')->firstOrFail();
    }

    public function test_case_assignment_notice_is_outboxed_and_contains_no_confidential_narrative(): void
    {
        config([
            'talyvane.safeguarding.emergency_contact_emails' => [],
            'talyvane.safeguarding.notifications_enabled' => true,
            'mail.default' => 'smtp',
        ]);
        Notification::fake();
        Mail::fake();

        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'notify-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'notify-specialist@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->createCase($owner, $specialist);

        $this->assertDatabaseHas('safeguarding_notification_outbox', [
            'case_id' => $case->id,
            'recipient_user_id' => $specialist->id,
            'notification_type' => 'case_assigned',
            'status' => 'pending',
        ]);

        $sent = app(SafeguardingNotificationDispatcher::class)->dispatchPending();

        $this->assertSame(1, $sent);
        Notification::assertSentOnDemand(SafeguardingCaseAlert::class, function ($notification, $channels, $notifiable) use ($case): bool {
            return $notification->caseReference === $case->reference
                && $notification->severity === 'high'
                && ! property_exists($notification, 'details')
                && ! property_exists($notification, 'summary');
        });

        $this->assertDatabaseHas('safeguarding_notification_outbox', [
            'case_id' => $case->id,
            'recipient_user_id' => $specialist->id,
            'status' => 'delivered',
        ]);
    }

    public function test_critical_case_enqueues_a_separate_escalation_notice_for_configured_contacts(): void
    {
        config([
            'talyvane.safeguarding.emergency_contact_emails' => ['urgent-safety@example.test'],
            'talyvane.safeguarding.notifications_enabled' => true,
            'mail.default' => 'smtp',
        ]);
        Notification::fake();

        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'notify-critical-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'notify-critical-specialist@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->createCase($owner, $specialist, 'critical');

        $this->assertDatabaseCount('safeguarding_notification_outbox', 2);

        $sent = app(SafeguardingNotificationDispatcher::class)->dispatchPending();

        $this->assertSame(2, $sent);
        Notification::assertSentOnDemandTimes(SafeguardingCaseAlert::class, 2);
        $this->assertNull(\\App\\Models\\SafeguardingNotificationOutbox::query()
            ->where('case_id', $case->id)
            ->where('notification_type', 'critical_escalation')
            ->firstOrFail()
            ->recipient_email);
    }

    public function test_dispatcher_fails_closed_when_notifications_are_disabled(): void
    {
        config(['talyvane.safeguarding.emergency_contact_emails' => []]);
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'notify-disabled-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'notify-disabled-specialist@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->createCase($owner, $specialist);

        config(['talyvane.safeguarding.notifications_enabled' => false]);
        try {
            app(SafeguardingNotificationDispatcher::class)->dispatchPending();
            $this->fail('Disabled safeguarding notifications must not be dispatched.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('safeguarding_notification_outbox', [
                'case_id' => $case->id,
                'status' => 'pending',
            ]);
        }
    }

    public function test_outbox_enqueue_is_idempotent_for_the_same_case_and_recipient(): void
    {
        config(['talyvane.safeguarding.emergency_contact_emails' => []]);
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'notify-dedupe-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'notify-dedupe-specialist@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->createCase($owner, $specialist);

        app(SafeguardingNotificationOutboxService::class)->enqueueCaseCreated($case, $specialist);

        $this->assertDatabaseCount('safeguarding_notification_outbox', 1);
    }

    public function test_notification_dispatcher_does_not_include_case_content_in_outbox_payload(): void
    {
        config(['talyvane.safeguarding.emergency_contact_emails' => []]);
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'notify-payload-owner@example.test',
            'role' => 'owner',
        ]);
        $specialist = $this->createAdminUser([
            'name' => 'Safeguarding Specialist',
            'email' => 'notify-payload-specialist@example.test',
            'role' => 'safeguarding',
        ]);
        $case = $this->createCase($owner, $specialist);

        $outbox = \DB::table('safeguarding_notification_outbox')->where('case_id', $case->id)->first();
        $this->assertNull($outbox->payload ?? null);
        $this->assertNull($outbox->summary ?? null);
        $this->assertNull($outbox->details ?? null);
    }
}
