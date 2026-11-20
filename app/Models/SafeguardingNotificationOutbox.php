<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeguardingNotificationOutbox extends Model
{
    protected $fillable = [
        'case_id',
        'recipient_user_id',
        'recipient_email',
        'recipient_key',
        'notification_type',
        'status',
        'attempts',
        'next_attempt_at',
        'processing_at',
        'delivered_at',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'recipient_email' => 'encrypted',
            'next_attempt_at' => 'immutable_datetime',
            'processing_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function safeguardingCase(): BelongsTo
    {
        return $this->belongsTo(SafeguardingCase::class, 'case_id');
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
