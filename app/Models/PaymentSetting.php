<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    protected $fillable = [
        'payments_enabled',
        'crypto_enabled',
        'cards_enabled',
        'manual_enabled',
        'booking_payments_enabled',
        'membership_payments_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'payments_enabled' => 'boolean',
            'crypto_enabled' => 'boolean',
            'cards_enabled' => 'boolean',
            'manual_enabled' => 'boolean',
            'booking_payments_enabled' => 'boolean',
            'membership_payments_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'payments_enabled' => false,
                'crypto_enabled' => false,
                'cards_enabled' => false,
                'manual_enabled' => false,
                'booking_payments_enabled' => false,
                'membership_payments_enabled' => false,
            ],
        );
    }
}
