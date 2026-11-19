<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfilePublicationReview extends Model
{
    protected $fillable = [
        'companion_profile_id',
        'reviewer_id',
        'verification_provider',
        'verification_reference_hash',
        'consent_version',
        'automatic_booking_opt_in',
        'decision',
        'review_note',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'automatic_booking_opt_in' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CompanionProfile::class, 'companion_profile_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
