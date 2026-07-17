<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Services;

use Highvertical\Wallet\Domain\Contracts\ExchangeRateProvider;

final class CurrencyConverter
{
    public function __construct(private ExchangeRateProvider $provider)
    {
    }

    public function rate(string $from, string $to): string
    {
        return $this->provider->getRate($from, $to);
    }
}
