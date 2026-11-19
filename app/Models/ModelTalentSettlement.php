<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class ModelTalentSettlement extends Model
{
    protected $fillable = [
        'reference', 'assignment_id', 'application_id', 'currency_code', 'currency_precision',
        'gross_amount', 'commission_percent', 'commission_amount', 'approved_expenses_amount',
        'model_net_amount', 'settlement_policy_version', 'calculation_snapshot', 'status',
        'created_by', 'approved_by', 'approved_at', 'payment_method', 'payment_reference',
        'payment_evidence_reference', 'paid_by', 'paid_at', 'reversed_by', 'reversed_at',
        'reversal_reason', 'reversal_evidence_reference', 'disputed_by', 'disputed_at', 'dispute_reason',
    ];

    protected function casts(): array
    {
        return [
            'currency_precision' => 'integer',
            'gross_amount' => 'decimal:4',
            'commission_percent' => 'decimal:2',
            'commission_amount' => 'decimal:4',
            'approved_expenses_amount' => 'decimal:4',
            'model_net_amount' => 'decimal:4',
            'calculation_snapshot' => 'encrypted:array',
            'payment_reference' => 'encrypted',
            'payment_evidence_reference' => 'encrypted',
            'reversal_reason' => 'encrypted',
            'reversal_evidence_reference' => 'encrypted',
            'dispute_reason' => 'encrypted',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'disputed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $settlement): void {
            $from = (string) $settlement->getOriginal('status');
            $to = (string) $settlement->status;
            $allowedTransitions = [
                'draft' => ['approved'],
                'approved' => ['paid', 'disputed'],
                'paid' => ['reversed', 'disputed'],
            ];
            $allowedFields = [
                'approved' => ['status', 'approved_by', 'approved_at', 'updated_at'],
                'paid' => ['status', 'payment_method', 'payment_reference', 'payment_evidence_reference', 'paid_by', 'paid_at', 'updated_at'],
                'reversed' => ['status', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_evidence_reference', 'updated_at'],
                'disputed' => ['status', 'disputed_by', 'disputed_at', 'dispute_reason', 'updated_at'],
            ];

            if (! in_array($to, $allowedTransitions[$from] ?? [], true)
                || array_diff(array_keys($settlement->getDirty()), $allowedFields[$to] ?? []) !== []) {
                throw new RuntimeException('Settlement financial snapshots are immutable; use a governed state transition.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Settlement records cannot be deleted.'));
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ModelTalentAssignment::class, 'assignment_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ModelTalentApplication::class, 'application_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ModelTalentSettlementExpense::class, 'settlement_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ModelTalentSettlementEvent::class, 'settlement_id')->orderBy('occurred_at');
    }
}
