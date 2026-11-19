<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SuperAdminRecoveryEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Super Admin recovery audit events are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Super Admin recovery audit events are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
