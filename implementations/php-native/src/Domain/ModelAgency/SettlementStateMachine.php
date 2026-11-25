<?php
declare(strict_types=1);

namespace Talyvane\Domain\ModelAgency;

final class SettlementStateMachine
{
    private const TRANSITIONS = [
        'draft' => ['approved'],
        'approved' => ['paid','disputed'],
        'paid' => ['reversed','disputed'],
        'reversed' => ['disputed'],
        'disputed' => [],
    ];

    public static function transition(
        string $from,
        string $to,
        string $actorRole,
        string $creatorId,
        string $actorId,
        ?string $approvedById = null,
        ?string $externalEvidenceReference = null
    ): string {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \DomainException('Settlement transition is not allowed.');
        }
        if (!in_array($actorRole, ['finance','owner','super_admin'], true)) {
            throw new \DomainException('Actor is not authorized for settlement operations.');
        }
        if ($to === 'approved' && $creatorId === $actorId) {
            throw new \DomainException('Settlement creator cannot approve their own settlement.');
        }
        if ($to === 'paid' && ($creatorId === $actorId || $approvedById === null || $approvedById === $actorId)) {
            throw new \DomainException('Settlement payment attestation requires a distinct maker, approver and payer.');
        }
        if (in_array($to, ['paid','reversed','disputed'], true) && ($externalEvidenceReference === null || trim($externalEvidenceReference) === '')) {
            throw new \DomainException('External payment/reversal/dispute evidence is required.');
        }
        return $to;
    }
}
