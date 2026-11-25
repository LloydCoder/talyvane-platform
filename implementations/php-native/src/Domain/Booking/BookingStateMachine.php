<?php
declare(strict_types=1);

namespace Talyvane\Domain\Booking;

final class BookingStateMachine
{
    private const TRANSITIONS = [
        'draft' => ['requested','cancelled'],
        'requested' => ['pending_review','awaiting_automatic_rule','accepted','confirmed','declined','cancelled','expired'],
        'pending_review' => ['accepted','declined','cancelled','expired'],
        'awaiting_automatic_rule' => ['accepted','pending_review','declined','cancelled','expired'],
        'accepted' => ['confirmed','cancelled','disputed'],
        'confirmed' => ['completed','cancelled','no_show','disputed'],
        'completed' => ['disputed'],
        'declined' => [],
        'cancelled' => [],
        'expired' => [],
        'no_show' => [],
        'disputed' => [],
    ];

    private const ACTOR_TRANSITIONS = [
        'client' => ['draft>requested','requested>cancelled','accepted>cancelled','confirmed>cancelled','confirmed>disputed','completed>disputed'],
        'companion' => ['requested>accepted','requested>declined','pending_review>accepted','pending_review>declined','accepted>cancelled','confirmed>cancelled','confirmed>disputed','completed>disputed'],
        'admin' => ['requested>pending_review','requested>awaiting_automatic_rule','requested>accepted','requested>declined','pending_review>accepted','pending_review>declined','awaiting_automatic_rule>accepted','awaiting_automatic_rule>pending_review','accepted>confirmed','confirmed>completed','confirmed>cancelled','confirmed>no_show','completed>disputed'],
        'owner' => ['requested>pending_review','requested>awaiting_automatic_rule','requested>accepted','requested>declined','pending_review>accepted','pending_review>declined','awaiting_automatic_rule>accepted','awaiting_automatic_rule>pending_review','accepted>confirmed','confirmed>completed','confirmed>cancelled','confirmed>no_show','completed>disputed'],
        'system' => ['requested>confirmed'],
        'super_admin' => ['*'],
    ];

    public static function transition(string $from, string $to, string $actorRole): string
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \DomainException('Booking state transition is not allowed.');
        }
        $allowed = self::ACTOR_TRANSITIONS[$actorRole] ?? [];
        if (!in_array('*', $allowed, true) && !in_array($from . '>' . $to, $allowed, true)) {
            throw new \DomainException('Actor is not authorized for this booking transition.');
        }
        return $to;
    }

    /** @return list<string> */
    public static function allowedFrom(string $state): array
    {
        return self::TRANSITIONS[$state] ?? [];
    }
}
