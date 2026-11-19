<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelTalentAssignment extends Model
{
    protected $fillable = [
        'reference', 'source_submission_id', 'application_id', 'representation_agreement_id',
        'country_code', 'project_purpose', 'client_name', 'client_reference', 'brief',
        'location_city', 'shoot_starts_at', 'shoot_ends_at', 'deliverables',
        'compensation_amount', 'expense_cap_amount', 'currency_code', 'commission_percent',
        'assignment_policy_version', 'status', 'created_by', 'offered_by', 'offered_at',
        'acceptance_recorded_by', 'accepted_at', 'executed_document_reference',
        'model_acceptance_evidence_reference', 'confirmed_by', 'confirmed_at',
        'started_by', 'started_at', 'completed_by', 'completed_at', 'cancelled_by',
        'cancelled_at', 'cancellation_reason', 'disputed_by', 'disputed_at', 'dispute_reason',
    ];

    protected function casts(): array
    {
        return [
            'client_name' => 'encrypted',
            'client_reference' => 'encrypted',
            'brief' => 'encrypted',
            'location_city' => 'encrypted',
            'deliverables' => 'encrypted',
            'executed_document_reference' => 'encrypted',
            'model_acceptance_evidence_reference' => 'encrypted',
            'cancellation_reason' => 'encrypted',
            'dispute_reason' => 'encrypted',
            'compensation_amount' => 'decimal:4',
            'expense_cap_amount' => 'decimal:4',
            'commission_percent' => 'decimal:2',
            'shoot_starts_at' => 'immutable_datetime',
            'shoot_ends_at' => 'immutable_datetime',
            'offered_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'disputed_at' => 'immutable_datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ModelCastingSubmission::class, 'source_submission_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ModelTalentApplication::class, 'application_id');
    }

    public function representationAgreement(): BelongsTo
    {
        return $this->belongsTo(ModelTalentRepresentationAgreement::class, 'representation_agreement_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ModelTalentAssignmentEvent::class, 'assignment_id')->orderBy('occurred_at');
    }
}
