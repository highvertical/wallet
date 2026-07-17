<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Services;

use Highvertical\Wallet\Domain\Contracts\LimitPolicy;
use Highvertical\Wallet\Domain\ValueObjects\Money;

final class LimitEnforcer
{
    public function __construct(private LimitPolicy $policy)
    {
    }

    public function assertWithinLimit(int $walletId, Money $amount, string $operation): void
    {
        $this->policy->assertWithinLimit($walletId, $amount, $operation);
    }
}
