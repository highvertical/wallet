<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Infrastructure\ExchangeRateProviders;

use Highvertical\Wallet\Domain\Contracts\ExchangeRateProvider;
use Highvertical\Wallet\Domain\Exceptions\ExchangeRateUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use ValueError;

/**
 * Reads config('wallet.exchange.*'). Fetches from a configurable HTTP
 * endpoint, cached per from/to pair so the row-locked transfer path never
 * waits on a live call - TransferFundsAction resolves the rate before
 * entering WalletLocker::lockPair().
 */
final class HttpExchangeRateProvider implements ExchangeRateProvider
{
    private const CURRENCY_CODE_PATTERN = '/^[A-Z]{3}$/';

    public function getRate(string $from, string $to): string
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return '1';
        }

        // $from/$to are interpolated straight into the endpoint URL below;
        // Money's own currency whitelist is opt-in (skipped entirely when
        // wallet.currencies is left unconfigured), so this class can't rely
        // on it having run - validate the shape here regardless of caller.
        if (! preg_match(self::CURRENCY_CODE_PATTERN, $from) || ! preg_match(self::CURRENCY_CODE_PATTERN, $to)) {
            throw new ExchangeRateUnavailableException(sprintf(
                '"%s" or "%s" is not a valid 3-letter currency code.',
                $from,
                $to
            ));
        }

        $cacheKey = "wallet:exchange-rate:{$from}:{$to}";
        $ttl = (int) config('wallet.exchange.cache_ttl_seconds', 3600);

        return Cache::remember($cacheKey, $ttl, fn () => $this->fetch($from, $to));
    }

    private function fetch(string $from, string $to): string
    {
        $endpoint = str_replace(
            ['{from}', '{to}'],
            [$from, $to],
            (string) config('wallet.exchange.endpoint')
        );

        $query = [];
        $apiKey = config('wallet.exchange.api_key');

        if ($apiKey !== null && $apiKey !== '') {
            $query[(string) config('wallet.exchange.api_key_query_param', 'access_key')] = $apiKey;
        }

        try {
            $response = Http::timeout((int) config('wallet.exchange.timeout_seconds', 5))->get($endpoint, $query);
        } catch (Throwable $exception) {
            // $exception->getMessage() is never surfaced here: Guzzle/cURL
            // transfer exceptions embed the full request URI, including the
            // api_key query parameter, and this exception's message is
            // rendered verbatim as JSON to the HTTP caller.
            report($exception);

            throw new ExchangeRateUnavailableException(sprintf(
                'Could not reach the exchange rate provider for %s to %s.',
                $from,
                $to
            ));
        }

        if (! $response->successful()) {
            throw new ExchangeRateUnavailableException(sprintf(
                'Exchange rate provider returned HTTP %d for %s to %s.',
                $response->status(),
                $from,
                $to
            ));
        }

        $path = str_replace('{to}', $to, (string) config('wallet.exchange.response_path', 'rates.{to}'));
        $value = data_get($response->json(), $path);

        if (! is_numeric($value)) {
            throw new ExchangeRateUnavailableException(sprintf(
                'Exchange rate provider response did not contain a numeric rate at "%s" for %s to %s.',
                $path,
                $from,
                $to
            ));
        }

        $rate = sprintf('%.10F', (float) $value);

        try {
            $isPositive = bccomp($rate, '0', 10) > 0;
        } catch (ValueError $exception) {
            throw new ExchangeRateUnavailableException(sprintf(
                'Exchange rate provider returned a malformed rate for %s to %s.',
                $from,
                $to
            ));
        }

        if (! $isPositive) {
            throw new ExchangeRateUnavailableException(sprintf(
                'Exchange rate provider returned a non-positive rate for %s to %s.',
                $from,
                $to
            ));
        }

        return $rate;
    }
}
