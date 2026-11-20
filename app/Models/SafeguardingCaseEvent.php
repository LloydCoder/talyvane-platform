<?php

namespace App\Models;

use App\Services\SafeguardingCaseContentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SafeguardingCaseEvent extends Model
{
    protected $fillable = [
        'case_id',
        'actor_id',
        'event_type',
        'from_status',
        'to_status',
        'note',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function setNoteAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['note'] = null;
            return;
        }

        $caseId = (int) ($this->attributes['case_id'] ?? 0);
        if ($caseId < 1) {
            throw new LogicException('Safeguarding event notes require a case identifier.');
        }

        $this->attributes['note'] = app(SafeguardingCaseContentService::class)->encryptForCase($caseId, $value);
    }

    public function getNoteAttribute(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(SafeguardingCaseContentService::class)->decryptForCase((int) $this->case_id, $value);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Safeguarding case events are append-only.'));
        static::deleting(fn () => throw new LogicException('Safeguarding case events are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
