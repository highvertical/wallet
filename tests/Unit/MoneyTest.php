<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Unit;

use Highvertical\Wallet\Domain\Exceptions\CurrencyMismatchException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Tests\TestCase;

final class MoneyTest extends TestCase
{
    public function test_from_decimal_converts_to_minor_units(): void
    {
        $money = Money::fromDecimal('1500.00', 'NGN');

        $this->assertSame(150000, $money->minorUnits());
        $this->assertSame('NGN', $money->currency());
    }

    public function test_from_decimal_rounds_trip_to_decimal(): void
    {
        $money = Money::fromDecimal('1500.50', 'NGN');

        $this->assertSame('1500.50', $money->toDecimal());
    }

    public function test_from_decimal_respects_zero_decimal_currency(): void
    {
        $money = Money::fromDecimal('1500', 'JPY');

        $this->assertSame(1500, $money->minorUnits());
        $this->assertSame('1500', $money->toDecimal());
    }

    public function test_from_decimal_handles_negative_amounts(): void
    {
        $money = Money::fromDecimal('-500.00', 'NGN');

        $this->assertSame(-50000, $money->minorUnits());
        $this->assertTrue($money->isNegative());
        $this->assertSame('-500.00', $money->toDecimal());
    }

    public function test_from_decimal_rejects_scientific_notation(): void
    {
        $this->expectException(InvalidAmountException::class);

        Money::fromDecimal('1.5e3', 'NGN');
    }

    public function test_from_decimal_rejects_non_numeric(): void
    {
        $this->expectException(InvalidAmountException::class);

        Money::fromDecimal('abc', 'NGN');
    }

    public function test_from_decimal_rejects_too_many_decimal_places(): void
    {
        $this->expectException(InvalidAmountException::class);

        Money::fromDecimal('10.123', 'NGN');
    }

    public function test_add_requires_same_currency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::fromMinorUnits(100, 'NGN')->add(Money::fromMinorUnits(100, 'USD'));
    }

    public function test_add_and_subtract(): void
    {
        $sum = Money::fromMinorUnits(100, 'NGN')->add(Money::fromMinorUnits(50, 'NGN'));
        $diff = Money::fromMinorUnits(100, 'NGN')->subtract(Money::fromMinorUnits(50, 'NGN'));

        $this->assertSame(150, $sum->minorUnits());
        $this->assertSame(50, $diff->minorUnits());
    }

    public function test_abs_and_negate(): void
    {
        $negative = Money::fromMinorUnits(-100, 'NGN');

        $this->assertSame(100, $negative->abs()->minorUnits());
        $this->assertSame(100, $negative->negate()->minorUnits());
    }

    public function test_comparisons(): void
    {
        $small = Money::fromMinorUnits(50, 'NGN');
        $big = Money::fromMinorUnits(100, 'NGN');

        $this->assertTrue($big->isGreaterThan($small));
        $this->assertTrue($small->isLessThan($big));
        $this->assertTrue($small->equals(Money::fromMinorUnits(50, 'NGN')));
        $this->assertTrue($small->isSameCurrency($big));
    }

    public function test_zero_and_is_zero(): void
    {
        $this->assertTrue(Money::zero('NGN')->isZero());
        $this->assertFalse(Money::fromMinorUnits(1, 'NGN')->isZero());
    }

    public function test_currency_is_normalized_to_uppercase(): void
    {
        $this->assertSame('NGN', Money::fromMinorUnits(100, 'ngn')->currency());
    }

    public function test_from_decimal_rejects_an_unconfigured_currency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::fromDecimal('10.00', 'XYZ');
    }

    public function test_from_minor_units_does_not_validate_currency(): void
    {
        // Reconstructing already-persisted data must never throw just
        // because a currency was later removed from config.
        $money = Money::fromMinorUnits(100, 'XYZ');

        $this->assertSame('XYZ', $money->currency());
    }

    public function test_from_decimal_rejects_an_oversized_amount(): void
    {
        $this->expectException(InvalidAmountException::class);

        Money::fromDecimal('9999999999999999.00', 'NGN');
    }

    public function test_from_decimal_accepts_a_large_but_safe_amount(): void
    {
        $money = Money::fromDecimal('9999999999999.00', 'NGN');

        $this->assertSame('9999999999999.00', $money->toDecimal());
    }

    public function test_convert_to_applies_the_rate_and_rounds_half_up(): void
    {
        $converted = Money::fromDecimal('10.00', 'USD')->convertTo('NGN', '1500.005');

        $this->assertSame('15000.05', $converted->toDecimal());
        $this->assertSame('NGN', $converted->currency());
    }

    public function test_convert_to_rounds_a_tie_half_up(): void
    {
        // 1.00 USD at 0.125 lands exactly on the 0.125 boundary between
        // 0.12 and 0.13 at NGN's 2 decimal places - half-up rounds to 0.13.
        $converted = Money::fromDecimal('1.00', 'USD')->convertTo('NGN', '0.125');

        $this->assertSame('0.13', $converted->toDecimal());
    }

    public function test_convert_to_across_decimal_places(): void
    {
        // 1000 JPY (0 decimal places) at 0.0067 -> 6.70 USD
        $converted = Money::fromDecimal('1000', 'JPY')->convertTo('USD', '0.0067');

        $this->assertSame('6.70', $converted->toDecimal());
    }

    public function test_convert_to_rejects_a_non_positive_rate(): void
    {
        $this->expectException(InvalidAmountException::class);

        Money::fromDecimal('10.00', 'USD')->convertTo('NGN', '0');
    }

    public function test_convert_to_rejects_a_negative_rate(): void
    {
        $this->expectException(InvalidAmountException::class);

        Money::fromDecimal('10.00', 'USD')->convertTo('NGN', '-1500.00');
    }

    public function test_convert_to_reuses_from_decimal_validation(): void
    {
        // A converted amount landing at 16 total digits must still be
        // rejected by the same digit cap fromDecimal() enforces directly.
        $this->expectException(InvalidAmountException::class);

        Money::fromDecimal('9999999999999.00', 'USD')->convertTo('NGN', '10');
    }
}
