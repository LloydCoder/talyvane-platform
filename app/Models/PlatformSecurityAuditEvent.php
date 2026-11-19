<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformSecurityAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'subject_user_id',
        'subject_email',
        'action',
        'reason',
        'details',
        'actor_type',
        'actor_identifier',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Platform security audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Platform security audit events are append-only.'));
    }
}
