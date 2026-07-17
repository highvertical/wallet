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
}
