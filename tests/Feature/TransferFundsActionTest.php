<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Contracts\ExchangeRateProvider;
use Highvertical\Wallet\Domain\Contracts\WalletRepository;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Data\TransferData;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Exceptions\CurrencyMismatchException;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletTransferred;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Tests\Support\FakeExchangeRateProvider;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class TransferFundsActionTest extends TestCase
{
    /**
     * A fixed-rate double bound over the real HttpExchangeRateProvider so
     * cross-currency tests never touch the network, and so calls can be
     * counted to prove the provider is skipped for same-currency transfers.
     */
    private function bindFakeExchangeRateProvider(string $rate = '1500.00'): FakeExchangeRateProvider
    {
        $fake = new FakeExchangeRateProvider($rate);
        $this->app->bind(ExchangeRateProvider::class, fn () => $fake);

        return $fake;
    }

    /**
     * Transfer's recipient-side lookup uses WalletRepository::find() (not
     * findOrCreate), so a recipient must already have a wallet in the
     * transfer currency - created here directly rather than via a deposit,
     * since a zero-amount deposit would itself be rejected as invalid.
     */
    private function givenWallet(TestUser $holder, string $currency = 'NGN'): void
    {
        $this->app->make(WalletRepository::class)->findOrCreate(
            $holder->getMorphClass(),
            $holder->getKey(),
            'default',
            $currency
        );
    }

    public function test_it_moves_funds_between_two_wallets(): void
    {
        Event::fake([WalletTransferred::class]);

        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);

        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('1000.00', 'NGN')));
        $this->givenWallet($recipient);

        $result = $manager->transfer(new TransferData(
            fromHolder: $sender,
            toHolder: $recipient,
            amount: Money::fromDecimal('300.00', 'NGN'),
        ));

        $this->assertSame(TransactionCategory::TRANSFER_OUT, $result['debit_transaction']->category);
        $this->assertSame(TransactionCategory::TRANSFER_IN, $result['credit_transaction']->category);
        $this->assertSame(70000, $sender->wallet()->fresh()->balance);
        $this->assertSame(30000, $recipient->wallet()->fresh()->balance);
        Event::assertDispatched(WalletTransferred::class);
    }

    public function test_it_rejects_zero_amount(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('100.00', 'NGN')));
        $this->givenWallet($recipient);

        $this->expectException(InvalidAmountException::class);

        $manager->transfer(new TransferData(fromHolder: $sender, toHolder: $recipient, amount: Money::zero('NGN')));
    }

    public function test_it_rejects_transfer_to_self(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $user = TestUser::create(['name' => 'Alice']);
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));

        $this->expectException(InvalidAmountException::class);

        $manager->transfer(new TransferData(fromHolder: $user, toHolder: $user, amount: Money::fromDecimal('10.00', 'NGN')));
    }

    public function test_it_rejects_transfer_when_recipient_has_no_wallet_in_that_currency(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('100.00', 'NGN')));

        $this->expectException(CurrencyMismatchException::class);

        $manager->transfer(new TransferData(fromHolder: $sender, toHolder: $recipient, amount: Money::fromDecimal('10.00', 'NGN')));
    }

    public function test_it_rejects_transfer_beyond_available_balance(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('50.00', 'NGN')));
        $this->givenWallet($recipient);

        $this->expectException(InsufficientFundsException::class);

        $manager->transfer(new TransferData(fromHolder: $sender, toHolder: $recipient, amount: Money::fromDecimal('100.00', 'NGN')));
    }

    public function test_it_rejects_transfer_onto_a_frozen_recipient_wallet(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('100.00', 'NGN')));
        $this->givenWallet($recipient);
        $recipientWallet = Wallet::query()->where('holder_type', $recipient->getMorphClass())->where('holder_id', $recipient->getKey())->firstOrFail();
        $manager->freeze($recipientWallet->getKey(), 'fraud', 1);

        $this->expectException(\Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException::class);

        $manager->transfer(new TransferData(fromHolder: $sender, toHolder: $recipient, amount: Money::fromDecimal('10.00', 'NGN')));
    }

    public function test_transfer_with_the_same_reference_is_idempotent(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('1000.00', 'NGN')));
        $this->givenWallet($recipient);

        $first = $manager->transfer(new TransferData(fromHolder: $sender, toHolder: $recipient, amount: Money::fromDecimal('100.00', 'NGN'), reference: 'tr-fixed'));
        $second = $manager->transfer(new TransferData(fromHolder: $sender, toHolder: $recipient, amount: Money::fromDecimal('100.00', 'NGN'), reference: 'tr-fixed'));

        $this->assertSame($first['transfer']->getKey(), $second['transfer']->getKey());
        $this->assertSame(90000, $sender->wallet()->fresh()->balance);
    }

    public function test_it_converts_a_cross_currency_transfer_at_the_bound_rate(): void
    {
        $this->bindFakeExchangeRateProvider('1500.00');

        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('1000.00', 'USD')));
        $this->givenWallet($recipient, 'NGN');

        $result = $manager->transfer(new TransferData(
            fromHolder: $sender,
            toHolder: $recipient,
            amount: Money::fromDecimal('10.00', 'USD'),
        ));

        // Sender debited in USD; recipient credited the converted NGN amount.
        $this->assertSame(99000, $sender->wallet('default', 'USD')->fresh()->balance);
        $this->assertSame(1500000, $recipient->wallet('default', 'NGN')->fresh()->balance);
        $this->assertSame('1500.00', $result['transfer']->exchange_rate);
        $this->assertSame(1500000, $result['transfer']->converted_amount);
        $this->assertSame(1500000, $result['credit_transaction']->amount);
        $this->assertSame(1000, $result['debit_transaction']->amount);
    }

    public function test_recipient_currency_disambiguates_a_multi_currency_recipient(): void
    {
        $this->bindFakeExchangeRateProvider('1500.00');

        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('1000.00', 'USD')));
        $this->givenWallet($recipient, 'NGN');
        $this->givenWallet($recipient, 'EUR');

        $result = $manager->transfer(new TransferData(
            fromHolder: $sender,
            toHolder: $recipient,
            amount: Money::fromDecimal('10.00', 'USD'),
            recipientCurrency: 'NGN',
        ));

        $this->assertSame(1500000, $recipient->wallet('default', 'NGN')->fresh()->balance);
        $this->assertSame(0, $recipient->wallet('default', 'EUR')->fresh()->balance);
    }

    public function test_it_rejects_an_ambiguous_recipient_without_recipient_currency(): void
    {
        $this->bindFakeExchangeRateProvider('1500.00');

        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('1000.00', 'USD')));
        $this->givenWallet($recipient, 'NGN');
        $this->givenWallet($recipient, 'EUR');

        $this->expectException(CurrencyMismatchException::class);

        $manager->transfer(new TransferData(
            fromHolder: $sender,
            toHolder: $recipient,
            amount: Money::fromDecimal('10.00', 'USD'),
        ));
    }

    public function test_disabling_exchange_restores_the_strict_currency_match_behaviour(): void
    {
        $fake = $this->bindFakeExchangeRateProvider('1500.00');
        config(['wallet.exchange.enabled' => false]);

        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('1000.00', 'USD')));
        $this->givenWallet($recipient, 'NGN');

        $this->expectException(CurrencyMismatchException::class);

        try {
            $manager->transfer(new TransferData(
                fromHolder: $sender,
                toHolder: $recipient,
                amount: Money::fromDecimal('10.00', 'USD'),
            ));
        } finally {
            $this->assertSame(0, $fake->calls);
        }
    }

    public function test_the_rate_provider_is_never_invoked_for_a_same_currency_transfer(): void
    {
        $fake = $this->bindFakeExchangeRateProvider('1500.00');

        $manager = $this->app->make(WalletManager::class);
        $sender = TestUser::create(['name' => 'Alice']);
        $recipient = TestUser::create(['name' => 'Bob']);
        $manager->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('1000.00', 'NGN')));
        $this->givenWallet($recipient, 'NGN');

        $result = $manager->transfer(new TransferData(
            fromHolder: $sender,
            toHolder: $recipient,
            amount: Money::fromDecimal('300.00', 'NGN'),
        ));

        $this->assertSame(0, $fake->calls);
        $this->assertNull($result['transfer']->exchange_rate);
        $this->assertNull($result['transfer']->converted_amount);
    }
}
