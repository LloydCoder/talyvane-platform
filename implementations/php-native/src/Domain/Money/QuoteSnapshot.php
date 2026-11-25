<?php
declare(strict_types=1);

namespace Talyvane\Domain\Money;

use DateTimeImmutable;

final readonly class QuoteSnapshot
{
    public function __construct(
        public int $originalAmountMinor,
        public string $originalCurrency,
        public ?int $targetAmountMinor,
        public ?string $targetCurrency,
        public ?string $exchangeRateDecimal,
        public ?string $exchangeRateSource,
        public ?DateTimeImmutable $exchangeRateObservedAt,
        public ?DateTimeImmutable $exchangeRateExpiresAt,
        public ?string $taxPolicyVersion,
        public array $fees,
        public string $marketCode,
        public string $policyVersion,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($originalAmountMinor < 0 || !preg_match('/^[A-Z]{3}$/D', $originalCurrency) ||
            !preg_match('/^[A-Z]{2}$/D', $marketCode) || $policyVersion === '') {
            throw new \InvalidArgumentException('Quote snapshot is invalid.');
        }
        if (($targetAmountMinor === null) !== ($targetCurrency === null)) {
            throw new \InvalidArgumentException('Target amount and currency must be provided together.');
        }
        if ($targetCurrency !== null && !preg_match('/^[A-Z]{3}$/D', $targetCurrency)) {
            throw new \InvalidArgumentException('Target currency is invalid.');
        }
        if ($exchangeRateDecimal !== null && !preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,12})?$/D', $exchangeRateDecimal)) {
            throw new \InvalidArgumentException('Exchange rate must be a positive decimal string.');
        }
        if ($targetCurrency !== null && ($exchangeRateDecimal === null || $exchangeRateSource === null || $exchangeRateObservedAt === null || $exchangeRateExpiresAt === null)) {
            throw new \InvalidArgumentException('Converted quote requires a complete exchange-rate snapshot.');
        }
    }

    public function accept(DateTimeImmutable $acceptedAt): self
    {
        if ($acceptedAt >= $this->expiresAt) {
            throw new \DomainException('Expired quote cannot be accepted.');
        }
        if ($this->exchangeRateExpiresAt !== null && $acceptedAt >= $this->exchangeRateExpiresAt) {
            throw new \DomainException('Expired exchange rate cannot be accepted.');
        }
        return $this;
    }
}
