<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CountryMarket;
use App\Models\ModelCastingCall;
use App\Models\ModelCastingSubmission;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentImageLicence;
use App\Models\ModelTalentPortfolioAsset;
use App\Services\ModelCastingWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

class ModelCastingAdminController extends Controller
{
    public function index(): Response
    {
        $this->assertSchemaReady();

        return response()->view('admin.model-agency.castings.index', [
            'calls' => ModelCastingCall::query()->with([
                'submissions.application',
                'submissions.portfolioAsset',
                'submissions.imageLicence',
            ])->latest()->paginate(20),
            'approvedMarkets' => CountryMarket::operationallyApproved()->orderBy('name')->get(['country_code', 'name']),
            'eligibleApplications' => ModelTalentApplication::query()
                ->where('status', 'accepted')
                ->whereNotNull('adult_eligibility_verified_at')
                ->whereNull('legal_hold_at')
                ->with(['portfolioAssets.imageLicences'])
                ->orderByDesc('submitted_at')
                ->limit(100)
                ->get(),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, ModelCastingWorkflowService $workflow): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'client_name' => ['required', 'string', 'min:2', 'max:160'],
            'client_reference' => ['nullable', 'string', 'max:200'],
            'project_purpose' => ['required', 'in:editorial,commercial_campaign,e_commerce,social_media,advertising,other'],
            'description' => ['required', 'string', 'min:20', 'max:3000'],
            'requirements' => ['required', 'string', 'min:20', 'max:3000'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
            'shoot_starts_at' => ['required', 'date', 'after:closes_at'],
            'shoot_ends_at' => ['required', 'date', 'after_or_equal:shoot_starts_at'],
            'compensation_amount' => ['nullable', 'numeric', 'min:0.0001', 'max:999999999999999.9999'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'max_submissions' => ['required', 'integer', 'min:1', 'max:500'],
        ]);

        $workflow->createDraft((int) $request->user()->id, $validated);

        return back()->with('status', 'Private casting call draft created. It is not publicly listed or distributed.');
    }

    public function publish(Request $request, ModelCastingCall $call, ModelCastingWorkflowService $workflow): RedirectResponse
    {
        $this->assertSchemaReady();
        $workflow->publish($call, (int) $request->user()->id);

        return back()->with('status', 'Private casting call opened.');
    }

    public function close(Request $request, ModelCastingCall $call, ModelCastingWorkflowService $workflow): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:1000']]);
        $workflow->close($call, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Casting call closed to new submissions.');
    }

    public function cancel(Request $request, ModelCastingCall $call, ModelCastingWorkflowService $workflow): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:1000']]);
        $workflow->cancel($call, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Casting call cancelled and live submissions invalidated.');
    }

    public function submit(Request $request, ModelCastingCall $call, ModelCastingWorkflowService $workflow): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'application_id' => ['required', 'integer', 'exists:model_talent_applications,id'],
            'portfolio_asset_id' => ['required', 'integer', 'exists:model_talent_portfolio_assets,id'],
            'image_licence_id' => ['required', 'integer', 'exists:model_talent_image_licences,id'],
        ]);

        $workflow->submit(
            $call,
            ModelTalentApplication::query()->findOrFail($validated['application_id']),
            ModelTalentPortfolioAsset::query()->findOrFail($validated['portfolio_asset_id']),
            ModelTalentImageLicence::query()->findOrFail($validated['image_licence_id']),
            (int) $request->user()->id,
        );

        return back()->with('status', 'Eligible model submitted to the private casting call.');
    }

    public function decide(Request $request, ModelCastingSubmission $submission, ModelCastingWorkflowService $workflow): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'decision' => ['required', 'in:shortlisted,selected,declined'],
            'note' => ['required', 'string', 'min:20', 'max:1500'],
        ]);
        $workflow->decide($submission, (int) $request->user()->id, $validated['decision'], trim($validated['note']));

        return back()->with('status', 'Manual casting decision recorded. Selection does not create an assignment or booking.');
    }

    public function withdraw(Request $request, ModelCastingSubmission $submission, ModelCastingWorkflowService $workflow): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:1500']]);
        $workflow->withdraw($submission, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Casting submission withdrawal recorded.');
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_casting_events')
                && Schema::hasColumns('model_casting_calls', ['reference', 'status', 'country_code', 'opens_at', 'closes_at', 'shoot_starts_at', 'shoot_ends_at', 'casting_policy_version'])
                && Schema::hasTable('model_casting_calls')
                && Schema::hasTable('model_casting_submissions')
                && Schema::hasTable('model_casting_events'),
            503,
            'Private model casting services are temporarily unavailable.',
        );
    }
}
