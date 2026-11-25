<?php
declare(strict_types=1);

namespace Talyvane\Domain\ModelAgency;

final class AgreementStateMachine
{
    private const TRANSITIONS = [
        'draft' => ['offered'],
        'offered' => ['executed','withdrawn'],
        'executed' => ['terminated'],
        'withdrawn' => [],
        'terminated' => [],
    ];

    public static function transition(string $from, string $to, string $actorRole, ?string $externalEvidenceReference = null): string
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \DomainException('Representation agreement transition is not allowed.');
        }
        if (!in_array($actorRole, ['admin','owner','super_admin'], true)) {
            throw new \DomainException('Only agency administration can manage agreement records.');
        }
        if ($to === 'executed' && ($externalEvidenceReference === null || trim($externalEvidenceReference) === '')) {
            throw new \DomainException('External execution evidence is required; this platform is not an e-signature service.');
        }
        return $to;
    }
}
