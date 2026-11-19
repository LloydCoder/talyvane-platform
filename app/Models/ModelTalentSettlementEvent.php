<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ModelTalentSettlementEvent extends Model
{
    protected $fillable = [
        'assignment_id', 'settlement_id', 'expense_id', 'actor_id',
        'actor_type', 'event_type', 'reason', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => 'encrypted',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Model settlement audit events are append-only.'));
        static::deleting(fn () => throw new RuntimeException('Model settlement audit events are append-only.'));
    }
}
