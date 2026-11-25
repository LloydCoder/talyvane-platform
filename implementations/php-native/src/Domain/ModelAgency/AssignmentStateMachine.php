<?php
declare(strict_types=1);

namespace Talyvane\Domain\ModelAgency;

final class AssignmentStateMachine
{
    private const TRANSITIONS = [
        'draft' => ['offered','cancelled'],
        'offered' => ['accepted','declined','cancelled'],
        'accepted' => ['confirmed','declined','cancelled','disputed'],
        'confirmed' => ['in_progress','cancelled','disputed'],
        'in_progress' => ['completed','disputed'],
        'completed' => ['disputed'],
        'declined' => [],
        'cancelled' => [],
        'disputed' => [],
    ];

    public static function transition(string $from, string $to, string $actorRole, ?string $evidenceReference = null): string
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \DomainException('Model assignment transition is not allowed.');
        }
        $agency = in_array($actorRole, ['admin','owner','super_admin'], true);
        $talent = $actorRole === 'applicant';
        if (!$agency && !$talent) {
            throw new \DomainException('Actor is not authorized for this assignment.');
        }
        if ($talent && !in_array($from . '>' . $to, ['offered>accepted','offered>declined','accepted>declined'], true)) {
            throw new \DomainException('Talent can only accept or decline an offer.');
        }
        if (in_array($to, ['accepted','confirmed','completed'], true) && ($evidenceReference === null || trim($evidenceReference) === '')) {
            throw new \DomainException('External offer/acceptance/completion evidence is required.');
        }
        return $to;
    }
}
