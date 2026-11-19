<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    protected $fillable = [
        'asset',
        'network',
        'address',
        'token_contract',
        'confirmations_required',
        'purpose_booking',
        'purpose_membership',
        'enabled',
        'verification_status',
        'verification_reference_hash',
        'verified_by',
        'address_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'purpose_booking' => 'boolean',
            'purpose_membership' => 'boolean',
            'enabled' => 'boolean',
            'address_reviewed_at' => 'immutable_datetime',
        ];
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
