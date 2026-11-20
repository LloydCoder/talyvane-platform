<?php

namespace Tests\Feature;

use App\Models\ModelTalentApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdultEligibilityVerifierBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function application(): ModelTalentApplication
    {
        return ModelTalentApplication::query()->create([
            'reference' => 'MTA-2026-VERIFY01',
            'full_name' => 'Jordan Example',
            'email' => 'private-applicant@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Private applicant information.',
            'status' => 'reviewing',
            'adult_eligibility_confirmed_at' => now(),
            'adult_eligibility_policy_version' => 'adult-eligibility-v1',
            'adult_eligibility_policy_accepted_at' => now(),
            'privacy_notice_version' => 'model-application-privacy-v1',
            'privacy_notice_accepted_at' => now(),
            'submitted_at' => now(),
        ]);
    }

    public function test_verifier_can_access_only_the_minimal_eligibility_queue_and_record_a_review(): void
    {
        config([
            'talyvane.model_agency.adult_eligibility_policy_approved' => true,
            'talyvane.model_agency.adult_eligibility_policy_version' => 'adult-eligibility-v1',
        ]);

        $application = $this->application();
        $verifier = $this->createAdminUser([
            'name' => 'Eligibility Verifier',
            'email' => 'verifier@example.test',
            'role' => 'verifier',
        ]);

        $this->actingAs($verifier)
            ->get('/admin/adult-eligibility')
            ->assertOk()
            ->assertSee('MTA-2026-VERIFY01')
            ->assertSee('Jordan Example')
            ->assertDontSee('private-applicant@example.test')
            ->assertDontSee('Private applicant information.');

        $this->actingAs($verifier)
            ->post('/admin/adult-eligibility/'.$application->id.'/verify', [
                'identity_matches_applicant' => '1',
                'document_is_valid' => '1',
                'meets_policy_age_threshold' => '1',
                'verification_method' => 'manual_document_inspection',
            ])
            ->assertRedirect('/admin/adult-eligibility');

        $this->assertSame($verifier->id, $application->fresh()->adult_eligibility_verified_by);
        $this->assertDatabaseHas('model_talent_application_events', [
            'application_id' => $application->id,
            'actor_id' => $verifier->id,
            'event_type' => 'application.adult_eligibility_verified',
        ]);
    }

    public function test_verifier_cannot_access_general_agency_or_finance_workspaces_or_change_application_status(): void
    {
        $application = $this->application();
        $verifier = $this->createAdminUser([
            'name' => 'Eligibility Verifier',
            'email' => 'verifier-boundary@example.test',
            'role' => 'verifier',
        ]);

        $this->actingAs($verifier)->get('/admin')->assertForbidden();
        $this->actingAs($verifier)->get('/admin/model-applications')->assertForbidden();
        $this->actingAs($verifier)->get('/admin/model-settlements')->assertForbidden();

        $this->actingAs($verifier)
            ->patch('/admin/model-applications/'.$application->id, [
                'status' => 'accepted',
                'review_note' => 'This verifier must not be able to accept applications.',
            ])
            ->assertForbidden();

        $this->assertSame('reviewing', $application->fresh()->status);
        $this->assertNull($application->fresh()->adult_eligibility_verified_at);
    }

    public function test_agency_admin_cannot_record_adult_eligibility_verification(): void
    {
        config([
            'talyvane.model_agency.adult_eligibility_policy_approved' => true,
            'talyvane.model_agency.adult_eligibility_policy_version' => 'adult-eligibility-v1',
        ]);

        $application = $this->application();
        $admin = $this->createAdminUser([
            'name' => 'Agency Administrator',
            'email' => 'agency-admin-verify@example.test',
            'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->post('/admin/model-applications/'.$application->id.'/adult-eligibility', [
                'identity_matches_applicant' => '1',
                'document_is_valid' => '1',
                'meets_policy_age_threshold' => '1',
                'verification_method' => 'manual_document_inspection',
            ])
            ->assertForbidden();

        $this->assertNull($application->fresh()->adult_eligibility_verified_at);
        $this->assertDatabaseMissing('model_talent_application_events', [
            'application_id' => $application->id,
            'event_type' => 'application.adult_eligibility_verified',
        ]);
    }

    public function test_an_authorized_verifier_can_verify_and_an_agency_owner_can_accept_the_application(): void
    {
        config([
            'talyvane.model_agency.adult_eligibility_policy_approved' => true,
            'talyvane.model_agency.adult_eligibility_policy_version' => 'adult-eligibility-v1',
        ]);

        $application = $this->application();
        $verifier = $this->createAdminUser([
            'name' => 'Eligibility Verifier',
            'email' => 'verifier-acceptance@example.test',
            'role' => 'verifier',
        ]);

        $this->actingAs($verifier)
            ->post('/admin/adult-eligibility/'.$application->id.'/verify', [
                'identity_matches_applicant' => '1',
                'document_is_valid' => '1',
                'meets_policy_age_threshold' => '1',
                'verification_method' => 'manual_document_inspection',
            ])
            ->assertRedirect('/admin/adult-eligibility');

        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'agency-owner-acceptance@example.test',
            'role' => 'owner',
        ]);

        $this->actingAs($owner)
            ->patch('/admin/model-applications/'.$application->id, [
                'status' => 'accepted',
                'review_note' => 'Eligibility is verified and the application may proceed.',
            ])
            ->assertRedirect(route('admin.model-applications.index'));

        $this->assertSame('accepted', $application->fresh()->status);
        $this->assertNotNull($application->fresh()->adult_eligibility_verified_at);
        $this->assertSame($verifier->id, $application->fresh()->adult_eligibility_verified_by);
    }

    public function test_existing_agency_roles_cannot_use_the_verifier_only_queue(): void
    {
        $this->application();
        $admin = $this->createAdminUser([
            'name' => 'Agency Administrator',
            'email' => 'agency-admin-boundary@example.test',
            'role' => 'admin',
        ]);

        $this->actingAs($admin)->get('/admin/adult-eligibility')->assertForbidden();
    }
}
