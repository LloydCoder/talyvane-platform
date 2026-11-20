<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileMedia extends Model
{
    protected $table = 'profile_media';

    protected $fillable = [
        'companion_profile_id', 'uploaded_by', 'storage_path', 'mime_type',
        'width', 'height', 'file_size', 'status', 'access_level', 'members_only_consent_at', 'members_only_consent_version', 'members_only_consent_withdrawn_at',
        'members_only_consent_withdrawn_by', 'rights_confirmed_at',
        'consent_version', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'rights_confirmed_at' => 'immutable_datetime',
            'members_only_consent_at' => 'immutable_datetime',
            'members_only_consent_withdrawn_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CompanionProfile::class, 'companion_profile_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function membersOnlyConsentWithdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'members_only_consent_withdrawn_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
