<?php
declare(strict_types=1);

namespace Talyvane\Domain\Payments;

final class PaymentStateMachine
{
    private const TRANSITIONS = [
        'created' => ['pending','failed','expired'],
        'pending' => ['observed','failed','expired','disputed'],
        'observed' => ['confirming','underpaid','overpaid','failed','disputed'],
        'confirming' => ['confirmed','underpaid','overpaid','failed','disputed'],
        'underpaid' => ['pending','refund_pending','disputed'],
        'overpaid' => ['confirmed','refund_pending','disputed'],
        'confirmed' => ['settled','refund_pending','disputed'],
        'settled' => ['refund_pending','disputed'],
        'refund_pending' => ['refunded','failed','disputed'],
        'failed' => [],
        'expired' => [],
        'refunded' => [],
        'disputed' => [],
    ];

    public static function transition(string $from, string $to, string $actorType, ?string $evidenceReference = null): string
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \DomainException('Payment state transition is not allowed.');
        }
        if (!in_array($actorType, ['system','provider','finance','owner','super_admin'], true)) {
            throw new \DomainException('Actor is not authorized for payment reconciliation.');
        }
        if (in_array($to, ['observed','confirmed','settled','refunded','disputed'], true) &&
            ($evidenceReference === null || trim($evidenceReference) === '')) {
            throw new \DomainException('Payment transition requires external evidence.');
        }
        if (in_array($actorType, ['finance','owner','super_admin'], true) &&
            in_array($to, ['observed','confirmed','settled','refunded'], true) &&
            ($evidenceReference === null || trim($evidenceReference) === '')) {
            throw new \DomainException('Manual payment attestation requires a retained evidence reference.');
        }
        return $to;
    }

    /** @return list<string> */
    public static function allowedFrom(string $state): array
    {
        return self::TRANSITIONS[$state] ?? [];
    }
}
