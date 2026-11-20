<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientMembership extends Model
{
    protected $fillable = [
        'user_id', 'tier_name', 'benefits_summary', 'status', 'starts_at',
        'ends_at', 'assigned_by', 'internal_note',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $membership) => $membership
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>=', now()));
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(ClientMembershipEntitlement::class, 'membership_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
