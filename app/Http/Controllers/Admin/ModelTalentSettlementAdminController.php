<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelTalentAssignment;
use App\Models\ModelTalentSettlement;
use App\Models\ModelTalentSettlementExpense;
use App\Services\ModelTalentSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

class ModelTalentSettlementAdminController extends Controller
{
    public function index(): Response
    {
        $this->assertSchemaReady();

        return response()->view('admin.model-agency.settlements.index', [
            'assignments' => ModelTalentAssignment::query()
                ->whereIn('status', ['in_progress', 'completed'])
                ->with('application')
                ->latest('updated_at')
                ->limit(100)
                ->get(),
            'settlements' => ModelTalentSettlement::query()
                ->with(['assignment.application', 'expenses'])
                ->latest()
                ->paginate(20),
            'expenses' => ModelTalentSettlementExpense::query()
                ->whereNull('settlement_id')
                ->with('assignment.application')
                ->latest()
                ->limit(100)
                ->get(),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function addExpense(Request $request, ModelTalentAssignment $assignment, ModelTalentSettlementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'description' => ['required', 'string', 'min:10', 'max:1000'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999999.9999'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'evidence_reference' => ['required', 'string', 'min:20', 'max:200'],
        ]);
        $service->addExpense($assignment, (int) $request->user()->id, $validated);

        return back()->with('status', 'Expense claim recorded for independent agency review.');
    }

    public function reviewExpense(Request $request, ModelTalentSettlementExpense $expense, ModelTalentSettlementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['required', 'string', 'min:20', 'max:1000'],
        ]);
        $service->reviewExpense($expense, (int) $request->user()->id, $validated['decision'], trim($validated['note']));

        return back()->with('status', 'Expense review decision recorded.');
    }

    public function create(Request $request, ModelTalentAssignment $assignment, ModelTalentSettlementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $service->createDraft($assignment, (int) $request->user()->id);

        return back()->with('status', 'Settlement draft calculated and snapshotted. No funds have been transferred.');
    }

    public function approve(Request $request, ModelTalentSettlement $settlement, ModelTalentSettlementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:1000']]);
        $service->approve($settlement, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Settlement approved for external payment.');
    }

    public function pay(Request $request, ModelTalentSettlement $settlement, ModelTalentSettlementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'payment_method' => ['required', 'in:bank_transfer,cash,crypto_external,other_external'],
            'payment_reference' => ['required', 'string', 'min:4', 'max:200'],
            'payment_evidence_reference' => ['required', 'string', 'min:20', 'max:200'],
        ]);
        $service->recordPayment(
            $settlement,
            (int) $request->user()->id,
            $validated['payment_method'],
            $validated['payment_reference'],
            $validated['payment_evidence_reference'],
        );

        return back()->with('status', 'External payment attestation recorded. The platform did not initiate or verify the transfer.');
    }

    public function reverse(Request $request, ModelTalentSettlement $settlement, ModelTalentSettlementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
            'evidence_reference' => ['required', 'string', 'min:20', 'max:200'],
        ]);
        $service->reverse($settlement, (int) $request->user()->id, trim($validated['reason']), $validated['evidence_reference']);

        return back()->with('status', 'Settlement reversal evidence recorded.');
    }

    public function dispute(Request $request, ModelTalentSettlement $settlement, ModelTalentSettlementService $service): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:1500']]);
        $service->dispute($settlement, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Settlement dispute recorded for human reconciliation.');
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_talent_settlements')
                && Schema::hasColumns('model_talent_settlements', [
                    'assignment_id', 'gross_amount', 'commission_amount',
                    'approved_expenses_amount', 'model_net_amount', 'status',
                    'settlement_policy_version', 'calculation_snapshot',
                ])
                && Schema::hasTable('model_talent_settlement_expenses')
                && Schema::hasTable('model_talent_settlement_events'),
            503,
            'Model settlement services are temporarily unavailable.',
        );
    }
}
