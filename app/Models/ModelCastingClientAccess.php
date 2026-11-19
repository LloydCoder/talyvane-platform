<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelCastingClientAccess extends Model
{
    protected $fillable = [
        'call_id', 'client_user_id', 'granted_by', 'granted_at', 'expires_at',
        'revoked_by', 'revoked_at', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'reason' => 'encrypted',
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(ModelCastingCall::class, 'call_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ModelCastingClientReview::class, 'access_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at?->isFuture() === true;
    }
}
