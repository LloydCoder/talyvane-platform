<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ModelTalentApplicationEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'application_id',
        'actor_id',
        'actor_type',
        'event_type',
        'from_status',
        'to_status',
        'reason',
        'occurred_at',
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
        static::updating(fn () => throw new LogicException('Model application events are append-only.'));
        static::deleting(fn () => throw new LogicException('Model application events are append-only.'));
    }
}
