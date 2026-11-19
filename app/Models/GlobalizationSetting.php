<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlobalizationSetting extends Model
{
    protected $fillable = [
        'base_currency_code',
        'default_locale',
        'default_timezone',
        'updated_by',
    ];

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_code', 'code');
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            ['default_locale' => 'en', 'default_timezone' => 'UTC'],
        );
    }
}
