<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Enums;

final class TransactionCategory
{
    public const DEPOSIT = 'deposit';
    public const WITHDRAWAL = 'withdrawal';
    public const TRANSFER_IN = 'transfer_in';
    public const TRANSFER_OUT = 'transfer_out';
    public const FEE = 'fee';
    public const REVERSAL = 'reversal';
    public const ADJUSTMENT = 'adjustment';
    public const HOLD_CAPTURE = 'hold_capture';

    /** @return string[] */
    public static function values(): array
    {
        return [
            self::DEPOSIT,
            self::WITHDRAWAL,
            self::TRANSFER_IN,
            self::TRANSFER_OUT,
            self::FEE,
            self::REVERSAL,
            self::ADJUSTMENT,
            self::HOLD_CAPTURE,
        ];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
