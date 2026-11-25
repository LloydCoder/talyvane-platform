<?php
declare(strict_types=1);

namespace Talyvane\Domain\Market;

use DateTimeImmutable;

final readonly class MarketPolicy
{
    public function __construct(
        public string $countryCode,
        public bool $enabled,
        public string $decision,
        public ?string $reviewerId,
        public ?DateTimeImmutable $reviewedAt,
        public ?DateTimeImmutable $expiresAt,
        public ?string $evidenceReference,
        public ?string $reviewSummary,
    ) {
        if (!preg_match('/^[A-Z]{2}$/D', $countryCode)) {
            throw new \InvalidArgumentException('Country must use an ISO 3166-1 alpha-2 code.');
        }
    }

    public function operationalAt(DateTimeImmutable $now): bool
    {
        return $this->enabled
            && $this->decision === 'approved'
            && $this->reviewerId !== null && $this->reviewerId !== ''
            && $this->reviewedAt !== null
            && $this->evidenceReference !== null && $this->evidenceReference !== ''
            && $this->reviewSummary !== null && trim($this->reviewSummary) !== ''
            && ($this->expiresAt === null || $this->expiresAt > $now)
            && $this->reviewedAt <= $now;
    }
}
