<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SuperAdminRecoveryGuardian extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Break-glass guardian assignments are immutable; use the controlled CLI commands.'));
        static::deleting(fn () => throw new LogicException('Break-glass guardian assignments are immutable; use the controlled CLI commands.'));
    }

    protected $fillable = [
        'slot',
        'user_id',
        'enrolled_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'enrolled_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
