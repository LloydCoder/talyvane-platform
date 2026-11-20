<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSecret extends Model
{
    protected $fillable = [
        'secret_key',
        'vault_path',
        'status',
        'vault_version',
        'configured_by',
        'last_rotated_at',
        'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'vault_version' => 'integer',
            'last_rotated_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }
}
