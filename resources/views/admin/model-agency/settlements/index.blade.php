@extends('admin.layout')

@section('title', 'Model settlements')
@section('section', 'MODEL SETTLEMENTS')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">MODEL AGENCY FINANCE</p>
            <h1>Expense and settlement ledger</h1>
            <p class="field-help">Decimal-safe calculation and approval register. Payment is recorded as an external operator attestation only; this platform does not initiate, custody or verify transfers.</p>
        </div>
    </div>

    @unless($canCreateSettlements)
        <section class="admin-panel"><h2>New financial claims paused</h2><p class="field-help">New expense claims and settlement drafts are disabled by the platform feature control. Existing claims, approvals, external payment attestations, reversals and disputes remain available for financial close-out.</p></section>
    @endunless

    <section class="admin-panel">
        <h2>Expense claims awaiting review</h2>
        @forelse($expenses->where('status', 'pending') as $expense)
            <article class="admin-record">
                <p class="eyebrow">ASSIGNMENT {{ $expense->assignment->reference }} · {{ $expense->currency_code }} {{ $expense->amount }}</p>
                <h3>{{ $expense->assignment->application->full_name }}</h3>
                <p>{{ $expense->description }}</p>
                <p class="field-help">Evidence: {{ $expense->evidence_reference }}</p>
                <div class="record-actions">
                    <form method="POST" action="{{ route('admin.model-settlements.expenses.review', $expense) }}" class="inline-form">
                        @csrf
                        <input type="hidden" name="decision" value="approved">
                        <input name="note" minlength="20" maxlength="1000" placeholder="Approval rationale" required>
                        <button class="button button-dark" type="submit">Approve expense</button>
                    </form>
                    <form method="POST" action="{{ route('admin.model-settlements.expenses.review', $expense) }}" class="inline-form">
                        @csrf
                        <input type="hidden" name="decision" value="rejected">
                        <input name="note" minlength="20" maxlength="1000" placeholder="Rejection rationale" required>
                        <button class="button button-dark" type="submit">Reject expense</button>
                    </form>
                </div>
            </article>
        @empty
            <p class="field-help">No unreviewed expense claims.</p>
        @endforelse
    </section>

    <section class="admin-panel">
        <h2>Active and completed assignments</h2>
        @forelse($assignments as $assignment)
            <article class="admin-record">
                <p class="eyebrow">{{ $assignment->reference }} · {{ strtoupper($assignment->status) }}</p>
                <h3>{{ $assignment->application->full_name }} — {{ $assignment->client_name }}</h3>
                <p>{{ $assignment->project_purpose }} · {{ $assignment->shoot_starts_at->format('d M Y') }}–{{ $assignment->shoot_ends_at->format('d M Y') }}</p>
                @if($assignment->compensation_amount !== null)
                    <p>Agreed gross: {{ $assignment->compensation_amount }} {{ $assignment->currency_code }} · Expense cap: {{ $assignment->expense_cap_amount ?? 'Not set' }} · Commission: {{ $assignment->commission_percent }}%</p>
                @endif

                @if($canSubmitExpenses && in_array($assignment->status, ['in_progress','completed'], true) && $assignment->settlement === null && $assignment->expense_cap_amount !== null)
                    <details class="admin-details">
                        <summary>Submit expense claim</summary>
                        <form method="POST" action="{{ route('admin.model-settlements.expenses.store', $assignment) }}" class="admin-form-grid">
                            @csrf
                            <label>Amount<input type="number" name="amount" min="0.0001" step="0.0001" required></label>
                            <label>Currency<input name="currency_code" maxlength="3" pattern="[A-Z]{3}" value="{{ $assignment->currency_code }}" required></label>
                            <label class="full-width">Description<textarea name="description" minlength="10" maxlength="1000" required></textarea></label>
                            <label class="full-width">Private evidence reference<input name="evidence_reference" minlength="20" maxlength="200" placeholder="private/model-expenses/receipt.pdf" required></label>
                            <div class="full-width"><button class="button button-dark" type="submit">Submit expense</button></div>
                        </form>
                    </details>
                @endif

                @if($canCreateSettlements && $assignment->status === 'completed' && $assignment->settlement === null)
                    <form method="POST" action="{{ route('admin.model-settlements.create', $assignment) }}">
                        @csrf
                        <button class="button button-dark" type="submit">Calculate settlement draft</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="field-help">No active or completed model assignments.</p>
        @endforelse
    </section>

    <section class="admin-panel">
        <h2>Settlement register</h2>
        @forelse($settlements as $settlement)
            <article class="admin-record">
                <p class="eyebrow">{{ $settlement->reference }} · {{ $settlement->status === 'paid' ? 'PAID — EXTERNAL ATTESTATION' : strtoupper($settlement->status) }}</p>
                <h3>{{ $settlement->assignment->application->full_name }} — {{ $settlement->assignment->client_name }}</h3>
                <div class="admin-form-grid">
                    <p>Gross: {{ $settlement->gross_amount }} {{ $settlement->currency_code }}</p>
                    <p>Commission ({{ $settlement->commission_percent }}%): {{ $settlement->commission_amount }}</p>
                    <p>Approved expenses: {{ $settlement->approved_expenses_amount }}</p>
                    <p>Model net: <strong>{{ $settlement->model_net_amount }} {{ $settlement->currency_code }}</strong></p>
                </div>
                <p class="field-help">Policy: {{ $settlement->settlement_policy_version }} · Currency precision: {{ $settlement->currency_precision }}</p>
                @if($settlement->status === 'draft')
                    <form method="POST" action="{{ route('admin.model-settlements.approve', $settlement) }}" class="inline-form">
                        @csrf<input name="reason" minlength="20" maxlength="1000" placeholder="Independent settlement approval rationale" required>
                        <button class="button button-dark" type="submit">Approve settlement</button>
                    </form>
                @elseif($settlement->status === 'approved')
                    <form method="POST" action="{{ route('admin.model-settlements.payment', $settlement) }}" class="admin-form-grid">
                        @csrf
                        <label>External method<select name="payment_method" required><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="other_external">Other external</option></select></label>
                        <label>External payment reference<input name="payment_reference" minlength="4" maxlength="200" required></label>
                        <label class="full-width">Private payment evidence reference<input name="payment_evidence_reference" minlength="20" maxlength="200" placeholder="private/model-settlements/payment-proof.pdf" required></label>
                        <div class="full-width"><button class="button button-dark" type="submit">Record external payment</button></div>
                    </form>
                @elseif($settlement->status === 'paid')
                    <form method="POST" action="{{ route('admin.model-settlements.reverse', $settlement) }}" class="admin-form-grid">
                        @csrf<label>Reversal reason<input name="reason" minlength="20" maxlength="1000" required></label>
                        <label>Private reversal evidence<input name="evidence_reference" minlength="20" maxlength="200" placeholder="private/model-settlements/reversal-proof.pdf" required></label>
                        <button class="button button-dark" type="submit">Record reversal</button>
                    </form>
                @endif
                @if(in_array($settlement->status, ['approved','paid'], true))
                    <form method="POST" action="{{ route('admin.model-settlements.dispute', $settlement) }}" class="inline-form">
                        @csrf<input name="reason" minlength="20" maxlength="1500" placeholder="Settlement dispute rationale" required>
                        <button class="button button-dark" type="submit">Dispute settlement</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="field-help">No settlements have been calculated.</p>
        @endforelse
        {{ $settlements->links() }}
    </section>

    <p class="field-help">All monetary calculations use integer minor units and the configured ISO currency precision; excess precision is rejected rather than rounded. Commission uses half-up rounding to the currency minor unit. External payment references are evidence only. No money is moved, no bank or wallet account is connected, and no on-chain or bank confirmation is performed by this platform.</p>
@endsection
