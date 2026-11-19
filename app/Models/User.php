<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use MustVerifyEmailTrait;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_enabled_at' => 'immutable_datetime',
            'mfa_recovery_codes' => 'array',
            'mfa_last_used_step' => 'integer',
        ];
    }

    public function favoriteProfiles(): BelongsToMany
    {
        return $this->belongsToMany(CompanionProfile::class, 'client_favorites', 'client_id', 'companion_profile_id')->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ClientMembership::class);
    }

    public function latestMembership(): HasOne
    {
        return $this->hasOne(ClientMembership::class)->latestOfMany();
    }

    public function companionProfile(): HasOne
    {
        return $this->hasOne(CompanionProfile::class);
    }

    public function isPlatformAdministrator(): bool
    {
        return $this->is_active && in_array($this->role, ['super_admin', 'owner', 'admin'], true);
    }
}
