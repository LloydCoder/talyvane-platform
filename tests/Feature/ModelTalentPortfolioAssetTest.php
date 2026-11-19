<?php

namespace Tests\Feature;

use App\Models\CountryMarket;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\User;
use App\Services\ModelAgencyApplicationLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Feature\Support\EnablesModelAgencyFeatureRoutes;

class ModelTalentPortfolioAssetTest extends TestCase
{
    use RefreshDatabase;
    use EnablesModelAgencyFeatureRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableModelAgencyFeatureRoutes();
    }

    private function configurePortfolioPolicy(): void
    {
        config([
            'talyvane.model_agency.portfolio_consent_version' => 'model-portfolio-consent-v1',
            'talyvane.model_agency.portfolio_consent_approved' => true,
        ]);
    }

    private function approvedMarket(): CountryMarket
    {
        return CountryMarket::query()->create([
            'country_code' => 'GB',
            'name' => 'United Kingdom',
            'is_enabled' => true,
            'compliance_status' => 'approved',
            'compliance_reviewed_at' => now(),
            'compliance_reviewed_by' => 'test-fixture',
            'compliance_evidence_reference' => 'test-market-evidence-gb',
            'compliance_review_summary' => 'Test fixture only; not approval for a real market.',
            'market_policy' => ['test_fixture' => true],
        ]);
    }

    private function application(array $overrides = []): ModelTalentApplication
    {
        $verifier = User::query()->create([
            'name' => 'Eligibility Verifier',
            'email' => 'verifier-'.strtolower(bin2hex(random_bytes(4))).'@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
            'is_active' => true,
        ]);

        return ModelTalentApplication::query()->create(array_merge([
            'reference' => 'MTA-2026-PORTFOLIO01',
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
            'adult_eligibility_verified_by' => $verifier->id,
            'adult_eligibility_verification_method' => 'manual_document_inspection',
            'privacy_notice_version' => 'privacy-v1',
            'privacy_notice_accepted_at' => now(),
            'submitted_at' => now(),
        ], $overrides));
    }

    private function uploadPayload(): array
    {
        return [
            'media' => UploadedFile::fake()->image('model-portfolio.jpg', 1000, 1400),
            'source_description' => 'Original editorial portrait supplied directly by the represented model.',
            'consent_scope' => 'Private agency portfolio review and approved casting submissions only.',
            'rights_evidence_reference' => 'private/model-rights/copyright-evidence-2026-001.pdf',
            'consent_evidence_reference' => 'private/model-consent/portfolio-consent-2026-001.pdf',
            'confirm_model_consent' => '1',
            'confirm_image_rights' => '1',
        ];
    }

    public function test_model_portfolio_upload_is_sanitized_private_and_pending_review(): void
    {
        Storage::fake('local');
        $this->configurePortfolioPolicy();
        $this->approvedMarket();
        $application = $this->application();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post('/admin/model-applications/'.$application->id.'/portfolio-assets', $this->uploadPayload())
            ->assertRedirect();

        $asset = ModelTalentPortfolioAsset::query()->firstOrFail();
        $this->assertSame('pending_review', $asset->status);
        $this->assertSame('image/webp', $asset->mime_type);
        $this->assertSame('model-portfolio-consent-v1', $asset->consent_policy_version);
        $this->assertSame('Private agency portfolio review and approved casting submissions only.', $asset->consent_scope);
        $this->assertSame(64, strlen($asset->sha256));
        $this->assertStringStartsWith('model-portfolio/', $asset->storage_path);
        Storage::disk('local')->assertExists($asset->storage_path);

        $this->actingAs($admin)
            ->get('/admin/model-portfolio-assets/'.$asset->id.'/preview')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get('/model-portfolio/'.$asset->id)->assertNotFound();
    }

    public function test_model_portfolio_review_requires_current_consent_and_withdrawal_removes_private_file(): void
    {
        Storage::fake('local');
        $this->configurePortfolioPolicy();
        $this->approvedMarket();
        $application = $this->application();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post('/admin/model-applications/'.$application->id.'/portfolio-assets', $this->uploadPayload())
            ->assertRedirect();
        $asset = ModelTalentPortfolioAsset::query()->firstOrFail();
        $path = $asset->storage_path;

        $this->actingAs($admin)
            ->post('/admin/model-portfolio-assets/'.$asset->id.'/review', [
                'decision' => 'approved',
                'review_note' => 'Evidence and consent scope checked for private portfolio curation.',
            ])
            ->assertRedirect();

        $this->assertSame('approved', $asset->fresh()->status);

        $this->actingAs($admin)
            ->post('/admin/model-portfolio-assets/'.$asset->id.'/withdraw-consent', [
                'reason' => 'The model requested withdrawal of consent for this portfolio image.',
                'withdrawal_evidence_reference' => 'private/model-consent/withdrawal-2026-001.pdf',
                'confirm_withdrawal' => '1',
            ])
            ->assertRedirect();

        $asset->refresh();
        $this->assertSame('withdrawn', $asset->status);
        $this->assertNotNull($asset->consent_withdrawn_at);
        $this->assertNull($asset->storage_path);
        Storage::disk('local')->assertMissing($path);
        $this->actingAs($admin)->get('/admin/model-portfolio-assets/'.$asset->id.'/preview')->assertNotFound();

        app(ModelAgencyApplicationLifecycleService::class)->anonymize($application, 'applicant');
        $this->assertSame('erased', $application->fresh()->status);
        $this->assertDatabaseMissing('model_talent_portfolio_assets', ['id' => $asset->id]);
    }

    public function test_upload_fails_closed_without_policy_approval_market_approval_or_adult_eligibility(): void
    {
        Storage::fake('local');
        $this->approvedMarket();
        $application = $this->application();
        $admin = $this->createAdminUser();

        config([
            'talyvane.model_agency.portfolio_consent_version' => 'model-portfolio-consent-v1',
            'talyvane.model_agency.portfolio_consent_approved' => false,
        ]);

        $this->actingAs($admin)
            ->post('/admin/model-applications/'.$application->id.'/portfolio-assets', $this->uploadPayload())
            ->assertSessionHasErrors('media');

        $this->configurePortfolioPolicy();
        CountryMarket::query()->where('country_code', 'GB')->update(['is_enabled' => false]);

        $this->actingAs($admin)
            ->post('/admin/model-applications/'.$application->id.'/portfolio-assets', $this->uploadPayload())
            ->assertSessionHasErrors('media');

        CountryMarket::query()->where('country_code', 'GB')->update(['is_enabled' => true]);
        $unverified = $this->application([
            'reference' => 'MTA-2026-PORTFOLIO02',
            'adult_eligibility_verified_at' => null,
            'adult_eligibility_verified_by' => null,
        ]);

        $this->actingAs($admin)
            ->post('/admin/model-applications/'.$unverified->id.'/portfolio-assets', $this->uploadPayload())
            ->assertSessionHasErrors('media');

        $this->assertDatabaseCount('model_talent_portfolio_assets', 0);
    }

    public function test_applicant_erasure_is_deferred_while_private_portfolio_files_exist(): void
    {
        Storage::fake('local');
        $this->configurePortfolioPolicy();
        $this->approvedMarket();
        $application = $this->application();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post('/admin/model-applications/'.$application->id.'/portfolio-assets', $this->uploadPayload())
            ->assertRedirect();

        try {
            app(ModelAgencyApplicationLifecycleService::class)->anonymize($application, 'applicant');
            $this->fail('Erasure must be deferred while private portfolio assets require removal and retention review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame('accepted', $application->fresh()->status);
    }
    public function test_outdated_consent_cannot_be_approved_but_can_be_rejected_and_removed(): void
    {
        Storage::fake('local');
        $this->configurePortfolioPolicy();
        $this->approvedMarket();
        $application = $this->application();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post('/admin/model-applications/'.$application->id.'/portfolio-assets', $this->uploadPayload())
            ->assertRedirect();
        $asset = ModelTalentPortfolioAsset::query()->firstOrFail();
        $path = $asset->storage_path;

        config(['talyvane.model_agency.portfolio_consent_version' => 'model-portfolio-consent-v2']);

        $this->actingAs($admin)
            ->post('/admin/model-portfolio-assets/'.$asset->id.'/review', [
                'decision' => 'approved',
                'review_note' => 'Attempted approval under a stale consent policy version.',
            ])
            ->assertSessionHasErrors('decision');

        $this->assertSame('pending_review', $asset->fresh()->status);

        $this->actingAs($admin)
            ->post('/admin/model-portfolio-assets/'.$asset->id.'/review', [
                'decision' => 'rejected',
                'review_note' => 'Rejected because the asset consent version is no longer current.',
            ])
            ->assertRedirect();

        $this->assertSame('rejected', $asset->fresh()->status);
        $this->assertNull($asset->fresh()->storage_path);
        Storage::disk('local')->assertMissing($path);
    }

}
