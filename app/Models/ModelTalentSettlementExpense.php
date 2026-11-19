<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ModelTalentSettlementExpense extends Model
{
    protected $fillable = [
        'assignment_id', 'settlement_id', 'description', 'amount', 'currency_code',
        'evidence_reference', 'status', 'created_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'description' => 'encrypted',
            'amount' => 'decimal:4',
            'evidence_reference' => 'encrypted',
            'review_note' => 'encrypted',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $expense): void {
            $from = (string) $expense->getOriginal('status');
            $to = (string) $expense->status;
            $dirty = array_keys($expense->getDirty());

            if ($from === 'pending' && in_array($to, ['approved', 'rejected'], true)) {
                $allowed = ['status', 'reviewed_by', 'reviewed_at', 'review_note', 'updated_at'];
            } elseif ($from === $to) {
                $allowed = ['settlement_id', 'updated_at'];
                if ($expense->isDirty('settlement_id') && $expense->getOriginal('settlement_id') !== null) {
                    throw new RuntimeException('An expense claim can only be attached to one settlement.');
                }
            } else {
                throw new RuntimeException('Expense review decisions are final once recorded.');
            }

            if (array_diff($dirty, $allowed) !== []) {
                throw new RuntimeException('Expense claim terms and evidence are immutable after submission.');
            }
        });
        static::deleting(fn () => throw new RuntimeException('Expense claims cannot be deleted.'));
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ModelTalentAssignment::class, 'assignment_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ModelTalentSettlement::class, 'settlement_id');
    }
}
