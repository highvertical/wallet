<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Enums;

final class HoldStatus
{
    public const ACTIVE = 'active';
    public const RELEASED = 'released';
    public const CAPTURED = 'captured';
    public const EXPIRED = 'expired';

    /** @return string[] */
    public static function values(): array
    {
        return [self::ACTIVE, self::RELEASED, self::CAPTURED, self::EXPIRED];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
