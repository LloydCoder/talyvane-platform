<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SafeguardingRestoreVerification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'backup_reference',
        'retention_cases_erased',
        'active_keys_verified',
        'orphan_keys_destroyed',
        'verified_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'retention_cases_erased' => 'integer',
            'active_keys_verified' => 'integer',
            'orphan_keys_destroyed' => 'integer',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Safeguarding restore verification records are append-only.'));
        static::deleting(fn () => throw new LogicException('Safeguarding restore verification records are append-only.'));
    }
}
