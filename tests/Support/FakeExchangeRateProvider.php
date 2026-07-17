<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Support;

use Highvertical\Wallet\Domain\Contracts\ExchangeRateProvider;

final class FakeExchangeRateProvider implements ExchangeRateProvider
{
    public int $calls = 0;

    public function __construct(private string $rate = '1500.00')
    {
    }

    public function getRate(string $from, string $to): string
    {
        $this->calls++;

        return $this->rate;
    }
}
