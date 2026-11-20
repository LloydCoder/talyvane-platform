<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ClientMembershipEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'membership_id',
        'client_id',
        'actor_id',
        'actor_email',
        'event_type',
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
        static::updating(fn () => throw new LogicException('Membership lifecycle events are append-only.'));
        static::deleting(fn () => throw new LogicException('Membership lifecycle events are append-only.'));
    }
}
