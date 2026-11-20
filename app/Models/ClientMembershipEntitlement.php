<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ClientMembershipEntitlement extends Model
{
    protected $fillable = [
        'membership_id',
        'entitlement_key',
        'granted_by',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Membership entitlement grants are immutable.'));
        static::deleting(fn () => throw new LogicException('Membership entitlement grants are immutable.'));
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(ClientMembership::class, 'membership_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
