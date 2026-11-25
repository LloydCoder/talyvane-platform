<?php
declare(strict_types=1);

namespace Talyvane\Domain\ModelAgency;

final class ApplicationStateMachine
{
    private const TRANSITIONS = [
        'submitted' => ['under_review','withdrawn'],
        'under_review' => ['accepted','declined','withdrawn'],
        'accepted' => ['withdrawn'],
        'declined' => ['withdrawn'],
        'withdrawn' => [],
        'anonymized' => [],
    ];

    public static function transition(string $from, string $to, string $actorRole): string
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \DomainException('Model application state transition is not allowed.');
        }
        if (!in_array($actorRole, ['admin','owner','super_admin'], true) && !($actorRole === 'applicant' && $to === 'withdrawn')) {
            throw new \DomainException('Actor is not authorized for this application transition.');
        }
        return $to;
    }
}
