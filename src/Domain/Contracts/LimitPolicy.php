<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Contracts;

use Highvertical\Wallet\Domain\Exceptions\LimitExceededException;
use Highvertical\Wallet\Domain\ValueObjects\Money;

/**
 * Strategy interface for the business-level daily/monthly ceiling on an
 * operation - separate from and in addition to the infrastructure rate
 * limiter. $operation is one of Domain\Enums\WalletOperation's values.
 */
interface LimitPolicy
{
    /**
     * @throws LimitExceededException if $amount would push the wallet past
     *                                 its configured daily/monthly cap for $operation.
     */
    public function assertWithinLimit(int $walletId, Money $amount, string $operation): void;
}
