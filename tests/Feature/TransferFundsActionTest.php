<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
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
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class TransferFundsActionTest extends TestCase
{
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
}
