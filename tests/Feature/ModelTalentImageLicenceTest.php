<?php

namespace Tests\Feature;

use App\Models\ModelTalentImageLicence;
use App\Services\ModelTalentImageLicenceService;
use App\Services\ModelTalentPortfolioAssetService;
use App\Services\ModelTalentRepresentationAgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModelTalentImageLicenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_licence_can_only_be_activated_after_external_execution_and_model_consent_are_recorded(): void
    {
        $context = $this->makeApprovedAssetAndAgreement();
        $service = app(ModelTalentImageLicenceService::class);
        $licence = $service->createDraft(
            $context['asset'],
            $context['agreement'],
            $context['admin']->id,
            [
                'licensee_name' => 'Northstar Campaign Ltd',
                'licensee_reference' => 'customer-contract-REF-2026',
                'purpose' => 'commercial_campaign',
                'channels' => ['website', 'social_media'],
                'purpose_details' => 'Named campaign only; no unrelated reuse or sublicensing.',
                'country_code' => 'GB',
                'starts_on' => now()->addDays(2)->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
                'exclusivity' => false,
                'compensation_amount' => '1250.00',
                'currency_code' => 'GBP',
            ],
        );

        $this->assertSame('draft', $licence->status);

        $service->activate(
            $licence,
            $context['admin']->id,
            'private/model-licences/signed-licence-2026-001.pdf',
            'private/model-consent/campaign-consent-2026-001.pdf',
        );

        $this->assertSame('active', $licence->fresh()->status);
    }

    private function activeLicence(array $context): \App\Models\ModelTalentImageLicence
    {
        $service = app(ModelTalentImageLicenceService::class);
        $licence = $service->createDraft(
            $context['asset'],
            $context['agreement'],
            $context['admin']->id,
            [
                'licensee_name' => 'Northstar Campaign Ltd',
                'licensee_reference' => 'customer-contract-REF-2026',
                'purpose' => 'commercial_campaign',
                'channels' => ['website', 'social_media'],
                'purpose_details' => 'Named campaign only; no unrelated reuse or sublicensing.',
                'country_code' => 'GB',
                'starts_on' => now()->addDays(2)->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
                'exclusivity' => false,
                'compensation_amount' => '1250.00',
                'currency_code' => 'GBP',
            ],
        );
        $service->activate(
            $licence,
            $context['admin']->id,
            'private/model-licences/signed-licence-2026-002.pdf',
            'private/model-consent/campaign-consent-2026-002.pdf',
        );

        return $licence;
    }

    private function makeApprovedAssetAndAgreement(): array
    {
        config([
            'talyvane.model_agency.image_licence_policy_version' => 'image-licence-policy-v1',
            'talyvane.model_agency.image_licence_policy_approved' => true,
            'talyvane.model_agency.portfolio_consent_version' => 'model-portfolio-consent-v1',
            'talyvane.model_agency.portfolio_consent_approved' => true,
            'talyvane.model_agency.representation_terms_version' => 'representation-terms-v1',
            'talyvane.model_agency.representation_terms_approved' => true,
        ]);
        \Illuminate\Support\Facades\Storage::fake('local');
        $admin = $this->createAdminUser();
        $verifier = \App\Models\User::query()->create([
            'name' => 'Eligibility Verifier',
            'email' => 'verifier-'.strtolower(bin2hex(random_bytes(4))).'@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
            'is_active' => true,
        ]);
        \App\Models\CountryMarket::query()->create([
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
        \App\Models\Currency::query()->create([
            'code' => 'GBP',
            'numeric_code' => '826',
            'name' => 'Pound Sterling',
            'symbol' => '£',
            'minor_unit' => 2,
            'is_enabled' => true,
            'metadata' => [],
        ]);
        $application = \App\Models\ModelTalentApplication::query()->create([
            'reference' => 'MTA-2026-LICENCE01',
            'full_name' => 'Jordan Example',
            'email' => 'jordan@example.test',
            'country_code' => 'GB',
            'city' => 'London',
            'experience_summary' => 'Commercial modelling experience.',
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
        $agreement = \App\Models\ModelTalentRepresentationAgreement::query()->create([
            'application_id' => $application->id,
            'country_code' => 'GB',
            'reference' => 'MRA-2026-LICENCE01',
            'version' => 1,
            'status' => 'executed',
            'terms_snapshot' => ['representation_scope' => 'Commercial model representation.'],
            'terms_policy_version' => 'representation-terms-v1',
            'created_by' => $admin->id,
            'offered_by' => $admin->id,
            'offered_at' => now()->subDays(3),
            'executed_by' => $admin->id,
            'executed_at' => now()->subDays(2),
            'executed_document_reference' => 'private/model-agreements/signed-representation-2026-001.pdf',
            'effective_from' => now()->subDay()->toDateString(),
            'effective_until' => now()->addMonths(3)->toDateString(),
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put('model-portfolio/licence-asset.webp', 'sanitized-image-bytes');
        $asset = \App\Models\ModelTalentPortfolioAsset::query()->create([
            'application_id' => $application->id,
            'uploaded_by' => $admin->id,
            'storage_path' => 'model-portfolio/licence-asset.webp',
            'mime_type' => 'image/webp',
            'width' => 1000,
            'height' => 1400,
            'file_size' => 100,
            'sha256' => hash('sha256', 'sanitized-image-bytes'),
            'status' => 'approved',
            'source_description' => 'Image supplied by the represented model.',
            'consent_scope' => 'Private agency portfolio curation only.',
            'rights_evidence_reference' => 'private/model-rights/licence-rights-evidence-2026.pdf',
            'consent_evidence_reference' => 'private/model-consent/portfolio-consent-2026.pdf',
            'consent_policy_version' => 'model-portfolio-consent-v1',
            'consent_recorded_at' => now()->subDays(5),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subDays(4),
            'review_note' => 'Rights and private portfolio consent checked.',
        ]);

        return compact('admin', 'application', 'agreement', 'asset');
    }
}
    public function test_withdrawing_portfolio_consent_revokes_active_licences(): void
    {
        $context = $this->makeApprovedAssetAndAgreement();
        $licence = $this->activeLicence($context);

        app(ModelTalentPortfolioAssetService::class)->withdrawConsent(
            $context['asset'],
            $context['admin']->id,
            'The model requested withdrawal of consent for this portfolio image.',
            'private/model-consent/withdrawal-licence-2026.pdf',
        );

        $this->assertSame('revoked', $licence->fresh()->status);
        $this->assertNotNull($licence->fresh()->revoked_at);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('model-portfolio/licence-asset.webp');
    }

    public function test_terminating_representation_revokes_active_licences(): void
    {
        $context = $this->makeApprovedAssetAndAgreement();
        $licence = $this->activeLicence($context);

        app(ModelTalentRepresentationAgreementService::class)->terminate(
            $context['agreement'],
            $context['admin']->id,
            'The representation relationship has been terminated under the executed agreement.',
        );

        $this->assertSame('revoked', $licence->fresh()->status);
    }

    public function test_image_licence_expiry_command_marks_past_terms_expired(): void
    {
        $context = $this->makeApprovedAssetAndAgreement();
        $licence = $this->activeLicence($context);
        $licence->forceFill(['ends_on' => now()->subDay()->toDateString()])->save();

        $this->artisan('talyvane:model-agency:expire-image-licences')->assertExitCode(0);

        $this->assertSame('expired', $licence->fresh()->status);
    }

    public function test_image_licence_requires_a_current_approved_policy_and_market(): void
    {
        $context = $this->makeApprovedAssetAndAgreement();
        config(['talyvane.model_agency.image_licence_policy_approved' => false]);

        try {
            app(ModelTalentImageLicenceService::class)->createDraft(
                $context['asset'],
                $context['agreement'],
                $context['admin']->id,
                [
                    'licensee_name' => 'Northstar Campaign Ltd',
                    'purpose' => 'commercial_campaign',
                    'channels' => ['website'],
                    'purpose_details' => 'Named campaign only; no unrelated reuse or sublicensing.',
                    'country_code' => 'GB',
                    'starts_on' => now()->addDays(2)->toDateString(),
                    'ends_on' => now()->addDays(30)->toDateString(),
                    'exclusivity' => false,
                ],
            );
            $this->fail('An unapproved licence policy must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('licence', $exception->errors());
        }
    }

