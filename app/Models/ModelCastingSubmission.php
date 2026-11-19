<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelCastingSubmission extends Model
{
    protected $fillable = [
        'call_id', 'application_id', 'portfolio_asset_id', 'image_licence_id',
        'reference', 'status', 'submitted_by', 'submitted_at', 'decision_by',
        'decision_at', 'decision_note', 'invalidated_at', 'invalidation_reason', 'withdrawal_evidence_reference',
    ];

    protected function casts(): array
    {
        return [
            'decision_note' => 'encrypted',
            'invalidation_reason' => 'encrypted',
            'withdrawal_evidence_reference' => 'encrypted',
            'submitted_at' => 'immutable_datetime',
            'decision_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(ModelCastingCall::class, 'call_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ModelTalentApplication::class, 'application_id');
    }

    public function portfolioAsset(): BelongsTo
    {
        return $this->belongsTo(ModelTalentPortfolioAsset::class, 'portfolio_asset_id');
    }

    public function imageLicence(): BelongsTo
    {
        return $this->belongsTo(ModelTalentImageLicence::class, 'image_licence_id');
    }

    public function clientReviews(): HasMany
    {
        return $this->hasMany(ModelCastingClientReview::class, 'submission_id');
    }

    public function assignment(): HasOne
    {
        return $this->hasOne(ModelTalentAssignment::class, 'source_submission_id');
    }
}
