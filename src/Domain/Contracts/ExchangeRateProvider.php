<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Contracts;

/**
 * Strategy interface for resolving a currency exchange rate. Bind a custom
 * implementation in your app's service provider to override
 * HttpExchangeRateProvider (e.g. to use a different FX API, a fixed-rate
 * table, or a paid provider).
 */
interface ExchangeRateProvider
{
    /**
     * Returns the multiplier to convert one unit of $from into $to, as a
     * positive bcmath-safe decimal string (never a float - see Money's
     * docblock on why floats never touch money). Must throw
     * ExchangeRateUnavailableException if no rate can be resolved.
     */
    public function getRate(string $from, string $to): string;
}
