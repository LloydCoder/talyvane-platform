<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelTalentApplicationRightsRequest;
use App\Services\ModelAgencySarResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModelAgencySarAdminController extends Controller
{
    public function index(): Response
    {
        $this->assertSchemaReady();

        return response()->view('admin.model-agency.sar.index', [
            'requests' => ModelTalentApplicationRightsRequest::query()
                ->where('request_type', 'access')
                ->whereIn('status', ['verified', 'completed'])
                ->whereNot('response_status', 'cancelled')
                ->with('application')
                ->orderByRaw('CASE WHEN response_due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('response_due_at')
                ->paginate(20),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function show(ModelTalentApplicationRightsRequest $rightsRequest, ModelAgencySarResponseService $responses): Response
    {
        $this->assertSchemaReady();
        $this->assertAccessRequest($rightsRequest);
        $rightsRequest->load(['application.events', 'application.rightsRequests']);

        $draft = $rightsRequest->response_status === 'prepared' && filled($rightsRequest->response_content)
            ? $rightsRequest->response_content
            : $responses->draft($rightsRequest);

        return response()->view('admin.model-agency.sar.show', [
            'rightsRequest' => $rightsRequest,
            'draftResponse' => $draft,
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function prepare(
        Request $request,
        ModelTalentApplicationRightsRequest $rightsRequest,
        ModelAgencySarResponseService $responses,
    ): RedirectResponse {
        $this->assertSchemaReady();
        $this->assertAccessRequest($rightsRequest);

        $validated = $request->validate([
            'response_content' => ['required', 'string', 'max:200000'],
            'platform_records_searched' => ['accepted'],
            'external_records_searched' => ['accepted'],
            'third_party_redactions_reviewed' => ['accepted'],
            'supplementary_information_reviewed' => ['accepted'],
        ]);

        $checklist = [
            'platform_records_searched' => true,
            'external_records_searched' => true,
            'third_party_redactions_reviewed' => true,
            'supplementary_information_reviewed' => true,
        ];

        $hash = $responses->prepare(
            $rightsRequest,
            (int) $request->user()->id,
            $validated['response_content'],
            $checklist,
        );

        return redirect()->route('admin.model-applicant-sar.show', $rightsRequest)
            ->with('status', 'The reviewed response draft was encrypted and saved. SHA-256: '.$hash);
    }

    public function download(
        ModelTalentApplicationRightsRequest $rightsRequest,
        ModelAgencySarResponseService $responses,
    ): Response {
        $this->assertSchemaReady();
        $this->assertAccessRequest($rightsRequest);

        $content = $responses->downloadContent($rightsRequest);
        $filename = 'Talyvane-SAR-'.preg_replace('/[^A-Za-z0-9-]/', '', $rightsRequest->application->reference).'.json';

        return response($content, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function fulfil(
        Request $request,
        ModelTalentApplicationRightsRequest $rightsRequest,
        ModelAgencySarResponseService $responses,
    ): RedirectResponse {
        $this->assertSchemaReady();
        $this->assertAccessRequest($rightsRequest);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['fulfilled', 'partially_fulfilled', 'refused'])],
            'delivery_method' => ['required', Rule::in(['secure_email', 'secure_portal', 'other'])],
            'delivery_reference' => ['required', 'string', 'min:8', 'max:255'],
            'decision_reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);

        $reason = trim($validated['decision_reason']);
        if (mb_strlen($reason) < 20) {
            throw ValidationException::withMessages(['decision_reason' => 'Provide at least 20 non-whitespace characters.']);
        }

        $responses->fulfil(
            $rightsRequest,
            (int) $request->user()->id,
            $validated['decision'],
            $validated['delivery_method'],
            $validated['delivery_reference'],
            $reason,
        );

        return redirect()->route('admin.model-applicant-sar.show', $rightsRequest)
            ->with('status', 'The subject-access response decision and delivery record were saved. The temporary response copy was purged.');
    }

    public function extend(
        Request $request,
        ModelTalentApplicationRightsRequest $rightsRequest,
        ModelAgencySarResponseService $responses,
    ): RedirectResponse {
        $this->assertSchemaReady();
        $this->assertAccessRequest($rightsRequest);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);

        $reason = trim($validated['reason']);
        if (mb_strlen($reason) < 20) {
            throw ValidationException::withMessages(['reason' => 'Provide at least 20 non-whitespace characters.']);
        }

        $responses->extend($rightsRequest, (int) $request->user()->id, $reason);

        return redirect()->route('admin.model-applicant-sar.show', $rightsRequest)
            ->with('status', 'The statutory response deadline was extended and the applicant was notified.');
    }

    private function assertAccessRequest(ModelTalentApplicationRightsRequest $rightsRequest): void
    {
        abort_unless(
            $rightsRequest->request_type === 'access'
                && in_array($rightsRequest->status, ['verified', 'completed'], true)
                && $rightsRequest->application?->status !== 'erased'
                && $rightsRequest->response_status !== 'cancelled',
            404,
        );
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_talent_application_rights_requests')
                && Schema::hasColumns('model_talent_application_rights_requests', [
                    'application_id',
                    'request_type',
                    'status',
                    'response_status',
                    'response_due_at',
                    'response_content',
                    'response_sha256',
                    'response_review_checklist',
                    'response_reviewed_by',
                    'response_reviewed_at',
                    'response_responded_at',
                    'response_delivery_method',
                    'response_delivery_reference_hash',
                    'response_decision_reason',
                    'response_delivered_by',
                ])
                && Schema::hasTable('model_talent_application_events'),
            503,
            'Subject-access request operations are temporarily unavailable.',
        );
    }
}
