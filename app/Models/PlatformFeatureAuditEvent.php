<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformFeatureAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'feature_key',
        'actor_id',
        'actor_email',
        'action',
        'before_state',
        'after_state',
        'reason',
        'correlation_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Feature audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Feature audit events are append-only.'));
    }
}
