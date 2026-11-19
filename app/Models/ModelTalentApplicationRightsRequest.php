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
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ModelTalentApplication::class, 'application_id');
    }
}
