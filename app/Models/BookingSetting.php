<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSetting extends Model
{
    protected $fillable = [
        'booking_mode',
        'auto_confirm_enabled',
        'auto_requires_verified_deposit',
        'minimum_notice_hours',
        'maximum_advance_days',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'auto_confirm_enabled' => 'boolean',
            'auto_requires_verified_deposit' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'booking_mode' => 'manual',
                'auto_confirm_enabled' => false,
                'auto_requires_verified_deposit' => true,
                'minimum_notice_hours' => 12,
                'maximum_advance_days' => 90,
            ],
        );
    }
}
