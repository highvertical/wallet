<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Enums;

final class WalletStatus
{
    public const ACTIVE = 'active';
    public const FROZEN = 'frozen';
    public const INACTIVE = 'inactive';

    /** @return string[] */
    public static function values(): array
    {
        return [self::ACTIVE, self::FROZEN, self::INACTIVE];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
