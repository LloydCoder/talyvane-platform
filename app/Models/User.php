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
use Illuminate\Support\Facades\DB;
use LogicException;

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


    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->role !== 'super_admin') {
                return;
            }

            $control = DB::table('platform_control')->where('id', 1)->value('super_admin_user_id');
            $existingSuperAdmin = static::query()->where('role', 'super_admin')->exists();

            if ($control !== null || $existingSuperAdmin) {
                throw new LogicException('A deployment may have only one Super Admin. Use the controlled bootstrap or recovery command.');
            }
        });

        static::updating(function (User $user): void {
            $controlId = DB::table('platform_control')->where('id', 1)->value('super_admin_user_id');
            $isReferencedPrincipal = $controlId !== null && (int) $controlId === (int) $user->id;

            if ($user->getOriginal('role') === 'super_admin') {
                if ($controlId !== null && ! $isReferencedPrincipal) {
                    throw new LogicException('Super Admin principal and deployment control record are inconsistent.');
                }

                if ($user->isDirty('role') && $user->role !== 'super_admin') {
                    throw new LogicException('The deployment Super Admin cannot be demoted.');
                }

                if ($user->isDirty('is_active') && ! $user->is_active) {
                    throw new LogicException('The deployment Super Admin cannot be deactivated.');
                }
            }

            if ($user->isDirty('role') && $user->role === 'super_admin' && ! $isReferencedPrincipal) {
                throw new LogicException('Super Admin promotion is permitted only through the controlled bootstrap process.');
            }
        });

        static::deleting(function (User $user): void {
            $controlId = DB::table('platform_control')->where('id', 1)->value('super_admin_user_id');

            if ($user->role === 'super_admin' || ($controlId !== null && (int) $controlId === (int) $user->id)) {
                throw new LogicException('The deployment Super Admin cannot be deleted. Use the controlled recovery process.');
            }
        });
    }

    public function isPlatformAdministrator(): bool
    {
        return $this->is_active && in_array($this->role, ['super_admin', 'owner', 'admin'], true);
    }
}
