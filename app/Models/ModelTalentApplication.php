<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelTalentApplication extends Model
{
    protected $fillable = [
        'reference',
        'full_name',
        'email',
        'phone',
        'country_code',
        'city',
        'portfolio_url',
        'experience_summary',
        'status',
        'adult_eligibility_confirmed_at',
        'privacy_notice_version',
        'privacy_notice_accepted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'adult_eligibility_confirmed_at' => 'immutable_datetime',
            'privacy_notice_accepted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ModelTalentApplicationEvent::class, 'application_id');
    }
}
