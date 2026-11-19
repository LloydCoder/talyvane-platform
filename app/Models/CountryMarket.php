<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CountryMarket extends Model
{
    protected $primaryKey = 'country_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'country_code',
        'alpha3_code',
        'name',
        'default_currency_code',
        'default_locale',
        'default_timezone',
        'is_enabled',
        'compliance_status',
        'compliance_reviewed_at',
        'compliance_reviewed_by',
        'market_policy',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'compliance_reviewed_at' => 'immutable_datetime',
            'market_policy' => 'array',
        ];
    }

    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_code', 'code');
    }

    public function localizedPrices(): HasMany
    {
        return $this->hasMany(LocalizedPrice::class, 'country_code', 'country_code');
    }
}
