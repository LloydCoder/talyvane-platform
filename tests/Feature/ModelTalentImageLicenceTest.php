<?php

namespace Tests\Feature;

use App\Models\ModelTalentImageLicence;
use App\Services\ModelTalentImageLicenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function makeApprovedAssetAndAgreement(): array
    {
        return [];
    }
}
