<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    protected static function booted(): void
    {
        $registerPendingMarket = function (Destination $destination): void {
            CountryMarket::query()->firstOrCreate(
                ['country_code' => $destination->country_code],
                [
                    'name' => $destination->country_name,
                    'default_locale' => $destination->locale,
                    'default_timezone' => $destination->timezone,
                    'is_enabled' => false,
                    'compliance_status' => 'pending',
                ],
            );
        };

        static::created($registerPendingMarket);
        static::updated(function (Destination $destination) use ($registerPendingMarket): void {
            if ($destination->wasChanged('country_code')) {
                $registerPendingMarket($destination);
            }
        });
    }

    protected $fillable = [
        'name',
        'slug',
        'country_code',
        'country_name',
        'city_name',
        'currency_code',
        'locale',
        'timezone',
        'description',
        'image_path',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function countryMarket(): BelongsTo
    {
        return $this->belongsTo(CountryMarket::class, 'country_code', 'country_code');
    }

    public function scopeOperationallyAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereHas('countryMarket', fn (Builder $market) => $market->operationallyApproved());
    }

    public function companionProfiles(): HasMany
    {
        return $this->hasMany(CompanionProfile::class);
    }
}
