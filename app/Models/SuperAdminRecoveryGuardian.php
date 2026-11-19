<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuperAdminRecoveryGuardian extends Model
{
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
