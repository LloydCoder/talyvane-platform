<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SafeguardingCase extends Model
{
    private ?array $decryptedContentCache = null;
    protected $fillable = [
        'reference',
        'case_type',
        'severity',
        'status',
        'subject_type',
        'assigned_to',
        'created_by',
        'resolved_at',
        'closed_at',
        'legal_hold_at',
        'legal_hold_by',
        'legal_hold_released_at',
        'legal_hold_released_by',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'legal_hold_at' => 'immutable_datetime',
            'legal_hold_released_at' => 'immutable_datetime',
        ];
    }

    public function content(): HasOne
    {
        return $this->hasOne(SafeguardingCaseContent::class, 'case_id');
    }

    public function getSummaryAttribute(): ?string
    {
        return $this->decryptedContent()['summary'];
    }

    public function getDetailsAttribute(): ?string
    {
        return $this->decryptedContent()['details'];
    }

    public function getSubjectReferenceAttribute(): ?string
    {
        return $this->decryptedContent()['subject_reference'];
    }

    public function getLegalHoldReasonAttribute(): ?string
    {
        return $this->decryptedContent()['legal_hold_reason'];
    }

    public function getLegalHoldReleaseReasonAttribute(): ?string
    {
        return $this->decryptedContent()['legal_hold_release_reason'];
    }

    public function getContentErasedAttribute(): bool
    {
        return $this->decryptedContent()['content_erased'];
    }

    private function decryptedContent(): array
    {
        if ($this->decryptedContentCache === null) {
            $this->decryptedContentCache = app(SafeguardingCaseContentService::class)->read($this);
        }

        return $this->decryptedContentCache;
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
