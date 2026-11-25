<?php
declare(strict_types=1);

namespace Talyvane\Domain\Membership;

use DateTimeImmutable;

final readonly class MembershipAccess
{
    public function __construct(
        public string $membershipStatus,
        public ?DateTimeImmutable $membershipStartsAt,
        public ?DateTimeImmutable $membershipEndsAt,
        public string $grantEntitlement,
        public DateTimeImmutable $grantStartsAt,
        public ?DateTimeImmutable $grantEndsAt,
        public bool $grantActive,
    ) {}

    public function allows(string $requestedEntitlement, DateTimeImmutable $now): bool
    {
        if ($requestedEntitlement === '' || $requestedEntitlement !== $this->grantEntitlement || !$this->grantActive) {
            return false;
        }
        if ($this->membershipStatus !== 'active') {
            return false;
        }
        if ($this->membershipStartsAt !== null && $this->membershipStartsAt > $now) {
            return false;
        }
        if ($this->membershipEndsAt !== null && $this->membershipEndsAt <= $now) {
            return false;
        }
        if ($this->grantStartsAt > $now) {
            return false;
        }
        return $this->grantEndsAt === null || $this->grantEndsAt > $now;
    }
}
