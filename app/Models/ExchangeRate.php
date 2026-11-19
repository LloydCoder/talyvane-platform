<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = [
        'base_currency_code',
        'quote_currency_code',
        'rate',
        'provider',
        'observed_at',
        'expires_at',
        'source_reference',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:14',
            'observed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_code', 'code');
    }

    public function quoteCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'quote_currency_code', 'code');
    }
}
