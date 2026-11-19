<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentPortfolioAsset;
use App\Services\ModelTalentPortfolioAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ModelTalentPortfolioAssetController extends Controller
{
    public function store(
        Request $request,
        ModelTalentApplication $application,
        ModelTalentPortfolioAssetService $service,
    ): RedirectResponse {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'media' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'source_description' => ['required', 'string', 'min:20', 'max:1000'],
            'consent_scope' => ['required', 'string', 'min:20', 'max:2000'],
            'rights_evidence_reference' => [
                'required', 'string', 'min:20', 'max:200',
                'regex:/^private\/model-rights\/[A-Za-z0-9][A-Za-z0-9._\/-]{10,180}$/',
                'not_regex:/\.\./',
            ],
            'consent_evidence_reference' => [
                'required', 'string', 'min:20', 'max:200',
                'regex:/^private\/model-consent\/[A-Za-z0-9][A-Za-z0-9._\/-]{10,180}$/',
                'not_regex:/\.\./',
            ],
            'confirm_model_consent' => ['required', 'accepted'],
            'confirm_image_rights' => ['required', 'accepted'],
        ]);

        $service->store($application, (int) $request->user()->id, $validated['media'], [
            'source_description' => trim($validated['source_description']),
            'consent_scope' => trim($validated['consent_scope']),
            'rights_evidence_reference' => $validated['rights_evidence_reference'],
            'consent_evidence_reference' => $validated['consent_evidence_reference'],
        ]);

        return back()->with('status', 'Model portfolio image received privately. It remains unavailable for public display or campaign use until separately licensed.');
    }

    public function review(
        Request $request,
        ModelTalentPortfolioAsset $asset,
        ModelTalentPortfolioAssetService $service,
    ): RedirectResponse {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_note' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $service->review($asset, (int) $request->user()->id, $validated['decision'], trim($validated['review_note']));

        return back()->with('status', 'Private model portfolio review decision recorded.');
    }

    public function withdrawConsent(
        Request $request,
        ModelTalentPortfolioAsset $asset,
        ModelTalentPortfolioAssetService $service,
    ): RedirectResponse {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'withdrawal_evidence_reference' => [
                'required', 'string', 'min:20', 'max:200',
                'regex:/^private\/model-consent\/[A-Za-z0-9][A-Za-z0-9._\/-]{10,180}$/',
                'not_regex:/\.\./',
            ],
            'confirm_withdrawal' => ['required', 'accepted'],
        ]);

        $service->withdrawConsent(
            $asset,
            (int) $request->user()->id,
            trim($validated['reason']),
            $validated['withdrawal_evidence_reference'],
        );

        return back()->with('status', 'Consent withdrawal recorded and the private portfolio image removed.');
    }

    public function preview(ModelTalentPortfolioAsset $asset): Response
    {
        $this->assertSchemaReady();
        abort_unless(in_array($asset->status, ['pending_review', 'approved'], true), 404);
        abort_unless($asset->storage_path && Storage::disk('local')->exists($asset->storage_path), 404);

        return response(Storage::disk('local')->get($asset->storage_path), 200, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline; filename="private-model-portfolio.webp"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_talent_image_licences')
                && Schema::hasTable('model_talent_portfolio_assets')
                && Schema::hasColumns('model_talent_portfolio_assets', [
                    'application_id', 'storage_path', 'sha256', 'status',
                    'consent_policy_version', 'consent_withdrawn_at',
                ]),
            503,
            'Private model portfolio services are temporarily unavailable.',
        );
    }
}
