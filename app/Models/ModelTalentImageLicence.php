<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelTalentImageLicence extends Model
{
    protected $fillable = [
        'application_id',
        'portfolio_asset_id',
        'representation_agreement_id',
        'reference',
        'status',
        'licensee_name',
        'licensee_reference',
        'purpose',
        'channels',
        'purpose_details',
        'country_code',
        'starts_on',
        'ends_on',
        'exclusivity',
        'compensation_amount',
        'currency_code',
        'licence_policy_version',
        'created_by',
        'activated_by',
        'activated_at',
        'execution_evidence_reference',
        'model_consent_evidence_reference',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'licensee_name' => 'encrypted',
            'licensee_reference' => 'encrypted',
            'channels' => 'array',
            'purpose_details' => 'encrypted',
            'compensation_amount' => 'decimal:4',
            'exclusivity' => 'boolean',
            'execution_evidence_reference' => 'encrypted',
            'model_consent_evidence_reference' => 'encrypted',
            'revocation_reason' => 'encrypted',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'activated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ModelTalentApplication::class, 'application_id');
    }

    public function portfolioAsset(): BelongsTo
    {
        return $this->belongsTo(ModelTalentPortfolioAsset::class, 'portfolio_asset_id');
    }

    public function representationAgreement(): BelongsTo
    {
        return $this->belongsTo(ModelTalentRepresentationAgreement::class, 'representation_agreement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
