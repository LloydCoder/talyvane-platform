<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'numeric_code',
        'name',
        'symbol',
        'minor_unit',
        'is_enabled',
        'is_reporting_currency',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'minor_unit' => 'integer',
            'is_enabled' => 'boolean',
            'is_reporting_currency' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function baseExchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'base_currency_code');
    }

    public function quoteExchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'quote_currency_code');
    }
}
