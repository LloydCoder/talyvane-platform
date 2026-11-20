<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SafeguardingCase;
use App\Models\User;
use App\Services\SafeguardingCaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class SafeguardingCaseController extends Controller
{
    public function create(): Response
    {
        return response()->view('admin.safeguarding.reports.create', [
            'specialists' => User::query()
                ->where('role', 'safeguarding')
                ->where('is_active', true)
                ->whereNotNull('mfa_enabled_at')
                ->whereNotNull('mfa_secret')
                ->orderBy('name')
                ->get(['id', 'name']),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, SafeguardingCaseService $service): RedirectResponse
    {
        $validated = $request->validate([
            'case_type' => ['required', Rule::in([
                'consent_concern', 'coercion_or_exploitation', 'trafficking_concern',
                'minor_safety_concern', 'nonconsensual_content', 'threat_or_harassment',
                'privacy_or_data_risk', 'other',
            ])],
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'subject_type' => ['nullable', Rule::in(['model_application', 'companion_profile', 'booking', 'client', 'other'])],
            'subject_reference' => ['nullable', 'string', 'max:160'],
            'summary' => ['required', 'string', 'min:20', 'max:300'],
            'details' => ['required', 'string', 'min:30', 'max:5000'],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
        ]);

        $case = $service->create($request->user(), $validated);

        return redirect()->route('admin.dashboard')->with(
            'status',
            'Confidential safeguarding report submitted. Case contents are accessible only to the assigned safeguarding specialist.',
        );
    }

    public function index(Request $request, SafeguardingCaseService $service): Response
    {
        return response()->view('admin.safeguarding.cases.index', [
            'cases' => $service->assignedCases($request->user()),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function show(Request $request, SafeguardingCase $case, SafeguardingCaseService $service): Response
    {
        $assigned = $service->findAssigned($request->user(), $case);
        $service->recordView($assigned, $request->user());
        $assigned->load(['events.actor']);

        return response()->view('admin.safeguarding.cases.show', [
            'case' => $assigned,
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function update(Request $request, SafeguardingCase $case, SafeguardingCaseService $service): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['triaged', 'investigating', 'action_required', 'resolved', 'closed'])],
            'note' => ['required', 'string', 'min:20', 'max:1500'],
        ]);

        $service->transition($case, $request->user(), $validated['status'], trim($validated['note']));

        return redirect()->route('admin.safeguarding.cases.show', $case);
    }
}
