<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SafeguardingCaseEvent extends Model
{
    protected $fillable = [
        'case_id',
        'actor_id',
        'event_type',
        'from_status',
        'to_status',
        'note',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'note' => 'encrypted',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Safeguarding case events are append-only.'));
        static::deleting(fn () => throw new LogicException('Safeguarding case events are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
