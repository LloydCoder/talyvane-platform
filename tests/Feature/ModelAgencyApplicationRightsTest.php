<?php

namespace Tests\Feature;

use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use App\Models\ModelTalentApplicationRightsRequest;
use App\Models\SitePage;
use App\Notifications\ModelAgencyRightsVerificationCode;
use App\Services\ModelAgencyRightsCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ModelAgencyApplicationRightsTest extends TestCase
{
    use RefreshDatabase;

    private function enableLifecycle(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'talyvane.model_agency.privacy_notice_version' => 'model-application-privacy-v1',
            'talyvane.model_agency.privacy_notice_approved' => true,
            'talyvane.model_agency.adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'talyvane.model_agency.adult_eligibility_policy_approved' => true,
            'talyvane.model_agency.retention.application_days' => 90,
            'talyvane.model_agency.retention.accepted_days' => 365,
            'talyvane.model_agency.retention.schedule_approved' => true,
        ]);

        SitePage::query()->create([
            'title' => 'Model Applicant Privacy Notice',
            'slug' => 'model-applicant-privacy',
            'body' => 'Approved model applicant privacy notice. Version: model-application-privacy-v1.',
            'is_published' => true,
            'published_at' => now(),
        ]);
        SitePage::query()->create([
            'title' => 'Adult Eligibility Policy',
            'slug' => 'model-adult-eligibility',
            'body' => 'Approved adult eligibility policy. Version: adult-eligibility-v1.',
            'is_published' => true,
            'published_at' => now(),
        ]);

        DB::table('platform_features')->where('key', 'model-agency.applications')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
            'activation_requirements' => json_encode([
                'model_agency_workflow',
                'applicant_data_lifecycle',
                'applicant_privacy_notice',
                'adult_eligibility_policy',
            ]),
        ]);
    }

    private function application(string $status = 'submitted', array $overrides = []): ModelTalentApplication
    {
        return ModelTalentApplication::query()->create(array_merge([
            'reference' => 'MTA-2026-'.strtoupper(bin2hex(random_bytes(4))),
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'phone' => '+447700900123',
            'country_code' => 'GB',
            'city' => 'London',
            'portfolio_url' => 'https://portfolio.example.test/jordan',
            'experience_summary' => 'Commercial and editorial modelling experience.',
            'status' => $status,
            'adult_eligibility_confirmed_at' => now(),
            'adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'adult_eligibility_policy_accepted_at' => now(),
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted_at' => now(),
            'submitted_at' => now(),
        ], $overrides));
    }

    private function requestCode(ModelTalentApplication $application, string $type = 'access'): ModelTalentApplicationRightsRequest
    {
        Notification::fake();
        $this->mock(ModelAgencyRightsCodeGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->once()->andReturn('12345678'));

        $this->post('/models/application-support', [
            'reference' => $application->reference,
            'email' => 'jordan@example.test',
            'request_type' => $type,
        ])->assertRedirect('/models/application-support/verify');

        $request = ModelTalentApplicationRightsRequest::query()->latest('id')->firstOrFail();
        $this->assertNotSame('12345678', $request->code_hash);
        $this->assertTrue(Hash::check('12345678', $request->code_hash));
        Notification::assertSentOnDemand(ModelAgencyRightsVerificationCode::class);

        return $request;
    }

    private function verifyCode(ModelTalentApplication $application, ModelTalentApplicationRightsRequest $request): void
    {
        $this->post('/models/application-support/verify', [
            'reference' => $application->reference,
            'email' => 'jordan@example.test',
            'code' => '12345678',
        ])->assertRedirect('/models/application-support/'.$request->id);
    }


    public function test_applicant_rights_services_remain_available_after_new_intake_is_disabled(): void
    {
        $this->enableLifecycle();
        $application = $this->application();

        DB::table('platform_features')->where('key', 'model-agency.applications')->update(['is_enabled' => false]);

        $this->get('/models/application-support')->assertOk();
        $request = $this->requestCode($application, 'access');
        $this->verifyCode($application, $request);

        $this->get('/models/application-support/'.$request->id)
            ->assertOk()
            ->assertSee('Jordan Example');
    }

    public function test_applicant_can_verify_email_and_access_their_application_data(): void
    {
        $this->enableLifecycle();
        $application = $this->application();
        $request = $this->requestCode($application, 'access');

        $this->get('/models/application-support/'.$request->id)->assertForbidden();
        $this->verifyCode($application, $request);

        $this->get('/models/application-support/'.$request->id)
            ->assertOk()
            ->assertSee('Jordan Example')
            ->assertSee('jordan@example.test')
            ->assertSee('Commercial and editorial modelling experience.')
            ->assertHeader('Cache-Control', 'no-store, private, max-age=0');
    }

    public function test_access_request_does_not_disclose_whether_reference_or_email_exists(): void
    {
        $this->enableLifecycle();
        Notification::fake();

        $this->post('/models/application-support', [
            'reference' => 'MTA-DOES-NOT-EXIST',
            'email' => 'unknown@example.test',
            'request_type' => 'access',
        ])->assertRedirect('/models/application-support/verify');

        $this->assertDatabaseCount('model_talent_application_rights_requests', 0);
        Notification::assertNothingSent();
    }

    public function test_applicant_can_correct_their_contact_and_portfolio_information_after_email_verification(): void
    {
        $this->enableLifecycle();
        $application = $this->application();
        $request = $this->requestCode($application, 'correction');
        $this->verifyCode($application, $request);

        $this->patch('/models/application-support/'.$request->id, [
            'full_name' => 'Jordan Updated',
            'phone' => '+447700900456',
            'city' => 'Manchester',
            'portfolio_url' => 'https://portfolio.example.test/updated',
            'experience_summary' => 'Updated commercial and editorial modelling experience.',
        ])->assertRedirect('/models/application-support/verify');

        $application->refresh();
        $this->assertSame('Jordan Updated', $application->full_name);
        $this->assertSame('+447700900456', $application->phone);
        $this->assertSame('Manchester', $application->city);
        $this->assertDatabaseHas('model_talent_application_events', [
            'application_id' => $application->id,
            'event_type' => 'application.corrected',
            'actor_type' => 'applicant',
        ]);
        $this->assertSame('completed', $request->fresh()->status);
    }

    public function test_applicant_can_withdraw_their_application_after_email_verification(): void
    {
        $this->enableLifecycle();
        $application = $this->application();
        $request = $this->requestCode($application, 'withdrawal');
        $this->verifyCode($application, $request);

        $this->post('/models/application-support/'.$request->id.'/withdraw')
            ->assertRedirect('/models/application-support/verify');

        $this->assertSame('withdrawn', $application->fresh()->status);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertDatabaseHas('model_talent_application_events', [
            'application_id' => $application->id,
            'from_status' => 'submitted',
            'to_status' => 'withdrawn',
            'event_type' => 'application.withdrawn',
        ]);
    }

    public function test_applicant_erasure_anonymizes_personal_data_and_scrubs_free_text_audit_reasons(): void
    {
        $this->enableLifecycle();
        $application = $this->application('accepted', ['review_note' => 'Discussed with Jordan Example about their personal circumstances.']);
        ModelTalentApplicationEvent::query()->create([
            'application_id' => $application->id,
            'actor_type' => 'administrator',
            'event_type' => 'application.accepted',
            'from_status' => 'reviewing',
            'to_status' => 'accepted',
            'reason' => 'Jordan Example requested a private contact arrangement.',
            'occurred_at' => now(),
        ]);
        $request = $this->requestCode($application, 'erasure');
        $this->verifyCode($application, $request);

        $this->post('/models/application-support/'.$request->id.'/erase')
            ->assertRedirect('/models/application-support/verify');

        $application->refresh();
        $this->assertSame('erased', $application->status);
        $this->assertSame('Erased applicant', $application->full_name);
        $this->assertStringContainsString('@invalid.example', $application->email);
        $this->assertNull($application->phone);
        $this->assertNull($application->portfolio_url);
        $this->assertNull($application->review_note);
        $this->assertNull($application->adult_eligibility_confirmed_at);
        $this->assertNull($application->adult_eligibility_policy_accepted_at);
        $this->assertNull($application->privacy_notice_accepted_at);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame(0, DB::table('model_talent_application_events')->where('application_id', $application->id)->whereNotNull('reason')->count());
        $this->assertDatabaseHas('model_talent_application_events', [
            'application_id' => $application->id,
            'event_type' => 'application.erased',
            'to_status' => 'erased',
        ]);
    }

    public function test_expired_and_exhausted_verification_codes_cannot_authorize_data_access(): void
    {
        $this->enableLifecycle();
        $application = $this->application();
        $request = $this->requestCode($application, 'access');

        $request->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->post('/models/application-support/verify', [
            'reference' => $application->reference,
            'email' => 'jordan@example.test',
            'code' => '12345678',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_legal_hold_prevents_applicant_erasure_until_an_authorized_owner_releases_it(): void
    {
        $this->enableLifecycle();
        $application = $this->application('declined', [
            'legal_hold_at' => now(),
            'legal_hold_reason' => 'Active litigation preservation requirement.',
        ]);
        $request = $this->requestCode($application, 'erasure');
        $this->verifyCode($application, $request);

        $this->post('/models/application-support/'.$request->id.'/erase')
            ->assertRedirect('/models/application-support/verify');

        $this->assertSame('Jordan Example', $application->fresh()->full_name);
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertDatabaseHas('model_talent_application_events', [
            'application_id' => $application->id,
            'event_type' => 'application.erasure_deferred',
        ]);
    }


    public function test_administrators_can_manage_existing_applications_and_legal_holds_after_intake_is_disabled(): void
    {
        $this->enableLifecycle();
        $this->application();
        DB::table('platform_features')->where('key', 'model-agency.applications')->update(['is_enabled' => false]);

        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'agency-owner@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($owner)
            ->get('/admin/model-applications')
            ->assertOk()
            ->assertSee('Jordan Example')
            ->assertHeader('Cache-Control', 'no-store, private, max-age=0');
    }

    public function test_only_owner_or_super_admin_can_place_or_release_an_applicant_legal_hold(): void
    {
        $this->enableLifecycle();
        $application = $this->application();

        $admin = $this->createAdminUser([
            'name' => 'Agency Admin',
            'email' => 'agency-admin@example.test',
            'role' => 'admin',
        ]);

        $this->actingAs($admin)->post('/admin/model-applications/'.$application->id.'/legal-hold', [
            'enabled' => '1',
            'reason' => 'Preserve records for an active legal proceeding.',
        ])->assertForbidden();

        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'agency-owner@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($owner)->post('/admin/model-applications/'.$application->id.'/legal-hold', [
            'enabled' => '1',
            'reason' => 'Preserve records for an active legal proceeding.',
        ])->assertRedirect('/admin/model-applications');

        $application->refresh();
        $this->assertNotNull($application->legal_hold_at);
        $this->assertSame('Preserve records for an active legal proceeding.', $application->legal_hold_reason);

        $this->actingAs($owner)->post('/admin/model-applications/'.$application->id.'/legal-hold', [
            'enabled' => '0',
            'reason' => 'The legal preservation requirement has ended.',
        ])->assertRedirect('/admin/model-applications');

        $this->assertNull($application->fresh()->legal_hold_at);
    }

    public function test_verification_code_is_locked_after_five_incorrect_attempts(): void
    {
        $this->enableLifecycle();
        $application = $this->application();
        $request = $this->requestCode($application, 'access');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/models/application-support/verify', [
                'reference' => $application->reference,
                'email' => 'jordan@example.test',
                'code' => '87654321',
            ])->assertSessionHasErrors('code');
        }

        $this->assertSame('locked', $request->fresh()->status);
        $this->assertSame(5, $request->fresh()->attempts);
    }

    public function test_retention_command_anonymizes_expired_applications_and_preserves_legal_holds(): void
    {
        $this->enableLifecycle();
        $expired = $this->application('declined', ['submitted_at' => now()->subDays(120)]);
        $accepted = $this->application('accepted', ['submitted_at' => now()->subDays(400)]);
        $held = $this->application('declined', [
            'submitted_at' => now()->subDays(120),
            'legal_hold_at' => now(),
            'legal_hold_reason' => 'Active litigation preservation requirement.',
        ]);
        $recent = $this->application('declined', ['submitted_at' => now()->subDays(20)]);

        $this->artisan('talyvane:model-agency:apply-retention')->assertExitCode(0);

        $this->assertSame('erased', $expired->fresh()->status);
        $this->assertSame('erased', $accepted->fresh()->status);
        $this->assertSame('declined', $held->fresh()->status);
        $this->assertSame('declined', $recent->fresh()->status);
    }
}
