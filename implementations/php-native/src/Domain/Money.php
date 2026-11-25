<?php
declare(strict_types=1);

namespace Talyvane\Domain;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(public int $minorUnits, public string $currency, public int $precision)
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Currency must be an ISO 4217 alpha code.');
        }
        if ($precision < 0 || $precision > 4) {
            throw new InvalidArgumentException('Unsupported currency precision.');
        }
    }

    public static function fromDecimal(string $amount, string $currency, int $precision): self
    {
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $amount, $m)) {
            throw new InvalidArgumentException('Amount must be a non-negative decimal string.');
        }
        $fraction = $m[2] ?? '';
        if (strlen($fraction) > $precision) {
            throw new InvalidArgumentException('Amount exceeds the currency precision.');
        }
        $scale = 10 ** $precision;
        $whole = (int) $m[1];
        if ($whole > intdiv(PHP_INT_MAX, $scale)) {
            throw new InvalidArgumentException('Amount exceeds supported range.');
        }
        $minor = ($whole * $scale) + (int) str_pad($fraction, $precision, '0');
        return new self($minor, $currency, $precision);
    }

    public function toDecimal(): string
    {
        $scale = 10 ** $this->precision;
        $whole = intdiv($this->minorUnits, $scale);
        if ($this->precision === 0) {
            return (string) $whole;
        }
        $fraction = str_pad((string) ($this->minorUnits % $scale), $this->precision, '0', STR_PAD_LEFT);
        return $whole . '.' . $fraction;
    }

    public function plus(self $other): self
    {
        $this->assertSameUnit($other);
        if ($other->minorUnits > PHP_INT_MAX - $this->minorUnits) {
            throw new InvalidArgumentException('Money amount overflow.');
        }
        return new self($this->minorUnits + $other->minorUnits, $this->currency, $this->precision);
    }

    private function assertSameUnit(self $other): void
    {
        if ($this->currency !== $other->currency || $this->precision !== $other->precision) {
            throw new InvalidArgumentException('Cannot combine different currency units.');
        }
    }
}
