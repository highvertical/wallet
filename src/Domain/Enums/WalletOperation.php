<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Enums;

/**
 * The operation kinds that have their own configurable fee and limit rules
 * (config('wallet.fees'|'limits').{deposit,withdrawal,transfer}). Distinct
 * from TransactionCategory, which is the finer-grained ledger classification.
 */
final class WalletOperation
{
    public const DEPOSIT = 'deposit';
    public const WITHDRAWAL = 'withdrawal';
    public const TRANSFER = 'transfer';

    /** @return string[] */
    public static function values(): array
    {
        return [self::DEPOSIT, self::WITHDRAWAL, self::TRANSFER];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
