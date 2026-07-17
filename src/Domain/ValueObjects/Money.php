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
    public static function fromDecimal(string $amount, string $currency): self
    {
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
