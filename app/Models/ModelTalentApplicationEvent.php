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

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Model application events are append-only.'));
        static::deleting(fn () => throw new LogicException('Model application events are append-only.'));
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
