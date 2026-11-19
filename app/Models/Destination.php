<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
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

    public function companionProfiles(): HasMany
    {
        return $this->hasMany(CompanionProfile::class);
    }
}
