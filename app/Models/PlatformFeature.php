<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformFeature extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'name',
        'module',
        'description',
        'implementation_status',
        'is_enabled',
        'risk_level',
        'dependencies',
        'activation_requirements',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'dependencies' => 'array',
            'activation_requirements' => 'array',
        ];
    }
}
