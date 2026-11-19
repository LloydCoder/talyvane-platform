<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelTalentApplicationRightsRequest extends Model
{
    protected $fillable = [
        'application_id',
        'request_type',
        'status',
        'code_hash',
        'attempts',
        'expires_at',
        'verified_at',
        'completed_at',
        'response_status',
        'response_due_at',
        'response_extended_at',
        'response_extension_reason',
        'response_reviewed_by',
        'response_reviewed_at',
        'response_review_checklist',
        'response_content',
        'response_sha256',
        'response_responded_at',
        'response_delivery_method',
        'response_delivery_reference_hash',
        'response_decision_reason',
        'response_delivered_by',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'response_due_at' => 'immutable_datetime',
            'response_extended_at' => 'immutable_datetime',
            'response_reviewed_at' => 'immutable_datetime',
            'response_review_checklist' => 'array',
            'response_content' => 'encrypted',
            'response_extension_reason' => 'encrypted',
            'response_decision_reason' => 'encrypted',
            'response_responded_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ModelTalentApplication::class, 'application_id');
    }
}
