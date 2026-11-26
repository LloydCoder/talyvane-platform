<?php
declare(strict_types=1);

namespace Talyvane\\Domain\\Booking;

use DateTimeImmutable;

final readonly class BookingCancellationPolicy
{
    public function __construct(
        public bool $clientCancellationEnabled,
        public int $clientCancellationCutoffMinutes
    ) {
        if ($clientCancellationCutoffMinutes < 0 || $clientCancellationCutoffMinutes > 43200) {
            throw new \\InvalidArgumentException('Client cancellation cutoff is invalid.');
        }
    }

    public function assertAllowed(string $actorRole,string $bookingState,DateTimeImmutable $startsAt,DateTimeImmutable $now): void
    {
        if ($actorRole !== 'client' || $bookingState !== 'confirmed' || !$this->clientCancellationEnabled) {
            throw new \\DomainException('Client cancellation is not permitted for this booking.');
        }
        if ($startsAt <= $now->modify('+' . $this->clientCancellationCutoffMinutes . ' minutes')) {
            throw new \\DomainException('The client cancellation window has closed.');
        }
    }
}
