<?php

namespace Tests\Feature;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GlobalizationMoneyTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_it_converts_major_units_to_minor_units_without_floating_point(string $major, string $currency, int $precision, int $expected, string $canonical): void
    {
        $money = Money::fromMajor($major, $currency, $precision);

        $this->assertSame($expected, $money->minorUnits());
        $this->assertSame($canonical, $money->toMajor());
    }

    public static function validAmounts(): array
    {
        return [
            'zero decimal currency' => ['125', 'JPY', 0, 125, '125'],
            'two decimal currency' => ['19.95', 'USD', 2, 1995, '19.95'],
            'three decimal currency' => ['12.345', 'BHD', 3, 12345, '12.345'],
            'negative ledger amount' => ['-0.50', 'USD', 2, -50, '-0.50'],
            'fractional amount padded' => ['7.5', 'USD', 2, 750, '7.50'],
        ];
    }

    public function test_it_rejects_precision_that_would_require_implicit_rounding(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMajor('1.001', 'USD', 2);
    }

    public function test_it_rejects_non_iso_currency_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMajor('1.00', 'USDT', 2);
    }

    public function test_it_rejects_binary_float_input(): void
    {
        $this->expectException(\TypeError::class);

        Money::fromMajor(0.1, 'USD', 2);
    }

    public function test_it_rejects_invalid_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMajor('1', 'USD', 4);
    }
}
