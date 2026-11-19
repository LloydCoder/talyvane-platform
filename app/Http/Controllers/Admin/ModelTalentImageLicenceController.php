<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Models\ModelTalentRepresentationAgreement;
use App\Services\ModelTalentImageLicenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ModelTalentImageLicenceController extends Controller
{
    private const PURPOSES = [
        'editorial',
        'commercial_campaign',
        'e_commerce',
        'social_media',
        'advertising',
        'casting_distribution',
        'private_portfolio',
        'other',
    ];

    private const CHANNELS = [
        'website',
        'print',
        'social_media',
        'email',
        'e_commerce',
        'casting_platform',
        'client_review',
        'out_of_home',
        'broadcast',
    ];

    public function store(
        Request $request,
        ModelTalentPortfolioAsset $asset,
        ModelTalentImageLicenceService $service,
    ): RedirectResponse {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'agreement_id' => ['required', 'integer', 'exists:model_talent_representation_agreements,id'],
            'licensee_name' => ['required', 'string', 'min:2', 'max:160'],
            'licensee_reference' => ['nullable', 'string', 'max:200'],
            'purpose' => ['required', 'in:'.implode(',', self::PURPOSES)],
            'channels' => ['required', 'array', 'min:1', 'max:8'],
            'channels.*' => ['required', 'distinct', 'in:'.implode(',', self::CHANNELS)],
            'purpose_details' => ['required', 'string', 'min:20', 'max:2000'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'exclusivity' => ['required', 'boolean'],
            'compensation_amount' => ['nullable', 'numeric', 'min:0.0001', 'max:999999999999999.9999'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
        ]);

        $agreement = ModelTalentRepresentationAgreement::query()->findOrFail($validated['agreement_id']);
        $service->createDraft($asset, $agreement, (int) $request->user()->id, $validated);

        return back()->with('status', 'Purpose-specific image licence draft created. No image use is authorized until external execution and model consent evidence are recorded.');
    }

    public function activate(
        Request $request,
        ModelTalentImageLicence $licence,
        ModelTalentImageLicenceService $service,
    ): RedirectResponse {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'execution_evidence_reference' => [
                'required', 'string', 'min:20', 'max:200',
                'regex:/^private\/model-licences\/[A-Za-z0-9][A-Za-z0-9._\/-]{10,180}$/',
                'not_regex:/\.\./',
            ],
            'model_consent_evidence_reference' => [
                'required', 'string', 'min:20', 'max:200',
                'regex:/^private\/model-consent\/[A-Za-z0-9][A-Za-z0-9._\/-]{10,180}$/',
                'not_regex:/\.\./',
            ],
            'confirm_external_execution' => ['required', 'accepted'],
            'confirm_model_consent' => ['required', 'accepted'],
        ]);

        $service->activate(
            $licence,
            (int) $request->user()->id,
            $validated['execution_evidence_reference'],
            $validated['model_consent_evidence_reference'],
        );

        return back()->with('status', 'External execution and purpose-specific model consent evidence recorded. The platform does not provide or validate electronic signatures.');
    }

    public function revoke(
        Request $request,
        ModelTalentImageLicence $licence,
        ModelTalentImageLicenceService $service,
    ): RedirectResponse {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'confirm_revocation' => ['required', 'accepted'],
        ]);

        $service->revoke($licence, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Image usage licence revoked.');
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_talent_image_licences')
                && Schema::hasColumns('model_talent_image_licences', [
                    'application_id', 'portfolio_asset_id', 'representation_agreement_id',
                    'status', 'purpose', 'channels', 'country_code', 'starts_on', 'ends_on',
                    'licence_policy_version',
                ]),
            503,
            'Model image licence services are temporarily unavailable.',
        );
    }
}
