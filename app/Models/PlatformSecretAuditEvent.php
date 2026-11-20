<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlatformSecretAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'secret_key',
        'actor_id',
        'actor_email',
        'action',
        'vault_version',
        'reason',
        'correlation_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'vault_version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Secret audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Secret audit events are append-only.'));
    }
}
