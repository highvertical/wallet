<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Enums;

final class TransactionType
{
    public const CREDIT = 'credit';
    public const DEBIT = 'debit';

    /** @return string[] */
    public static function values(): array
    {
        return [self::CREDIT, self::DEBIT];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
