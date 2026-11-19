<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelCastingClientReview extends Model
{
    protected $fillable = [
        'call_id', 'submission_id', 'access_id', 'client_user_id',
        'decision', 'note', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'note' => 'encrypted',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ModelCastingSubmission::class, 'submission_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function access(): BelongsTo
    {
        return $this->belongsTo(ModelCastingClientAccess::class, 'access_id');
    }
}
