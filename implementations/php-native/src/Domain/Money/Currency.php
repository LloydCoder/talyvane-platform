<?php
declare(strict_types=1);

namespace Talyvane\Domain\Money;

final readonly class Currency
{
    public function __construct(
        public string $code,
        public string $name,
        public int $minorUnits,
        public bool $enabled,
        public ?string $numericCode = null,
        public ?string $symbol = null,
        public string $datasetVersion = ''
    ) {
        if (!preg_match('/^[A-Z]{3}$/D', $code) || $name === '' || $minorUnits < 0 || $minorUnits > 4) {
            throw new \InvalidArgumentException('Currency reference record is invalid.');
        }
        if ($numericCode !== null && !preg_match('/^[0-9]{3}$/D', $numericCode)) {
            throw new \InvalidArgumentException('ISO currency numeric code must contain three digits.');
        }
    }
}
