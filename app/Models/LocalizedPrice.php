<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LocalizedPrice extends Model
{
    protected $fillable = [
        'country_code',
        'currency_code',
        'amount_minor',
        'price_basis',
        'effective_from',
        'effective_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(CountryMarket::class, 'country_code', 'country_code');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }
}
