<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = [
        'reference',
        'client_id',
        'companion_profile_id',
        'destination_id',
        'booking_mode',
        'status',
        'deposit_status',
        'starts_at',
        'ends_at',
        'timezone',
        'duration_minutes',
        'client_note',
        'confirmed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CompanionProfile::class, 'companion_profile_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}
