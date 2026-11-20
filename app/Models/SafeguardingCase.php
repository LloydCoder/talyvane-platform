<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SafeguardingCase extends Model
{
    protected $fillable = [
        'reference',
        'case_type',
        'severity',
        'status',
        'subject_type',
        'subject_reference',
        'summary',
        'details',
        'assigned_to',
        'created_by',
        'resolved_at',
        'closed_at',
        'legal_hold_at',
        'legal_hold_by',
        'legal_hold_reason',
    ];

    protected function casts(): array
    {
        return [
            'subject_reference' => 'encrypted',
            'summary' => 'encrypted',
            'details' => 'encrypted',
            'legal_hold_reason' => 'encrypted',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'legal_hold_at' => 'immutable_datetime',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SafeguardingCaseEvent::class, 'case_id')->orderBy('occurred_at');
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(SafeguardingCaseAccessEvent::class, 'case_id');
    }
}
