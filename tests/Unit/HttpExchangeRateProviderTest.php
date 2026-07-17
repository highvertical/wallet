<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Unit;

use Highvertical\Wallet\Domain\Exceptions\ExchangeRateUnavailableException;
use Highvertical\Wallet\Infrastructure\ExchangeRateProviders\HttpExchangeRateProvider;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Http;

final class HttpExchangeRateProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Cache::remember needs a working store; the array driver is
        // enough here and keeps this test isolated from any host app's
        // real cache configuration.
        config(['cache.default' => 'array']);
    }

    public function test_it_returns_one_without_calling_out_when_currencies_match(): void
    {
        Http::fake();

        $rate = (new HttpExchangeRateProvider())->getRate('USD', 'USD');

        $this->assertSame('1', $rate);
        Http::assertNothingSent();
    }

    public function test_it_fetches_and_returns_the_rate(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);
        config(['wallet.exchange.response_path' => 'rates.{to}']);

        Http::fake([
            'example.test/*' => Http::response(['rates' => ['NGN' => 1500.005]], 200),
        ]);

        $rate = (new HttpExchangeRateProvider())->getRate('USD', 'NGN');

        $this->assertSame('1500.0050000000', $rate);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.test/v6/latest/USD';
        });
    }

    public function test_it_caches_the_rate_so_only_one_http_call_is_made(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);
        config(['wallet.exchange.response_path' => 'rates.{to}']);
        config(['wallet.exchange.cache_ttl_seconds' => 3600]);

        Http::fake([
            'example.test/*' => Http::response(['rates' => ['NGN' => 1500.00]], 200),
        ]);

        $provider = new HttpExchangeRateProvider();
        $provider->getRate('USD', 'NGN');
        $provider->getRate('USD', 'NGN');

        Http::assertSentCount(1);
    }

    public function test_it_throws_when_the_response_is_not_successful(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);

        Http::fake([
            'example.test/*' => Http::response(['error' => 'nope'], 500),
        ]);

        $this->expectException(ExchangeRateUnavailableException::class);

        (new HttpExchangeRateProvider())->getRate('USD', 'NGN');
    }

    public function test_it_throws_when_the_connection_fails(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        $this->expectException(ExchangeRateUnavailableException::class);

        (new HttpExchangeRateProvider())->getRate('USD', 'NGN');
    }

    /**
     * A connection failure's underlying exception message routinely embeds
     * the full request URI (including the api_key query param, when
     * configured) - that raw message must never surface in the exception
     * this method throws, since it's rendered verbatim as JSON to the HTTP
     * caller who requested the transfer.
     */
    public function test_the_connection_failure_message_never_leaks_the_api_key(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);
        config(['wallet.exchange.api_key' => 'super-secret-key']);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 6: could not resolve host for https://example.test/v6/latest/USD?access_key=super-secret-key'
            );
        });

        try {
            (new HttpExchangeRateProvider())->getRate('USD', 'NGN');
            $this->fail('Expected ExchangeRateUnavailableException was not thrown.');
        } catch (ExchangeRateUnavailableException $exception) {
            $this->assertStringNotContainsString('super-secret-key', $exception->getMessage());
        }
    }

    public function test_it_rejects_a_malformed_currency_code_before_calling_out(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);

        Http::fake();

        $this->expectException(ExchangeRateUnavailableException::class);

        try {
            (new HttpExchangeRateProvider())->getRate('USD', 'NGN?evil=1');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_throws_when_the_response_path_is_missing(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);
        config(['wallet.exchange.response_path' => 'rates.{to}']);

        Http::fake([
            'example.test/*' => Http::response(['rates' => []], 200),
        ]);

        $this->expectException(ExchangeRateUnavailableException::class);

        (new HttpExchangeRateProvider())->getRate('USD', 'NGN');
    }

    public function test_it_throws_when_the_response_value_is_not_numeric(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);
        config(['wallet.exchange.response_path' => 'rates.{to}']);

        Http::fake([
            'example.test/*' => Http::response(['rates' => ['NGN' => 'not-a-number']], 200),
        ]);

        $this->expectException(ExchangeRateUnavailableException::class);

        (new HttpExchangeRateProvider())->getRate('USD', 'NGN');
    }

    public function test_it_throws_when_the_response_value_is_not_positive(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);
        config(['wallet.exchange.response_path' => 'rates.{to}']);

        Http::fake([
            'example.test/*' => Http::response(['rates' => ['NGN' => 0]], 200),
        ]);

        $this->expectException(ExchangeRateUnavailableException::class);

        (new HttpExchangeRateProvider())->getRate('USD', 'NGN');
    }

    public function test_it_appends_the_api_key_query_param_when_configured(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);
        config(['wallet.exchange.response_path' => 'rates.{to}']);
        config(['wallet.exchange.api_key' => 'secret-key']);
        config(['wallet.exchange.api_key_query_param' => 'access_key']);

        Http::fake([
            'example.test/*' => Http::response(['rates' => ['NGN' => 1500.00]], 200),
        ]);

        (new HttpExchangeRateProvider())->getRate('USD', 'NGN');

        Http::assertSent(function ($request) {
            return $request['access_key'] === 'secret-key' || str_contains($request->url(), 'access_key=secret-key');
        });
    }

    public function test_it_does_not_append_an_api_key_param_when_not_configured(): void
    {
        config(['wallet.exchange.endpoint' => 'https://example.test/v6/latest/{from}']);
        config(['wallet.exchange.response_path' => 'rates.{to}']);
        config(['wallet.exchange.api_key' => null]);

        Http::fake([
            'example.test/*' => Http::response(['rates' => ['NGN' => 1500.00]], 200),
        ]);

        (new HttpExchangeRateProvider())->getRate('USD', 'NGN');

        Http::assertSent(function ($request) {
            return ! str_contains($request->url(), 'access_key');
        });
    }
}
