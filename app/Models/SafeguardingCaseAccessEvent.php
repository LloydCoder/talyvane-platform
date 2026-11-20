<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SafeguardingCaseAccessEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['case_id', 'actor_id', 'action', 'accessed_at'];

    protected function casts(): array
    {
        return ['accessed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Safeguarding case access events are append-only.'));
        static::deleting(fn () => throw new LogicException('Safeguarding case access events are append-only.'));
    }
}
