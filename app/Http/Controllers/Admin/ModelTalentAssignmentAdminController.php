<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelCastingSubmission;
use App\Models\ModelTalentAssignment;
use App\Services\ModelTalentAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

class ModelTalentAssignmentAdminController extends Controller
{
    public function index(\App\Services\FeatureRegistry $features): Response
    {
        $this->assertSchemaReady();

        return response()->view('admin.model-agency.assignments.index', [
            'canCreateAssignments' => $features->isOperationallyEnabled('model-agency.assignments'),
            'assignments' => ModelTalentAssignment::query()
                ->with(['application', 'submission.call', 'representationAgreement'])
                ->latest()
                ->paginate(20),
            'selectedSubmissions' => ModelCastingSubmission::query()
                ->where('status', 'selected')
                ->with(['application', 'call'])
                ->whereDoesntHave('assignment')
                ->latest('submitted_at')
                ->limit(100)
                ->get(),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, ModelTalentAssignmentService $assignments): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'submission_id' => ['required', 'integer', 'exists:model_casting_submissions,id'],
            'project_purpose' => ['required', 'in:editorial,commercial_campaign,e_commerce,social_media,advertising,other'],
            'client_name' => ['required', 'string', 'min:2', 'max:160'],
            'client_reference' => ['nullable', 'string', 'max:200'],
            'brief' => ['required', 'string', 'min:20', 'max:3000'],
            'location_city' => ['nullable', 'string', 'max:100'],
            'shoot_starts_at' => ['required', 'date'],
            'shoot_ends_at' => ['required', 'date', 'after_or_equal:shoot_starts_at'],
            'deliverables' => ['required', 'string', 'min:20', 'max:3000'],
            'compensation_amount' => ['nullable', 'numeric', 'min:0.0001', 'max:999999999999999.9999'],
            'expense_cap_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.9999'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
        ]);

        $assignments->createDraft(
            ModelCastingSubmission::query()->findOrFail($validated['submission_id']),
            (int) $request->user()->id,
            $validated,
        );

        return back()->with('status', 'Private model assignment draft created. No contract, booking, payment or settlement was issued.');
    }

    public function offer(Request $request, ModelTalentAssignment $assignment, ModelTalentAssignmentService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $service->offer($assignment, (int) $request->user()->id);

        return back()->with('status', 'Assignment offer recorded for external delivery and model review.');
    }

    public function accept(Request $request, ModelTalentAssignment $assignment, ModelTalentAssignmentService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'executed_document_reference' => ['required', 'string', 'min:20', 'max:200', 'regex:/^private\\/model-assignments\\/[A-Za-z0-9][A-Za-z0-9._\\/-]{10,180}$/', 'not_regex:/\\.\\./'],
            'model_acceptance_evidence_reference' => ['required', 'string', 'min:20', 'max:200', 'regex:/^private\\/model-consent\\/[A-Za-z0-9][A-Za-z0-9._\\/-]{10,180}$/', 'not_regex:/\\.\\./'],
        ]);
        $service->recordAcceptance(
            $assignment,
            (int) $request->user()->id,
            $validated['executed_document_reference'],
            $validated['model_acceptance_evidence_reference'],
        );

        return back()->with('status', 'External assignment execution and model acceptance evidence recorded.');
    }

    public function confirm(Request $request, ModelTalentAssignment $assignment, ModelTalentAssignmentService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $service->confirm($assignment, (int) $request->user()->id);

        return back()->with('status', 'Model assignment confirmed.');
    }

    public function start(Request $request, ModelTalentAssignment $assignment, ModelTalentAssignmentService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $service->start($assignment, (int) $request->user()->id);

        return back()->with('status', 'Model assignment marked in progress.');
    }

    public function complete(Request $request, ModelTalentAssignment $assignment, ModelTalentAssignmentService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate(['completion_note' => ['required', 'string', 'min:20', 'max:1500']]);
        $service->complete($assignment, (int) $request->user()->id, trim($validated['completion_note']));

        return back()->with('status', 'Model assignment completion recorded. Settlement remains a separate workflow.');
    }

    public function decline(Request $request, ModelTalentAssignment $assignment, ModelTalentAssignmentService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
            'evidence_reference' => ['required', 'string', 'min:20', 'max:200', 'regex:/^private\\/model-consent\\/[A-Za-z0-9][A-Za-z0-9._\\/-]{10,180}$/', 'not_regex:/\\.\\./'],
        ]);
        $service->decline($assignment, (int) $request->user()->id, trim($validated['reason']), $validated['evidence_reference']);

        return back()->with('status', 'Model assignment decline recorded.');
    }

    public function cancel(Request $request, ModelTalentAssignment $assignment, ModelTalentAssignmentService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:1000']]);
        $service->cancel($assignment, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Model assignment cancellation recorded.');
    }

    public function dispute(Request $request, ModelTalentAssignment $assignment, ModelTalentAssignmentService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:1500']]);
        $service->dispute($assignment, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Assignment dispute recorded for human review.');
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_talent_assignments')
                && Schema::hasColumns('model_talent_assignments', [
                    'reference', 'source_submission_id', 'application_id', 'representation_agreement_id',
                    'status', 'shoot_starts_at', 'shoot_ends_at', 'assignment_policy_version',
                ])
                && Schema::hasTable('model_talent_assignment_events'),
            503,
            'Model assignment services are temporarily unavailable.',
        );
    }
}
