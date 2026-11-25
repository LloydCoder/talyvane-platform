<?php
declare(strict_types=1);

namespace Talyvane\Domain\Consent;

use DateTimeImmutable;

final readonly class ConsentGrant
{
    public function __construct(
        public string $subjectId,
        public string $purpose,
        public string $audience,
        public string $policyVersion,
        public bool $granted,
        public DateTimeImmutable $grantedAt,
        public ?DateTimeImmutable $withdrawnAt,
    ) {}

    public function validFor(string $subjectId, string $purpose, string $audience, string $currentPolicyVersion, DateTimeImmutable $now): bool
    {
        return $this->subjectId === $subjectId
            && $this->purpose === $purpose
            && $this->audience === $audience
            && $this->policyVersion === $currentPolicyVersion
            && $this->granted
            && $this->grantedAt <= $now
            && ($this->withdrawnAt === null || $this->withdrawnAt > $now);
    }
}
