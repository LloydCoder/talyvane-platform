<?php

namespace Tests\Feature;

use App\Models\CountryMarket;
use App\Models\Currency;
use App\Models\ModelCastingCall;
use App\Models\ModelCastingSubmission;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\ModelTalentRepresentationAgreement;
use App\Models\User;
use App\Services\ModelCastingClientPortalService;
use App\Services\ModelCastingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModelCastingClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private function enableFeature(): void
    {
        DB::table('platform_features')->whereIn('key', [
            'model-agency.applications',
            'model-agency.usage-rights',
            'model-agency.casting',
            'model-agency.client-casting',
        ])->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }

    private function context(array $channels = ['casting_platform', 'client_review']): array
    {
        config([
            'talyvane.model_agency.portfolio_consent_version' => 'portfolio-consent-v1',
            'talyvane.model_agency.portfolio_consent_approved' => true,
            'talyvane.model_agency.image_licence_policy_version' => 'image-licence-policy-v1',
            'talyvane.model_agency.image_licence_policy_approved' => true,
            'talyvane.model_agency.representation_terms_version' => 'representation-terms-v1',
            'talyvane.model_agency.representation_terms_approved' => true,
            'talyvane.model_agency.casting_policy_version' => 'casting-policy-v1',
            'talyvane.model_agency.casting_policy_approved' => true,
            'talyvane.model_agency.client_review_policy_version' => 'client-review-policy-v1',
            'talyvane.model_agency.client_review_policy_approved' => true,
            'talyvane.model_agency.client_review_access_days' => 30,
        ]);
        Storage::fake('local');
        $this->enableFeature();

        $admin = $this->createAdminUser(['email' => 'casting-admin-'.strtolower(bin2hex(random_bytes(4))).'@example.test']);
        $client = User::query()->create([
            'name' => 'Casting Client',
            'email' => 'casting-client-'.strtolower(bin2hex(random_bytes(4))).'@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        $verifier = User::query()->create([
            'name' => 'Eligibility Verifier',
            'email' => 'casting-verifier-'.strtolower(bin2hex(random_bytes(4))).'@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
            'is_active' => true,
        ]);

        CountryMarket::query()->create([
            'country_code' => 'GB',
            'name' => 'United Kingdom',
            'is_enabled' => true,
            'compliance_status' => 'approved',
            'compliance_reviewed_at' => now(),
            'compliance_reviewed_by' => 'test-fixture',
            'compliance_evidence_reference' => 'test-market-evidence-gb',
            'compliance_review_summary' => 'Test fixture only.',
            'market_policy' => ['test_fixture' => true],
        ]);
        Currency::query()->create([
            'code' => 'GBP',
            'numeric_code' => '826',
            'name' => 'Pound Sterling',
            'symbol' => '£',
            'minor_unit' => 2,
            'is_enabled' => true,
            'metadata' => [],
        ]);

        $application = ModelTalentApplication::query()->create([
            'reference' => 'MTA-CLIENT-'.strtoupper(bin2hex(random_bytes(3))),
            'full_name' => 'Private Model Name',
            'email' => 'private-model-'.strtolower(bin2hex(random_bytes(3))).'@example.test',
            'phone' => '+447700900999',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Editorial and commercial modelling experience.',
            'status' => 'accepted',
            'adult_eligibility_confirmed_at' => now(),
            'adult_eligibility_policy_version' => 'adult-policy-v1',
            'adult_eligibility_policy_accepted_at' => now(),
            'adult_eligibility_verified_at' => now(),
            'adult_eligibility_verified_by' => $verifier->id,
            'adult_eligibility_verification_method' => 'manual_document_inspection',
            'privacy_notice_version' => 'privacy-v1',
            'privacy_notice_accepted_at' => now(),
            'submitted_at' => now(),
        ]);

        $agreement = ModelTalentRepresentationAgreement::query()->create([
            'application_id' => $application->id,
            'country_code' => 'GB',
            'reference' => 'MRA-CLIENT-'.strtoupper(bin2hex(random_bytes(3))),
            'version' => 1,
            'status' => 'executed',
            'terms_snapshot' => ['commission_percent' => '15.00'],
            'terms_policy_version' => 'representation-terms-v1',
            'created_by' => $admin->id,
            'offered_by' => $admin->id,
            'offered_at' => now()->subDays(3),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDays(2),
            'executed_document_reference' => 'private/model-agreements/client-review-agreement.pdf',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_until' => now()->addMonths(3)->toDateString(),
        ]);

        Storage::disk('local')->put('model-portfolio/client-review.webp', 'private-model-image-bytes');
        $asset = ModelTalentPortfolioAsset::query()->create([
            'application_id' => $application->id,
            'uploaded_by' => $admin->id,
            'storage_path' => 'model-portfolio/client-review.webp',
            'mime_type' => 'image/webp',
            'width' => 1000,
            'height' => 1400,
            'file_size' => 100,
            'sha256' => hash('sha256', 'private-model-image-bytes'),
            'status' => 'approved',
            'source_description' => 'Image supplied by the model.',
            'consent_scope' => 'Private agency casting and named client review only.',
            'rights_evidence_reference' => 'private/model-rights/client-review-rights.pdf',
            'consent_evidence_reference' => 'private/model-consent/client-review-consent.pdf',
            'consent_policy_version' => 'portfolio-consent-v1',
            'consent_recorded_at' => now()->subDays(5),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDays(4),
            'review_note' => 'Rights and consent reviewed.',
        ]);

        $licence = ModelTalentImageLicence::query()->create([
            'application_id' => $application->id,
            'portfolio_asset_id' => $asset->id,
            'representation_agreement_id' => $agreement->id,
            'reference' => 'MTL-CLIENT-'.strtoupper(bin2hex(random_bytes(3))),
            'status' => 'active',
            'licensee_name' => 'Northstar Studio Ltd',
            'purpose' => 'casting_distribution',
            'channels' => $channels,
            'purpose_details' => 'Private casting and client review only; no public distribution or campaign use.',
            'country_code' => 'GB',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(20)->toDateString(),
            'exclusivity' => false,
            'licence_policy_version' => 'image-licence-policy-v1',
            'created_by' => $admin->id,
            'activated_by' => $admin->id,
            'activated_at' => now(),
            'execution_evidence_reference' => 'private/model-licences/client-review-licence.pdf',
            'model_consent_evidence_reference' => 'private/model-consent/client-review-licence-consent.pdf',
        ]);

        $workflow = app(ModelCastingWorkflowService::class);
        $call = $workflow->createDraft($admin->id, [
            'title' => 'Private Winter Editorial Casting',
            'client_name' => 'Northstar Studio Ltd',
            'project_purpose' => 'editorial',
            'description' => 'Private editorial casting brief for a winter campaign.',
            'requirements' => 'Adult models with editorial experience.',
            'country_code' => 'GB',
            'opens_at' => now()->subMinutes(5)->toDateTimeString(),
            'closes_at' => now()->addDay()->toDateTimeString(),
            'shoot_starts_at' => now()->addDays(3)->toDateTimeString(),
            'shoot_ends_at' => now()->addDays(4)->toDateTimeString(),
            'compensation_amount' => '800.00',
            'currency_code' => 'GBP',
            'max_submissions' => 20,
        ]);
        $workflow->publish($call, $admin->id);
        $submission = $workflow->submit($call, $application, $asset, $licence, $admin->id);
        $workflow->decide($submission, $admin->id, 'selected', 'Human agency selection for private client review.');
        $call->forceFill(['status' => 'closed'])->save();

        return compact('admin', 'client', 'application', 'agreement', 'asset', 'licence', 'call', 'submission');
    }

    public function test_client_only_sees_assigned_calls_and_licensed_private_media(): void
    {
        $context = $this->context();
        $this->actingAs($context['client'])->get('/client/model-castings')->assertOk()->assertDontSee($context['call']->title);

        app(ModelCastingClientPortalService::class)->grantAccess(
            $context['call'],
            $context['client'],
            $context['admin']->id,
            now()->addDays(7),
            'Named client has a reviewed commercial need to evaluate this private casting.',
        );

        $this->actingAs($context['client'])
            ->get('/client/model-castings')
            ->assertOk()
            ->assertSee($context['call']->title);

        $this->actingAs($context['client'])
            ->get('/client/model-castings/'.$context['call']->id)
            ->assertOk()
            ->assertSee($context['submission']->reference)
            ->assertDontSee($context['application']->email)
            ->assertDontSee('+447700900999')
            ->assertDontSee('model-portfolio/client-review.webp');

        $response = $this->actingAs($context['client'])
            ->get('/client/model-castings/submissions/'.$context['submission']->id.'/media')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private, max-age=0')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertSame('private-model-image-bytes', $response->getContent());
    }

    public function test_client_review_is_advisory_and_revoked_access_blocks_all_subsequent_views(): void
    {
        $context = $this->context();
        $service = app(ModelCastingClientPortalService::class);
        $access = $service->grantAccess(
            $context['call'],
            $context['client'],
            $context['admin']->id,
            now()->addDays(7),
            'Named client has a reviewed commercial need to evaluate this private casting.',
        );

        $this->actingAs($context['client'])
            ->post('/client/model-castings/submissions/'.$context['submission']->id.'/review', [
                'decision' => 'shortlisted',
                'note' => 'Client requests that the agency retain this model in the shortlist for final human review.',
            ])
            ->assertRedirect();

        $this->assertSame('selected', $context['submission']->fresh()->status);
        $this->assertDatabaseHas('model_casting_client_reviews', [
            'submission_id' => $context['submission']->id,
            'client_user_id' => $context['client']->id,
            'decision' => 'shortlisted',
        ]);

        $service->revokeAccess($access, $context['admin']->id, 'Client review window closed after the agreed decision period.');

        $this->actingAs($context['client'])
            ->get('/client/model-castings/'.$context['call']->id)
            ->assertNotFound();
        $this->actingAs($context['client'])
            ->get('/client/model-castings/submissions/'.$context['submission']->id.'/media')
            ->assertNotFound();
    }

    public function test_active_casting_licence_without_client_review_channel_does_not_expose_submission(): void
    {
        $context = $this->context(['casting_platform']);
        app(ModelCastingClientPortalService::class)->grantAccess(
            $context['call'],
            $context['client'],
            $context['admin']->id,
            now()->addDays(7),
            'Named client has a reviewed commercial need to evaluate this private casting.',
        );

        $this->actingAs($context['client'])
            ->get('/client/model-castings/'.$context['call']->id)
            ->assertOk()
            ->assertDontSee($context['submission']->reference);

        $this->actingAs($context['client'])
            ->get('/client/model-castings/submissions/'.$context['submission']->id.'/media')
            ->assertNotFound();
    }

    public function test_another_client_cannot_use_an_existing_clients_casting_access(): void
    {
        $context = $this->context();
        $otherClient = User::query()->create([
            'name' => 'Other Casting Client',
            'email' => 'other-casting-client@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        $otherClient->forceFill(['email_verified_at' => now()])->save();

        app(ModelCastingClientPortalService::class)->grantAccess(
            $context['call'],
            $context['client'],
            $context['admin']->id,
            now()->addDays(7),
            'Named client has a reviewed commercial need to evaluate this private casting.',
        );

        $this->actingAs($otherClient)
            ->get('/client/model-castings/'.$context['call']->id)
            ->assertNotFound();
    }

    public function test_client_access_requires_verified_client_and_a_bounded_expiry(): void
    {
        $context = $this->context();
        $unverified = User::query()->create([
            'name' => 'Unverified Casting Client',
            'email' => 'unverified-casting-client@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);

        try {
            app(ModelCastingClientPortalService::class)->grantAccess(
                $context['call'],
                $unverified,
                $context['admin']->id,
                now()->addDays(7),
                'An unverified client must not receive private model portfolio access.',
            );
            $this->fail('An unverified client cannot be granted casting access.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('access', $exception->errors());
        }

        try {
            app(ModelCastingClientPortalService::class)->grantAccess(
                $context['call'],
                $context['client'],
                $context['admin']->id,
                now()->addDays(90),
                'A client access grant must have a short and bounded expiry period.',
            );
            $this->fail('A client access grant cannot exceed the configured maximum duration.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('access', $exception->errors());
        }
    }
}
