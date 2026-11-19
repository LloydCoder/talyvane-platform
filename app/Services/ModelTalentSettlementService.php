<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ModelTalentAssignment;
use App\Models\ModelTalentSettlement;
use App\Models\ModelTalentSettlementEvent;
use App\Models\ModelTalentSettlementExpense;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ModelTalentSettlementService
{
    public function addExpense(ModelTalentAssignment $assignment, int $actorId, array $input): ModelTalentSettlementExpense
    {
        $this->assertEvidenceReference((string) ($input['evidence_reference'] ?? ''), 'private/model-expenses/');

        return DB::transaction(function () use ($assignment, $actorId, $input): ModelTalentSettlementExpense {
            $lockedAssignment = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if (! filled($input['description'] ?? null) || mb_strlen(trim((string) ($input['description'] ?? ''))) < 10 || mb_strlen(trim((string) ($input['description'] ?? ''))) > 1000) {
                throw ValidationException::withMessages(['expense' => 'Provide a clear expense description between 10 and 1,000 characters.']);
            }

            if (! in_array($lockedAssignment->status, ['in_progress', 'completed'], true)
                || ModelTalentSettlement::query()->where('assignment_id', $lockedAssignment->id)->exists()) {
                throw ValidationException::withMessages(['expense' => 'Expenses can only be submitted before settlement is created and while the assignment is in progress or completed.']);
            }

            if ($lockedAssignment->expense_cap_amount === null
                || $lockedAssignment->currency_code === null
                || ($input['currency_code'] ?? null) !== $lockedAssignment->currency_code) {
                throw ValidationException::withMessages(['expense' => 'The assignment has no approved expense cap or the expense currency does not match.']);
            }

            $currency = Currency::query()->where('code', $lockedAssignment->currency_code)->where('is_enabled', true)->lockForUpdate()->first();
            if (! $currency) {
                throw ValidationException::withMessages(['expense' => 'The assignment currency must remain enabled for settlement.']);
            }

            $amount = $this->money((string) ($input['amount'] ?? ''), $currency, 'expense');
            $cap = $this->money((string) $lockedAssignment->expense_cap_amount, $currency);
            if ($amount->minorUnits() <= 0) {
                throw ValidationException::withMessages(['expense' => 'Expense amount must be greater than zero.']);
            }

            $existing = ModelTalentSettlementExpense::query()
                ->where('assignment_id', $lockedAssignment->id)
                ->whereIn('status', ['pending', 'approved'])
                ->lockForUpdate()
                ->get();
            $committedMinor = 0;
            foreach ($existing as $claim) {
                $claimMoney = $this->money((string) $claim->amount, $currency);
                if ($claimMoney->minorUnits() <= 0) {
                    throw ValidationException::withMessages(['expense' => 'Existing expense claims contain an invalid non-positive amount; manual reconciliation is required.']);
                }
                if ($committedMinor > PHP_INT_MAX - $claimMoney->minorUnits()) {
                    throw ValidationException::withMessages(['expense' => 'The accumulated expense claims exceed the supported calculation range.']);
                }
                $committedMinor += $claimMoney->minorUnits();
            }

            if ($committedMinor > $cap->minorUnits() - $amount->minorUnits()) {
                throw ValidationException::withMessages(['expense' => 'Pending and approved expense claims cannot exceed the assignment expense cap.']);
            }

            $expense = ModelTalentSettlementExpense::query()->create([
                'assignment_id' => $lockedAssignment->id,
                'description' => trim((string) $input['description']),
                'amount' => $amount->toMajor(),
                'currency_code' => $currency->code,
                'evidence_reference' => $input['evidence_reference'],
                'status' => 'pending',
                'created_by' => $actorId,
            ]);

            $this->event($lockedAssignment, null, $expense->id, $actorId, 'expense.submitted', 'Submitted an expense claim for human review.');

            return $expense;
        });
    }

    public function reviewExpense(ModelTalentSettlementExpense $expense, int $actorId, string $decision, string $note): void
    {
        DB::transaction(function () use ($expense, $actorId, $decision, $note): void {
            $current = ModelTalentSettlementExpense::query()->findOrFail($expense->id);
            $assignment = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($current->assignment_id);
            $locked = ModelTalentSettlementExpense::query()->lockForUpdate()->findOrFail($expense->id);

            if (! in_array($decision, ['approved', 'rejected'], true)
                || $locked->status !== 'pending'
                || $locked->settlement_id !== null
                || ModelTalentSettlement::query()->where('assignment_id', $assignment->id)->exists()) {
                throw ValidationException::withMessages(['expense' => 'Only an unassigned pending expense can receive a review decision.']);
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $this->event($assignment, null, $locked->id, $actorId, 'expense.'.$decision, $note);
        });
    }

    public function createDraft(ModelTalentAssignment $assignment, int $actorId): ModelTalentSettlement
    {
        return DB::transaction(function () use ($assignment, $actorId): ModelTalentSettlement {
            $lockedAssignment = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($lockedAssignment->status !== 'completed'
                || $lockedAssignment->compensation_amount === null
                || $lockedAssignment->currency_code === null
                || ModelTalentSettlement::query()->where('assignment_id', $lockedAssignment->id)->exists()) {
                throw ValidationException::withMessages(['settlement' => 'Only a completed assignment with agreed compensation and no existing settlement can be settled.']);
            }

            $policyVersion = $this->approvedPolicyVersion();
            $currency = Currency::query()->where('code', $lockedAssignment->currency_code)->where('is_enabled', true)->lockForUpdate()->first();
            if (! $currency) {
                throw ValidationException::withMessages(['settlement' => 'The assignment currency must remain enabled for settlement.']);
            }

            $expenses = ModelTalentSettlementExpense::query()
                ->where('assignment_id', $lockedAssignment->id)
                ->lockForUpdate()
                ->get();

            if ($expenses->contains(fn (ModelTalentSettlementExpense $expense): bool => $expense->status === 'pending')) {
                throw ValidationException::withMessages(['settlement' => 'All expense claims must be approved or rejected before settlement calculation.']);
            }

            $calculation = $this->calculate($lockedAssignment, $currency, $expenses);
            $settlement = ModelTalentSettlement::query()->create([
                'reference' => 'MTS-'.now()->format('Y').'-'.Str::upper(Str::random(12)),
                'assignment_id' => $lockedAssignment->id,
                'application_id' => $lockedAssignment->application_id,
                'currency_code' => $currency->code,
                'currency_precision' => $currency->minor_unit,
                'gross_amount' => $calculation['gross']->toMajor(),
                'commission_percent' => $lockedAssignment->commission_percent,
                'commission_amount' => $calculation['commission']->toMajor(),
                'approved_expenses_amount' => $calculation['expenses']->toMajor(),
                'model_net_amount' => $calculation['net']->toMajor(),
                'settlement_policy_version' => $policyVersion,
                'calculation_snapshot' => $calculation['snapshot'],
                'status' => 'draft',
                'created_by' => $actorId,
            ]);

            ModelTalentSettlementExpense::query()
                ->where('assignment_id', $lockedAssignment->id)
                ->update(['settlement_id' => $settlement->id, 'updated_at' => now()]);

            $this->event($lockedAssignment, $settlement->id, null, $actorId, 'settlement.drafted', 'Created a decimal-safe settlement snapshot from the completed assignment and reviewed expense claims.');

            return $settlement;
        });
    }

    public function approve(ModelTalentSettlement $settlement, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($settlement, $actorId, $reason): void {
            $current = ModelTalentSettlement::query()->findOrFail($settlement->id);
            $assignment = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($current->assignment_id);
            $locked = ModelTalentSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if ($locked->status !== 'draft'
                || $assignment->status !== 'completed'
                || $locked->settlement_policy_version !== $this->approvedPolicyVersion()) {
                throw ValidationException::withMessages(['settlement' => 'Only a draft settlement under the current approved policy can be approved.']);
            }

            $currency = Currency::query()->where('code', $locked->currency_code)->where('is_enabled', true)->lockForUpdate()->first();
            if (! $currency || $currency->minor_unit !== $locked->currency_precision) {
                throw ValidationException::withMessages(['settlement' => 'The settlement currency or its minor-unit precision changed; manual reconciliation is required.']);
            }

            $expenses = ModelTalentSettlementExpense::query()
                ->where('settlement_id', $locked->id)
                ->lockForUpdate()
                ->get();
            if ($expenses->contains(fn (ModelTalentSettlementExpense $expense): bool => $expense->status === 'pending')) {
                throw ValidationException::withMessages(['settlement' => 'Settlement cannot be approved while an expense claim is pending.']);
            }

            $calculation = $this->calculate($assignment, $currency, $expenses);
            foreach ([
                'gross_amount' => $calculation['gross']->toMajor(),
                'commission_amount' => $calculation['commission']->toMajor(),
                'approved_expenses_amount' => $calculation['expenses']->toMajor(),
                'model_net_amount' => $calculation['net']->toMajor(),
            ] as $field => $expected) {
                if ($this->money((string) $locked->{$field}, $currency)->toMajor() !== $expected) {
                    throw ValidationException::withMessages(['settlement' => 'Settlement amounts no longer match the immutable calculation snapshot. Manual reconciliation is required.']);
                }
            }

            if ($this->normalize((string) $locked->commission_percent) !== $this->normalize((string) $assignment->commission_percent)
                || $locked->calculation_snapshot !== $calculation['snapshot']) {
                throw ValidationException::withMessages(['settlement' => 'The commission or calculation snapshot changed; manual reconciliation is required.']);
            }

            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $actorId,
                'approved_at' => now(),
            ])->save();

            $this->event($assignment, $locked->id, null, $actorId, 'settlement.approved', $reason);
        });
    }

    public function recordPayment(
        ModelTalentSettlement $settlement,
        int $actorId,
        string $method,
        string $externalReference,
        string $evidenceReference,
    ): void {
        $methods = ['bank_transfer', 'cash', 'other_external'];
        if (! in_array($method, $methods, true) || ! filled($externalReference) || mb_strlen($externalReference) > 200) {
            throw ValidationException::withMessages(['payment' => 'Provide an allowed external payment method and reference.']);
        }
        $this->assertEvidenceReference($evidenceReference, 'private/model-settlements/');

        DB::transaction(function () use ($settlement, $actorId, $method, $externalReference, $evidenceReference): void {
            $current = ModelTalentSettlement::query()->findOrFail($settlement->id);
            $assignment = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($current->assignment_id);
            $locked = ModelTalentSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['payment' => 'Only an approved settlement can have external payment evidence recorded.']);
            }

            $locked->forceFill([
                'status' => 'paid',
                'payment_method' => $method,
                'payment_reference' => trim($externalReference),
                'payment_evidence_reference' => $evidenceReference,
                'paid_by' => $actorId,
                'paid_at' => now(),
            ])->save();

            $this->event($assignment, $locked->id, null, $actorId, 'settlement.paid_recorded', 'Recorded an external payment attestation. The platform did not initiate or independently verify the transfer.');
        });
    }

    public function reverse(ModelTalentSettlement $settlement, int $actorId, string $reason, string $evidenceReference): void
    {
        $this->assertEvidenceReference($evidenceReference, 'private/model-settlements/');

        DB::transaction(function () use ($settlement, $actorId, $reason, $evidenceReference): void {
            $current = ModelTalentSettlement::query()->findOrFail($settlement->id);
            $assignment = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($current->assignment_id);
            $locked = ModelTalentSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if ($locked->status !== 'paid') {
                throw ValidationException::withMessages(['settlement' => 'Only a paid settlement can be reversed.']);
            }

            $locked->forceFill([
                'status' => 'reversed',
                'reversed_by' => $actorId,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'reversal_evidence_reference' => $evidenceReference,
            ])->save();

            $this->event($assignment, $locked->id, null, $actorId, 'settlement.reversed', $reason);
        });
    }

    public function dispute(ModelTalentSettlement $settlement, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($settlement, $actorId, $reason): void {
            $current = ModelTalentSettlement::query()->findOrFail($settlement->id);
            $assignment = ModelTalentAssignment::query()->lockForUpdate()->findOrFail($current->assignment_id);
            $locked = ModelTalentSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if (! in_array($locked->status, ['approved', 'paid'], true)) {
                throw ValidationException::withMessages(['settlement' => 'Only an approved or paid settlement can be disputed.']);
            }

            $locked->forceFill([
                'status' => 'disputed',
                'disputed_by' => $actorId,
                'disputed_at' => now(),
                'dispute_reason' => $reason,
            ])->save();

            $this->event($assignment, $locked->id, null, $actorId, 'settlement.disputed', $reason);
        });
    }

    private function calculate(ModelTalentAssignment $assignment, Currency $currency, $expenses): array
    {
        $gross = $this->money((string) $assignment->compensation_amount, $currency);
        $commissionRate = Money::fromMajor($this->normalize((string) $assignment->commission_percent), 'USD', 2);
        $rateBasisPoints = $commissionRate->minorUnits();

        if ($gross->minorUnits() <= 0 || $rateBasisPoints < 0 || $rateBasisPoints > 10000) {
            throw ValidationException::withMessages(['settlement' => 'The assignment must contain a positive gross fee and a valid 0–100% commission rate.']);
        }

        if ($rateBasisPoints > 0 && $gross->minorUnits() > intdiv(PHP_INT_MAX - 5000, $rateBasisPoints)) {
            throw ValidationException::withMessages(['settlement' => 'The gross amount exceeds the supported safe calculation range.']);
        }

        $commissionMinor = intdiv(($gross->minorUnits() * $rateBasisPoints) + 5000, 10000);
        $expenseMinor = 0;

        foreach ($expenses as $expense) {
            if ($expense->status !== 'approved') {
                continue;
            }
            if ($expense->currency_code !== $currency->code) {
                throw ValidationException::withMessages(['settlement' => 'Expense currency does not match the assignment currency.']);
            }
            $money = $this->money((string) $expense->amount, $currency);
            if ($money->minorUnits() <= 0) {
                throw ValidationException::withMessages(['settlement' => 'An approved expense contains an invalid non-positive amount.']);
            }
            if ($expenseMinor > PHP_INT_MAX - $money->minorUnits()) {
                throw ValidationException::withMessages(['settlement' => 'Approved expenses exceed the supported calculation range.']);
            }
            $expenseMinor += $money->minorUnits();
        }

        $cap = $assignment->expense_cap_amount === null ? 0 : $this->money((string) $assignment->expense_cap_amount, $currency)->minorUnits();
        if ($expenseMinor > $cap) {
            throw ValidationException::withMessages(['settlement' => 'Approved expenses exceed the assignment expense cap.']);
        }

        $netMinor = $gross->minorUnits() - $commissionMinor;
        if ($netMinor > PHP_INT_MAX - $expenseMinor) {
            throw ValidationException::withMessages(['settlement' => 'The model net amount exceeds the supported calculation range.']);
        }
        $netMinor += $expenseMinor;

        $commission = Money::fromMinor($commissionMinor, $currency->code, $currency->minor_unit);
        $approvedExpenses = Money::fromMinor($expenseMinor, $currency->code, $currency->minor_unit);
        $net = Money::fromMinor($netMinor, $currency->code, $currency->minor_unit);

        return [
            'gross' => $gross,
            'commission' => $commission,
            'expenses' => $approvedExpenses,
            'net' => $net,
            'snapshot' => [
                'currency_code' => $currency->code,
                'currency_precision' => $currency->minor_unit,
                'gross_minor_units' => $gross->minorUnits(),
                'commission_rate_basis_points' => $rateBasisPoints,
                'commission_minor_units' => $commissionMinor,
                'approved_expense_minor_units' => $expenseMinor,
                'model_net_minor_units' => $netMinor,
                'rounding' => 'half_up_to_currency_minor_unit',
            ],
        ];
    }

    private function money(string $amount, Currency $currency, string $errorKey = 'settlement'): Money
    {
        try {
            return Money::fromMajor($this->normalize($amount), $currency->code, $currency->minor_unit);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$errorKey => $exception->getMessage()]);
        }
    }

    private function normalize(string $amount): string
    {
        if (! str_contains($amount, '.')) {
            return $amount;
        }

        [$whole, $fraction] = explode('.', $amount, 2);
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    private function approvedPolicyVersion(): string
    {
        $version = (string) config('talyvane.model_agency.settlement_policy_version');
        if (! config('talyvane.model_agency.settlement_policy_approved') || $version === '') {
            throw ValidationException::withMessages(['settlement' => 'A current, versioned model settlement policy must be explicitly approved.']);
        }

        return $version;
    }

    private function assertEvidenceReference(string $reference, string $prefix): void
    {
        if (! str_starts_with($reference, $prefix)
            || str_contains($reference, '..')
            || ! preg_match('/^private\/[A-Za-z0-9-]+\/[A-Za-z0-9][A-Za-z0-9._\/-]{10,180}$/', $reference)) {
            throw ValidationException::withMessages(['evidence' => 'Settlement evidence must reference a private, access-controlled storage key.']);
        }
    }

    private function event(
        ModelTalentAssignment $assignment,
        ?int $settlementId,
        ?int $expenseId,
        ?int $actorId,
        string $type,
        string $reason,
    ): void {
        ModelTalentSettlementEvent::query()->create([
            'assignment_id' => $assignment->id,
            'settlement_id' => $settlementId,
            'expense_id' => $expenseId,
            'actor_id' => $actorId,
            'actor_type' => $actorId === null ? 'system' : 'administrator',
            'event_type' => $type,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }
}
