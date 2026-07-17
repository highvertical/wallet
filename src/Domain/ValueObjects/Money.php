<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\ValueObjects;

use Highvertical\Wallet\Domain\Exceptions\CurrencyMismatchException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;

/**
 * Currency-aware, integer-minor-unit money. Conventionally immutable by
 * discipline (readonly properties are off the table on the PHP 8.0 floor):
 * every operation returns a new instance. This is the only class permitted
 * to convert between minor units and a decimal display string.
 */
final class Money
{
    private int $minorUnits;

    private string $currency;

    public function __construct(int $minorUnits, string $currency)
    {
        $this->minorUnits = $minorUnits;
        $this->currency = strtoupper($currency);
    }

    public static function fromMinorUnits(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Parses a strict decimal string ("5000", "1500.00", "-500.00") into
     * minor units. Rejects scientific notation, "NaN", "Infinity", and more
     * fractional digits than the currency allows, using string arithmetic
     * only - no float ever touches the value.
     */
    /**
     * Total normalized digits (whole + fractional) allowed in a decimal
     * amount. Chosen to leave large headroom below PHP_INT_MAX (~9.2e18) so
     * repeated additions across many transactions can never silently
     * overflow, while comfortably exceeding any realistic wallet balance.
     */
    private const MAX_TOTAL_DIGITS = 15;

    public static function fromDecimal(string $amount, string $currency): self
    {
        self::assertConfiguredCurrency($currency);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidAmountException(sprintf('"%s" is not a valid decimal amount.', $amount));
        }

        $decimalPlaces = self::decimalPlacesFor($currency);
        $isNegative = $amount[0] === '-';
        $unsigned = ltrim($amount, '-');

        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        if (strlen($fraction) > $decimalPlaces) {
            throw new InvalidAmountException(sprintf(
                '"%s" has more decimal places than %s allows (%d).',
                $amount,
                strtoupper($currency),
                $decimalPlaces
            ));
        }

        $normalizedWhole = ltrim($whole, '0');
        $normalizedWhole = $normalizedWhole === '' ? '0' : $normalizedWhole;
        $totalDigits = strlen($normalizedWhole) + $decimalPlaces;

        if ($totalDigits > self::MAX_TOTAL_DIGITS) {
            throw new InvalidAmountException(sprintf(
                '"%s" has too many digits (maximum %d).',
                $amount,
                self::MAX_TOTAL_DIGITS
            ));
        }

        $fraction = str_pad($fraction, $decimalPlaces, '0');
        $minorUnits = (int) ($whole.$fraction);

        return new self($isNegative ? -$minorUnits : $minorUnits, $currency);
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    /**
     * Converts this amount into $targetCurrency using $rate (a positive
     * decimal string, multiplier from this currency to the target). Rounds
     * half-up to the target currency's configured decimal places using
     * bcmath only - no float ever touches the value - then delegates to
     * fromDecimal() so the existing digit-cap/currency-whitelist validation
     * applies uniformly rather than via a parallel bypass.
     */
    public function convertTo(string $targetCurrency, string $rate): self
    {
        if (bccomp($rate, '0', 10) <= 0) {
            throw new InvalidAmountException(sprintf('"%s" is not a valid positive exchange rate.', $rate));
        }

        $targetPlaces = self::decimalPlacesFor($targetCurrency);
        $product = bcmul($this->toDecimal(), $rate, $targetPlaces + 8);

        return self::fromDecimal(self::roundHalfUp($product, $targetPlaces), $targetCurrency);
    }

    private static function roundHalfUp(string $decimal, int $places): string
    {
        $half = '0.'.str_repeat('0', $places).'5';

        return $decimal[0] === '-' ? bcsub($decimal, $half, $places) : bcadd($decimal, $half, $places);
    }

    public function toDecimal(): string
    {
        $decimalPlaces = self::decimalPlacesFor($this->currency);
        $isNegative = $this->minorUnits < 0;
        $digits = (string) abs($this->minorUnits);

        if ($decimalPlaces === 0) {
            return ($isNegative ? '-' : '').$digits;
        }

        $digits = str_pad($digits, $decimalPlaces + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -$decimalPlaces);
        $fraction = substr($digits, -$decimalPlaces);

        return ($isNegative ? '-' : '').$whole.'.'.$fraction;
    }

    private static function decimalPlacesFor(string $currency): int
    {
        return (int) config('wallet.currencies.'.strtoupper($currency).'.decimal_places', 2);
    }

    /**
     * Only validated at the fromDecimal() entry point (new money created
     * from a raw string) - not in the constructor/fromMinorUnits(), so that
     * reconstructing Money from already-persisted data never throws just
     * because a currency was later removed from config.
     */
    private static function assertConfiguredCurrency(string $currency): void
    {
        $configured = array_keys((array) config('wallet.currencies', []));

        if ($configured !== [] && ! in_array(strtoupper($currency), $configured, true)) {
            throw new CurrencyMismatchException(sprintf(
                '"%s" is not a configured wallet currency.',
                strtoupper($currency)
            ));
        }
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->isSameCurrency($other)) {
            throw new CurrencyMismatchException(sprintf(
                'Cannot operate on %s and %s together.',
                $this->currency,
                $other->currency
            ));
        }
    }
}
