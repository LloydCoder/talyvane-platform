<?php

namespace Tests\Feature;

use App\Models\ModelTalentApplication;
use App\Models\SitePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelAgencyApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function enableApplications(): void
    {
        config(['talyvane.model_agency.privacy_notice_version' => 'model-application-privacy-v1', 'talyvane.model_agency.privacy_notice_approved' => true, 'talyvane.model_agency.adult_eligibility_policy_version' => 'adult-eligibility-v1', 'talyvane.model_agency.adult_eligibility_policy_approved' => true]);
        SitePage::query()->create(['title' => 'Model Applicant Privacy Notice', 'slug' => 'model-applicant-privacy', 'body' => 'Approved model applicant privacy notice content for test. Version: model-application-privacy-v1.', 'is_published' => true, 'published_at' => now()]);
        SitePage::query()->create(['title' => 'Adult Eligibility Policy', 'slug' => 'model-adult-eligibility', 'body' => 'Approved adult eligibility policy content for test. Version: adult-eligibility-v1.', 'is_published' => true, 'published_at' => now()]);
        DB::table('platform_features')->where('key', 'model-agency.applications')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }

    public function test_adult_model_application_can_be_submitted_without_creating_a_companion_or_client_account(): void
    {
        $this->enableApplications();

        $this->get('/models/apply')->assertOk()->assertSee('Model representation')->assertSee('application.');

        $this->post('/models/apply', [
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'phone' => '+447700900123',
            'country_code' => 'GB',
            'city' => 'London',
            'portfolio_url' => 'https://portfolio.example.test/jordan',
            'experience_summary' => 'Commercial and editorial modelling experience.',
            'adult_eligibility_confirmed' => '1',
            'adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'adult_eligibility_policy_accepted' => '1',
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted' => '1',
        ])->assertRedirect('/models/apply/received');

        $application = ModelTalentApplication::query()->firstOrFail();

        $this->assertSame('submitted', $application->status);
        $this->assertSame('Jordan Example', $application->full_name);
        $this->assertSame('jordan@example.test', $application->email);
        $this->assertNotSame('jordan@example.test', DB::table('model_talent_applications')->where('id', $application->id)->value('email'));
        $this->assertNotSame('Commercial and editorial modelling experience.', DB::table('model_talent_applications')->where('id', $application->id)->value('experience_summary'));
        $this->assertNotNull($application->adult_eligibility_confirmed_at);
        $this->assertNotNull($application->adult_eligibility_policy_accepted_at);
        $this->assertSame('adult-eligibility-v1', $application->adult_eligibility_policy_version);
        $this->assertNotNull($application->privacy_notice_accepted_at);
        $this->assertSame('model-application-privacy-v1', $application->privacy_notice_version);
        $this->assertDatabaseMissing('users', ['email' => 'jordan@example.test']);
        $this->assertDatabaseHas('platform_features', [
            'key' => 'model-agency.applications',
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }

    public function test_model_application_requires_explicit_adult_eligibility_and_privacy_acknowledgements(): void
    {
        $this->enableApplications();

        $this->from('/models/apply')->post('/models/apply', [
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Commercial modelling experience.',
        ])->assertSessionHasErrors(['adult_eligibility_confirmed', 'adult_eligibility_policy_accepted', 'privacy_notice_accepted']);

        $this->assertDatabaseCount('model_talent_applications', 0);
    }

    public function test_model_application_route_fails_closed_when_feature_is_disabled(): void
    {
        $this->get('/models/apply')->assertNotFound();
    }

    public function test_admin_can_review_application_without_exposing_applicant_data_to_non_admins(): void
    {
        $this->enableApplications();
        $application = ModelTalentApplication::query()->create([
            'reference' => 'MTA-2026-000001',
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'portfolio_url' => 'https://portfolio.example.test/jordan',
            'experience_summary' => 'Editorial and commercial experience.',
            'status' => 'submitted',
            'adult_eligibility_confirmed_at' => now(),
            'adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'adult_eligibility_policy_accepted_at' => now(),
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted_at' => now(),
            'submitted_at' => now(),
        ]);

        $client = User::query()->create([
            'name' => 'Client',
            'email' => 'client@example.test',
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);

        $this->actingAs($client)->get('/admin/model-applications')->assertForbidden();

        $admin = $this->createAdminUser([
            'name' => 'Agency Admin',
            'email' => 'agency-admin@example.test',
            'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->get('/admin/model-applications')
            ->assertOk()
            ->assertSee('Jordan Example')
            ->assertSee('MTA-2026-000001');

        $this->actingAs($admin)
            ->patch('/admin/model-applications/'.$application->id, [
                'status' => 'reviewing',
                'review_note' => 'Portfolio review initiated.',
            ])
            ->assertRedirect('/admin/model-applications');

        $application->refresh();
        $this->assertSame('reviewing', $application->status);
        $this->assertSame('Portfolio review initiated.', $application->review_note);
        $this->assertSame($admin->id, $application->reviewed_by);
        $this->assertDatabaseHas('model_talent_application_events', [
            'application_id' => $application->id,
            'actor_id' => $admin->id,
            'from_status' => 'submitted',
            'to_status' => 'reviewing',
        ]);

        $this->actingAs($admin)
            ->patch('/admin/model-applications/'.$application->id, [
                'status' => 'accepted',
                'review_note' => 'Accepted to discuss a separate representation agreement.',
            ])
            ->assertRedirect('/admin/model-applications');

        $application->refresh();
        $this->assertSame('accepted', $application->status);
        $this->assertDatabaseCount('model_talent_application_events', 3);

        $this->actingAs($admin)
            ->patch('/admin/model-applications/'.$application->id, [
                'status' => 'declined',
                'review_note' => 'This terminal transition must be rejected.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('accepted', $application->fresh()->status);

    }

    public function test_application_status_transition_rejects_invalid_states(): void
    {
        $this->enableApplications();
        $application = ModelTalentApplication::query()->create([
            'reference' => 'MTA-2026-000002',
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Editorial and commercial experience.',
            'status' => 'submitted',
            'adult_eligibility_confirmed_at' => now(),
            'adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'adult_eligibility_policy_accepted_at' => now(),
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted_at' => now(),
            'submitted_at' => now(),
        ]);

        $admin = $this->createAdminUser([
            'name' => 'Agency Admin',
            'email' => 'agency-admin@example.test',
            'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->patch('/admin/model-applications/'.$application->id, [
                'status' => 'published',
                'review_note' => 'Invalid transition.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('model_talent_applications', [
            'id' => $application->id,
            'status' => 'submitted',
        ]);
    }
    public function test_submission_fails_closed_when_legal_policy_approvals_are_missing(): void
    {
        $this->enableApplications();
        config(['talyvane.model_agency.privacy_notice_approved' => false]);

        $this->post('/models/apply', [
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Commercial modelling experience and editorial portfolio.',
            'adult_eligibility_confirmed' => '1',
            'adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'adult_eligibility_policy_accepted' => '1',
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted' => '1',
        ])->assertStatus(503);

        $this->assertDatabaseCount('model_talent_applications', 0);
    }

    public function test_portfolio_links_must_use_https_and_are_never_fetched_by_the_application_flow(): void
    {
        $this->enableApplications();

        $this->from('/models/apply')->post('/models/apply', [
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'portfolio_url' => 'http://127.0.0.1/private',
            'experience_summary' => 'Commercial modelling experience and editorial portfolio.',
            'adult_eligibility_confirmed' => '1',
            'adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'adult_eligibility_policy_accepted' => '1',
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted' => '1',
        ])->assertSessionHasErrors('portfolio_url');

        $this->assertDatabaseCount('model_talent_applications', 0);
    }

    public function test_model_application_audit_events_are_append_only(): void
    {
        $this->enableApplications();
        $this->post('/models/apply', [
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Commercial modelling experience and editorial portfolio.',
            'adult_eligibility_confirmed' => '1',
            'adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'adult_eligibility_policy_accepted' => '1',
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted' => '1',
        ])->assertRedirect('/models/apply/received');

        $event = \App\Models\ModelTalentApplicationEvent::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_portfolio_links_reject_embedded_credentials_and_custom_ports(): void
    {
        $this->enableApplications();

        foreach ([
            'https://applicant:secret@portfolio.example.test/jordan',
            'https://portfolio.example.test:8443/jordan',
        ] as $url) {
            $this->from('/models/apply')->post('/models/apply', [
                'full_name' => 'Jordan Example',
                'email' => 'jordan@example.test',
                'country_code' => 'GB',
                'city' => 'London',
                'portfolio_url' => $url,
                'experience_summary' => 'Commercial modelling experience and editorial portfolio.',
                'adult_eligibility_confirmed' => '1',
                'adult_eligibility_policy_version' => 'adult-eligibility-v1',
                'adult_eligibility_policy_accepted' => '1',
                'privacy_notice_version' => 'model-application-privacy-v1',
                'privacy_notice_accepted' => '1',
            ])->assertSessionHasErrors('portfolio_url');
        }

        $this->assertDatabaseCount('model_talent_applications', 0);
    }

    public function test_model_application_audit_events_reject_updates(): void
    {
        $this->enableApplications();
        $this->post('/models/apply', [
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Commercial modelling experience and editorial portfolio.',
            'adult_eligibility_confirmed' => '1',
            'adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'adult_eligibility_policy_accepted' => '1',
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted' => '1',
        ])->assertRedirect('/models/apply/received');

        $event = \App\Models\ModelTalentApplicationEvent::query()->firstOrFail();
        $this->expectException(\LogicException::class);
        $event->forceFill(['event_type' => 'application.tampered'])->save();
    }

    public function test_feature_activation_requires_approved_privacy_and_adult_eligibility_policies(): void
    {
        $feature = \App\Models\PlatformFeature::query()->findOrFail('model-agency.applications');
        $blockers = app(\App\Services\FeatureActivationEvaluator::class)->blockers($feature);

        $this->assertContains('A legally reviewed and versioned model-applicant privacy notice must be configured and explicitly approved.', $blockers);
        $this->assertContains('A legally reviewed, versioned adult-eligibility policy must be configured and explicitly approved.', $blockers);

        config([
            'talyvane.model_agency.privacy_notice_version' => 'model-application-privacy-v1',
            'talyvane.model_agency.privacy_notice_approved' => true,
            'talyvane.model_agency.adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'talyvane.model_agency.adult_eligibility_policy_approved' => true,
        ]);

        $this->assertNotEmpty(app(\App\Services\FeatureActivationEvaluator::class)->blockers($feature));

        SitePage::query()->create(['title' => 'Model Applicant Privacy Notice', 'slug' => 'model-applicant-privacy', 'body' => 'Approved model applicant privacy notice content for test. Version: model-application-privacy-v1.', 'is_published' => true, 'published_at' => now()]);
        SitePage::query()->create(['title' => 'Adult Eligibility Policy', 'slug' => 'model-adult-eligibility', 'body' => 'Approved adult eligibility policy content for test. Version: adult-eligibility-v1.', 'is_published' => true, 'published_at' => now()]);

        $this->assertSame([], app(\App\Services\FeatureActivationEvaluator::class)->blockers($feature));
    }

}
