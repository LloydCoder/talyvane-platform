<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeguardingCaseContent extends Model
{
    protected $fillable = [
        'case_id',
        'key_ciphertext',
        'summary_ciphertext',
        'details_ciphertext',
        'subject_reference_ciphertext',
        'legal_hold_reason_ciphertext',
        'legal_hold_release_reason_ciphertext',
        'retention_erased_at',
        'retention_erased_by',
        'retention_policy_version',
    ];

    protected function casts(): array
    {
        return ['retention_erased_at' => 'immutable_datetime'];
    }

    public function safeguardingCase(): BelongsTo
    {
        return $this->belongsTo(SafeguardingCase::class, 'case_id');
    }
}
