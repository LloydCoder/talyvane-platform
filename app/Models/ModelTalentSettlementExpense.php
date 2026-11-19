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

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ModelTalentAssignment::class, 'assignment_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ModelTalentSettlement::class, 'settlement_id');
    }
}
