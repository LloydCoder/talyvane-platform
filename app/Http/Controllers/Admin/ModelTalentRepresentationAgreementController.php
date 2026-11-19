<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentRepresentationAgreement;
use App\Services\ModelTalentRepresentationAgreementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ModelTalentRepresentationAgreementController extends Controller
{
    public function store(Request $request, ModelTalentApplication $application, ModelTalentRepresentationAgreementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'representation_scope' => ['required', 'string', 'min:20', 'max:3000'],
            'exclusivity' => ['required', 'in:exclusive,non_exclusive'],
            'commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'term_months' => ['required', 'integer', 'min:1', 'max:60'],
            'notice_days' => ['required', 'integer', 'min:0', 'max:180'],
            'image_usage_scope' => ['required', 'string', 'min:20', 'max:3000'],
            'compensation_terms' => ['required', 'string', 'min:20', 'max:3000'],
            'expense_terms' => ['required', 'string', 'min:20', 'max:3000'],
            'withdrawal_and_consent_terms' => ['required', 'string', 'min:20', 'max:3000'],
        ]);

        $service->createDraft($application, (int) $request->user()->id, [
            'country_code' => $validated['country_code'],
            'representation_scope' => trim($validated['representation_scope']),
            'exclusivity' => $validated['exclusivity'],
            'commission_percent' => number_format((float) $validated['commission_percent'], 2, '.', ''),
            'term_months' => (int) $validated['term_months'],
            'notice_days' => (int) $validated['notice_days'],
            'image_usage_scope' => trim($validated['image_usage_scope']),
            'compensation_terms' => trim($validated['compensation_terms']),
            'expense_terms' => trim($validated['expense_terms']),
            'withdrawal_and_consent_terms' => trim($validated['withdrawal_and_consent_terms']),
        ]);

        return back()->with('status', 'Representation agreement draft created. It is not a contract until separately executed by all required parties.');
    }

    public function offer(Request $request, ModelTalentRepresentationAgreement $agreement, ModelTalentRepresentationAgreementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $service->offer($agreement, (int) $request->user()->id);

        return back()->with('status', 'Representation agreement offer recorded.');
    }

    public function execute(Request $request, ModelTalentRepresentationAgreement $agreement, ModelTalentRepresentationAgreementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'executed_document_reference' => [
                'required', 'string', 'min:20', 'max:200',
                'regex:/^private\/model-agreements\/[A-Za-z0-9][A-Za-z0-9._\/-]{10,180}$/',
                'not_regex:/\.\./',
            ],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'confirm_externally_executed' => ['required', 'accepted'],
        ]);

        $service->recordExecution(
            $agreement,
            (int) $request->user()->id,
            $validated['executed_document_reference'],
            $validated['effective_from'],
            $validated['effective_until'] ?? null,
        );

        return back()->with('status', 'External execution evidence recorded. The platform has not performed or validated an electronic signature.');
    }

    public function terminate(Request $request, ModelTalentRepresentationAgreement $agreement, ModelTalentRepresentationAgreementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'confirm_termination' => ['required', 'accepted'],
        ]);

        if (mb_strlen(trim($validated['reason'])) < 20) {
            throw ValidationException::withMessages(['reason' => 'Provide a meaningful termination reason.']);
        }

        $service->terminate($agreement, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Representation agreement termination recorded.');
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_talent_representation_agreements')
                && Schema::hasColumns('model_talent_representation_agreements', ['application_id', 'reference', 'version', 'status', 'terms_snapshot', 'terms_policy_version', 'executed_document_reference']),
            503,
            'Representation agreement services are temporarily unavailable.',
        );
    }
}
