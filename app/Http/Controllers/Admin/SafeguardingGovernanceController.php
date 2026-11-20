<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SafeguardingCase;
use App\Services\SafeguardingCaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SafeguardingGovernanceController extends Controller
{
    public function index(): Response
    {
        return response()->view('admin.safeguarding.governance.index', [
            'cases' => SafeguardingCase::query()
                ->latest('created_at')
                ->paginate(30, [
                    'id',
                    'reference',
                    'case_type',
                    'severity',
                    'status',
                    'assigned_to',
                    'created_at',
                    'legal_hold_at',
                    'legal_hold_by',
                    'legal_hold_released_at',
                    'legal_hold_released_by',
                ]),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function placeLegalHold(Request $request, SafeguardingCase $case, SafeguardingCaseService $service): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);
        $service->placeLegalHold($case, $request->user(), trim($validated['reason']));

        return redirect()->route('admin.safeguarding.governance.index')
            ->with('status', 'Legal hold placed. Case contents remain restricted to the assigned safeguarding specialist.');
    }

    public function releaseLegalHold(Request $request, SafeguardingCase $case, SafeguardingCaseService $service): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);
        $service->releaseLegalHold($case, $request->user(), trim($validated['reason']));

        return redirect()->route('admin.safeguarding.governance.index')
            ->with('status', 'Legal hold released after independent privileged review.');
    }
}
