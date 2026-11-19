<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanionProfile extends Model
{
    protected $fillable = [
        'destination_id',
        'display_name',
        'slug',
        'summary',
        'languages',
        'publication_status',
        'accepts_automatic_bookings',
        'automatic_booking_consent_at',
        'age_verified_at',
        'publication_consent_at',
        'consent_withdrawn_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'languages' => 'array',
            'age_verified_at' => 'immutable_datetime',
            'publication_consent_at' => 'immutable_datetime',
            'consent_withdrawn_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'automatic_booking_consent_at' => 'immutable_datetime',
            'accepts_automatic_bookings' => 'boolean',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function scopePubliclyDiscoverable(Builder $query): Builder
    {
        return $query
            ->where('publication_status', 'published')
            ->whereNotNull('age_verified_at')
            ->whereNotNull('publication_consent_at')
            ->whereNull('consent_withdrawn_at')
            ->whereHas('destination', fn (Builder $destination) => $destination->where('is_active', true));
    }
}
