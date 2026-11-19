<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final readonly class Money
{
    private function __construct(
        private int $minorUnits,
        private string $currency,
        private int $minorUnitPrecision,
    ) {
    }

    public static function fromMajor(string $amount, string $currency, int $minorUnitPrecision): self
    {
        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter uppercase ISO 4217 code.');
        }

        if ($minorUnitPrecision < 0 || $minorUnitPrecision > 3) {
            throw new InvalidArgumentException('Currency precision must be between zero and three minor-unit digits.');
        }

        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $amount)) {
            throw new InvalidArgumentException('Money amount must be a plain decimal string.');
        }

        $negative = str_starts_with($amount, '-');
        $unsigned = $negative ? substr($amount, 1) : $amount;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        if (strlen($fraction) > $minorUnitPrecision) {
            throw new InvalidArgumentException('Amount exceeds the currency minor-unit precision; implicit rounding is not allowed.');
        }

        $minorDigits = ltrim($whole.$fraction.str_repeat('0', $minorUnitPrecision - strlen($fraction)), '0');
        $minorDigits = $minorDigits === '' ? '0' : $minorDigits;
        $signedDigits = ($negative && $minorDigits !== '0' ? '-' : '').$minorDigits;
        $minorUnits = filter_var($signedDigits, FILTER_VALIDATE_INT);

        if ($minorUnits === false) {
            throw new InvalidArgumentException('Money amount exceeds the supported integer minor-unit range.');
        }

        return new self($minorUnits, $currency, $minorUnitPrecision);
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function precision(): int
    {
        return $this->minorUnitPrecision;
    }

    public function toMajor(): string
    {
        $digits = (string) abs($this->minorUnits);

        if ($this->minorUnitPrecision === 0) {
            return ($this->minorUnits < 0 ? '-' : '').$digits;
        }

        $digits = str_pad($digits, $this->minorUnitPrecision + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$this->minorUnitPrecision);
        $fraction = substr($digits, -$this->minorUnitPrecision);

        return ($this->minorUnits < 0 ? '-' : '').$whole.'.'.$fraction;
    }
}
