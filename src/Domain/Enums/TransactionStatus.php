<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Enums;

final class TransactionStatus
{
    public const PENDING = 'pending';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const REVERSED = 'reversed';

    /** @return string[] */
    public static function values(): array
    {
        return [self::PENDING, self::COMPLETED, self::FAILED, self::REVERSED];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
