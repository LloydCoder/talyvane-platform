<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'is_public',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }
}
