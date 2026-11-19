<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            $allowed = ['settlement_id', 'status', 'reviewed_by', 'reviewed_at', 'review_note', 'updated_at'];
            $dirty = array_keys($expense->getDirty());

            if (array_diff($dirty, $allowed) !== []) {
                throw new RuntimeException('Expense claim terms and evidence are immutable after submission.');
            }

            if ($expense->isDirty('status')
                && ((string) $expense->getOriginal('status') !== 'pending'
                    || ! in_array($expense->status, ['approved', 'rejected'], true))) {
                throw new RuntimeException('Expense review decisions are final once recorded.');
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
