<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProfileMediaConsentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'profile_media_id',
        'companion_profile_id',
        'media_reference',
        'actor_id',
        'actor_email',
        'action',
        'consent_policy_version',
        'statement_sha256',
        'correlation_id',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Profile media consent events are immutable.'));
        static::deleting(fn () => throw new LogicException('Profile media consent events are immutable.'));
    }
}
