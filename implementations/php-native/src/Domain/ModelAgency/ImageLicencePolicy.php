<?php
declare(strict_types=1);

namespace Talyvane\Domain\ModelAgency;

use DateTimeImmutable;

final readonly class ImageLicencePolicy
{
    public function __construct(
        public string $status,
        public string $purpose,
        public string $territoryCode,
        public string $channel,
        public bool $agreementExecuted,
        public bool $assetApproved,
        public bool $consentCurrent,
        public ?string $externalExecutionEvidence,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public ?DateTimeImmutable $revokedAt,
    ) {}

    public function permits(string $purpose, string $territory, string $channel, DateTimeImmutable $now): bool
    {
        return $this->status === 'active'
            && $this->purpose === $purpose
            && $this->territoryCode === $territory
            && $this->channel === $channel
            && $this->agreementExecuted
            && $this->assetApproved
            && $this->consentCurrent
            && $this->externalExecutionEvidence !== null
            && trim($this->externalExecutionEvidence) !== ''
            && $this->validFrom <= $now
            && ($this->validUntil === null || $this->validUntil > $now)
            && ($this->revokedAt === null || $this->revokedAt > $now);
    }
}
