<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enquiry extends Model
{
    protected $fillable = [
        'destination_id',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'assigned_to',
        'responded_at',
        'closed_at',
        'privacy_acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'privacy_acknowledged_at' => 'immutable_datetime',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
