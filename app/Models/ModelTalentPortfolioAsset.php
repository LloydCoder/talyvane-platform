<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelTalentPortfolioAsset extends Model
{
    protected $fillable = [
        'application_id',
        'uploaded_by',
        'storage_path',
        'mime_type',
        'width',
        'height',
        'file_size',
        'sha256',
        'status',
        'source_description',
        'consent_scope',
        'rights_evidence_reference',
        'consent_evidence_reference',
        'consent_policy_version',
        'consent_recorded_at',
        'consent_withdrawn_at',
        'withdrawal_evidence_reference',
        'withdrawal_reason',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'source_description' => 'encrypted',
            'consent_scope' => 'encrypted',
            'rights_evidence_reference' => 'encrypted',
            'consent_evidence_reference' => 'encrypted',
            'withdrawal_evidence_reference' => 'encrypted',
            'withdrawal_reason' => 'encrypted',
            'review_note' => 'encrypted',
            'consent_recorded_at' => 'immutable_datetime',
            'consent_withdrawn_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ModelTalentApplication::class, 'application_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function imageLicences(): HasMany
    {
        return $this->hasMany(ModelTalentImageLicence::class, 'portfolio_asset_id')->latest();
    }
}
