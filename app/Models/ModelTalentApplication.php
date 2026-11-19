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
        'adult_eligibility_policy_version',
        'adult_eligibility_policy_accepted_at',
        'adult_eligibility_verified_at',
        'adult_eligibility_verified_by',
        'adult_eligibility_verification_method',
        'privacy_notice_version',
        'privacy_notice_accepted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'legal_hold_at',
        'legal_hold_by',
        'legal_hold_reason',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'full_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'city' => 'encrypted',
            'portfolio_url' => 'encrypted',
            'experience_summary' => 'encrypted',
            'review_note' => 'encrypted',
            'legal_hold_reason' => 'encrypted',
            'adult_eligibility_confirmed_at' => 'immutable_datetime',
            'adult_eligibility_policy_accepted_at' => 'immutable_datetime',
            'adult_eligibility_verified_at' => 'immutable_datetime',
            'privacy_notice_accepted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'legal_hold_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function adultEligibilityVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adult_eligibility_verified_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ModelTalentApplicationEvent::class, 'application_id');
    }

    public function representationAgreements(): HasMany
    {
        return $this->hasMany(ModelTalentRepresentationAgreement::class, 'application_id')->latest('version');
    }
}
