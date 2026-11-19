<?php

namespace Tests\Feature;

use App\Models\ModelTalentApplication;
use App\Models\ModelTalentRepresentationAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelTalentRepresentationAgreementTest extends TestCase
{
    use RefreshDatabase;

    private function application(array $overrides = []): ModelTalentApplication
    {
        $owner = User::query()->create([
            'name' => 'Eligibility Verifier',
            'email' => 'verifier@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
            'is_active' => true,
        ]);

        return ModelTalentApplication::query()->create(array_merge([
            'reference' => 'MTA-2026-AGREE01',
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Editorial and commercial modelling experience.',
            'status' => 'accepted',
            'adult_eligibility_confirmed_at' => now(),
            'adult_eligibility_policy_version' => 'adult-policy-v1',
            'adult_eligibility_policy_accepted_at' => now(),
            'adult_eligibility_verified_at' => now(),
            'adult_eligibility_verified_by' => $owner->id,
            'adult_eligibility_verification_method' => 'manual_document_inspection',
            'privacy_notice_version' => 'privacy-v1',
            'privacy_notice_accepted_at' => now(),
            'submitted_at' => now(),
        ], $overrides));
    }

    private function terms(): array
    {
        return [
            'territory' => 'United Kingdom — subject to local legal approval',
            'representation_scope' => 'Commercial and editorial model representation within the approved territory.',
            'exclusivity' => 'non_exclusive',
            'commission_percent' => '15.00',
            'term_months' => 12,
            'notice_days' => 30,
            'image_usage_scope' => 'Only separately approved campaign-specific image usage with a recorded licence.',
            'compensation_terms' => 'Compensation and commission are disclosed per assignment before acceptance.',
            'expense_terms' => 'No expense is deducted without prior written disclosure and authorization.',
            'withdrawal_and_consent_terms' => 'Model may withdraw purpose-specific image consent subject to applicable law and executed terms.',
        ];
    }

    public function test_agreement_requires_accepted_verified_applicant_and_no_legal_hold(): void
    {
        $admin = $this->createAdminUser();
        $unverified = $this->application([
            'reference' => 'MTA-2026-AGREE02',
            'adult_eligibility_verified_at' => null,
            'adult_eligibility_verified_by' => null,
        ]);

        $this->actingAs($admin)->post('/admin/model-applications/'.$unverified->id.'/agreements', $this->terms())
            ->assertSessionHasErrors('agreement');

        $held = $this->application([
            'reference' => 'MTA-2026-AGREE03',
            'legal_hold_at' => now(),
            'legal_hold_by' => $admin->id,
            'legal_hold_reason' => 'A documented preservation requirement applies to this applicant.',
        ]);

        $this->actingAs($admin)->post('/admin/model-applications/'.$held->id.'/agreements', $this->terms())
            ->assertSessionHasErrors('agreement');

        $this->assertDatabaseCount('model_talent_representation_agreements', 0);
    }

    public function test_agreement_lifecycle_records_draft_offer_external_execution_and_termination(): void
    {
        config([
            'talyvane.model_agency.representation_terms_version' => 'representation-terms-v1',
            'talyvane.model_agency.representation_terms_approved' => true,
        ]);
        $application = $this->application();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post('/admin/model-applications/'.$application->id.'/agreements', $this->terms())
            ->assertRedirect();

        $agreement = ModelTalentRepresentationAgreement::query()->firstOrFail();
        $this->assertSame('draft', $agreement->status);
        $this->assertSame('representation-terms-v1', $agreement->terms_policy_version);
        $this->assertSame('United Kingdom — subject to local legal approval', $agreement->terms_snapshot['territory']);

        $this->actingAs($admin)->post('/admin/model-representation-agreements/'.$agreement->id.'/offer')
            ->assertRedirect();
        $this->assertSame('offered', $agreement->fresh()->status);

        $this->actingAs($admin)->post('/admin/model-representation-agreements/'.$agreement->id.'/execute', [
            'executed_document_reference' => 'private/model-agreements/signed-contract-2026-0001.pdf',
            'effective_from' => '2026-12-01',
            'effective_until' => '2027-11-30',
            'confirm_externally_executed' => '1',
        ])->assertRedirect();

        $agreement->refresh();
        $this->assertSame('executed', $agreement->status);
        $this->assertSame('2026-12-01', $agreement->effective_from->format('Y-m-d'));
        $this->assertNotNull($agreement->executed_at);
        $this->assertNotSame('private/model-agreements/signed-contract-2026-0001.pdf', DB::table('model_talent_representation_agreements')->where('id', $agreement->id)->value('executed_document_reference'));

        $this->actingAs($admin)->post('/admin/model-representation-agreements/'.$agreement->id.'/terminate', [
            'reason' => 'The parties agreed to end representation under the applicable notice provision.',
            'confirm_termination' => '1',
        ])->assertRedirect();

        $this->assertSame('terminated', $agreement->fresh()->status);
        $this->assertDatabaseHas('model_talent_application_events', [
            'application_id' => $application->id,
            'event_type' => 'application.representation_executed_recorded',
        ]);
        $this->assertDatabaseHas('model_talent_application_events', [
            'application_id' => $application->id,
            'event_type' => 'application.representation_agreement_terminated',
        ]);
    }

    public function test_offer_fails_closed_without_current_approved_terms_and_document_reference_must_be_private(): void
    {
        config([
            'talyvane.model_agency.representation_terms_version' => 'representation-terms-v1',
            'talyvane.model_agency.representation_terms_approved' => false,
        ]);
        $application = $this->application();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->post('/admin/model-applications/'.$application->id.'/agreements', $this->terms())
            ->assertRedirect();
        $agreement = ModelTalentRepresentationAgreement::query()->firstOrFail();

        $this->actingAs($admin)->post('/admin/model-representation-agreements/'.$agreement->id.'/offer')
            ->assertSessionHasErrors('agreement');
        $this->assertSame('draft', $agreement->fresh()->status);

        config(['talyvane.model_agency.representation_terms_approved' => true]);
        $this->actingAs($admin)->post('/admin/model-representation-agreements/'.$agreement->id.'/offer')
            ->assertRedirect();

        $this->actingAs($admin)->post('/admin/model-representation-agreements/'.$agreement->id.'/execute', [
            'executed_document_reference' => 'https://example.test/public-contract.pdf',
            'effective_from' => '2026-12-01',
            'confirm_externally_executed' => '1',
        ])->assertSessionHasErrors('executed_document_reference');

        $this->assertSame('offered', $agreement->fresh()->status);
    }

    public function test_non_administrator_cannot_create_representation_agreements(): void
    {
        $application = $this->application();
        $client = User::query()->create([
            'name' => 'Client',
            'email' => 'client-agreement@example.test',
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);

        $this->actingAs($client)->post('/admin/model-applications/'.$application->id.'/agreements', $this->terms())
            ->assertForbidden();

        $this->assertDatabaseCount('model_talent_representation_agreements', 0);
    }
}
    public function test_applicant_erasure_is_deferred_when_representation_agreement_records_exist(): void
    {
        $application = $this->application();
        $admin = $this->createAdminUser();
        ModelTalentRepresentationAgreement::query()->create([
            'application_id' => $application->id,
            'reference' => 'MRA-2026-ERASURE01',
            'version' => 1,
            'status' => 'draft',
            'terms_snapshot' => $this->terms(),
            'terms_policy_version' => 'terms-v1',
            'created_by' => $admin->id,
        ]);

        try {
            app(ModelAgencyApplicationLifecycleService::class)->anonymize($application, 'applicant');
            $this->fail('Erasure must be deferred until agreement retention has been reviewed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('accepted', $application->fresh()->status);
        $this->assertSame('jordan@example.test', $application->fresh()->email);
    }

