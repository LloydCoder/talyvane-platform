<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
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

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->role === 'super_admin' && static::deploymentSuperAdminIsAssigned()) {
                throw new LogicException('Only the designated deployment Super Admin may hold this role.');
            }
        });

        static::updating(function (User $user): void {
            if ($user->isDirty('role') && $user->role === 'super_admin' && static::deploymentSuperAdminIsAssignedToAnotherUser($user)) {
                throw new LogicException('Only the designated deployment Super Admin may hold this role.');
            }

            if (! $user->isDesignatedDeploymentSuperAdmin()) {
                return;
            }

            if (($user->isDirty('role') && $user->role !== 'super_admin')
                || ($user->isDirty('is_active') && ! $user->is_active)) {
                throw new LogicException('The designated Super Admin cannot be demoted or deactivated outside the controlled recovery process.');
            }
        });

        static::deleting(function (User $user): void {
            if ($user->isDesignatedDeploymentSuperAdmin()) {
                throw new LogicException('The designated Super Admin cannot be deleted outside the controlled recovery process.');
            }
        });
    }

    private function isDesignatedDeploymentSuperAdmin(): bool
    {
        if (! $this->exists || ! $this->getKey() || ! Schema::hasTable('platform_control')) {
            return false;
        }

        return DB::table('platform_control')
            ->where('id', 1)
            ->where('super_admin_user_id', $this->getKey())
            ->exists();
    }

    private static function deploymentSuperAdminIsAssigned(): bool
    {
        if (! Schema::hasTable('platform_control')) {
            return false;
        }

        return DB::table('platform_control')
            ->where('id', 1)
            ->whereNotNull('super_admin_user_id')
            ->exists();
    }

    private static function deploymentSuperAdminIsAssignedToAnotherUser(User $user): bool
    {
        if (! Schema::hasTable('platform_control')) {
            return false;
        }

        return DB::table('platform_control')
            ->where('id', 1)
            ->whereNotNull('super_admin_user_id')
            ->where('super_admin_user_id', '!=', $user->getKey())
            ->exists();
    }

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
