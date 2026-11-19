<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanionAvailabilityRule extends Model
{
    protected $fillable = [
        'companion_profile_id',
        'weekday',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CompanionProfile::class, 'companion_profile_id');
    }
}
