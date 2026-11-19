<?php

namespace Tests\Feature;

use App\Models\ModelTalentApplication;
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
        DB::table('platform_features')->where('key', 'model-agency.applications')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
            'activation_requirements' => json_encode([]),
        ]);
    }

    public function test_adult_model_application_can_be_submitted_without_creating_a_companion_or_client_account(): void
    {
        $this->enableApplications();

        $this->get('/models/apply')->assertOk()->assertSee('Model representation application');

        $this->post('/models/apply', [
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'phone' => '+447700900123',
            'country_code' => 'GB',
            'city' => 'London',
            'portfolio_url' => 'https://portfolio.example.test/jordan',
            'experience_summary' => 'Commercial and editorial modelling experience.',
            'adult_eligibility_confirmed' => '1',
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted' => '1',
        ])->assertRedirect('/models/apply/received');

        $application = ModelTalentApplication::query()->firstOrFail();

        $this->assertSame('submitted', $application->status);
        $this->assertSame('Jordan Example', $application->full_name);
        $this->assertSame('jordan@example.test', $application->email);
        $this->assertNotNull($application->adult_eligibility_confirmed_at);
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
        ])->assertSessionHasErrors(['adult_eligibility_confirmed', 'privacy_notice_accepted']);

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

        $this->assertDatabaseHas('model_talent_applications', [
            'id' => $application->id,
            'status' => 'reviewing',
            'review_note' => 'Portfolio review initiated.',
            'reviewed_by' => $admin->id,
        ]);
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
}
