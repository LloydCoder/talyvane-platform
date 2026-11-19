<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelTalentRepresentationAgreement extends Model
{
    protected $fillable = [
        'application_id',
        'reference',
        'version',
        'status',
        'terms_snapshot',
        'terms_policy_version',
        'created_by',
        'offered_by',
        'offered_at',
        'executed_by',
        'executed_at',
        'executed_document_reference',
        'effective_from',
        'effective_until',
        'terminated_at',
        'terminated_by',
        'termination_reason',
    ];

    protected function casts(): array
    {
        return [
            'terms_snapshot' => 'encrypted:array',
            'executed_document_reference' => 'encrypted',
            'termination_reason' => 'encrypted',
            'offered_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
            'terminated_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ModelTalentApplication::class, 'application_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function offerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
