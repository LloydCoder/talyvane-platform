<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelCastingCall extends Model
{
    protected $fillable = [
        'reference', 'title', 'client_name', 'client_reference', 'project_purpose',
        'description', 'requirements', 'country_code', 'opens_at', 'closes_at',
        'shoot_starts_at', 'shoot_ends_at', 'compensation_amount', 'currency_code',
        'max_submissions', 'status', 'casting_policy_version', 'created_by',
        'published_by', 'published_at', 'closed_by', 'closed_at', 'cancelled_by',
        'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'client_name' => 'encrypted',
            'client_reference' => 'encrypted',
            'description' => 'encrypted',
            'requirements' => 'encrypted',
            'compensation_amount' => 'decimal:4',
            'opens_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
            'shoot_starts_at' => 'immutable_datetime',
            'shoot_ends_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'cancellation_reason' => 'encrypted',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ModelCastingSubmission::class, 'call_id')->latest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
