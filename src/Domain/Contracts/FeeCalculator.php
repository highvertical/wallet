<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Contracts;

use Highvertical\Wallet\Domain\ValueObjects\Money;

/**
 * Strategy interface for computing the fee on an operation. $operation is
 * one of Domain\Enums\WalletOperation's values. Bind a custom implementation
 * in your app's service provider to override ConfigDrivenFeeCalculator.
 */
interface FeeCalculator
{
    public function calculate(Money $amount, string $operation): Money;
}
