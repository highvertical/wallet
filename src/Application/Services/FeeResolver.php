<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Services;

use Highvertical\Wallet\Domain\Contracts\FeeCalculator;
use Highvertical\Wallet\Domain\ValueObjects\Money;

final class FeeResolver
{
    public function __construct(private FeeCalculator $calculator)
    {
    }

    public function resolve(Money $amount, string $operation): Money
    {
        return $this->calculator->calculate($amount, $operation);
    }
}
