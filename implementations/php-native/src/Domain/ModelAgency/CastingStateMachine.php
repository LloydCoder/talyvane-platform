<?php
declare(strict_types=1);

namespace Talyvane\Domain\ModelAgency;

final class CastingStateMachine
{
    private const CALLS = [
        'draft' => ['published','cancelled'],
        'published' => ['closed','cancelled'],
        'closed' => [],
        'cancelled' => [],
    ];

    private const SUBMISSIONS = [
        'submitted' => ['shortlisted','selected','declined','withdrawn'],
        'shortlisted' => ['selected','declined','withdrawn'],
        'selected' => ['withdrawn'],
        'declined' => ['withdrawn'],
        'withdrawn' => [],
    ];

    public static function call(string $from, string $to): string
    {
        if (!in_array($to, self::CALLS[$from] ?? [], true)) {
            throw new \DomainException('Casting call transition is not allowed.');
        }
        return $to;
    }

    public static function submission(string $from, string $to, string $actorRole): string
    {
        if (!in_array($to, self::SUBMISSIONS[$from] ?? [], true)) {
            throw new \DomainException('Casting submission transition is not allowed.');
        }
        if (!in_array($actorRole, ['admin','owner','super_admin'], true) && !($actorRole === 'applicant' && $to === 'withdrawn')) {
            throw new \DomainException('Actor is not authorized for this casting decision.');
        }
        return $to;
    }
}
