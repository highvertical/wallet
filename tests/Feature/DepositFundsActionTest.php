<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletCredited;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class DepositFundsActionTest extends TestCase
{
    public function test_it_creates_a_wallet_on_first_deposit_and_credits_it(): void
    {
        Event::fake([WalletCredited::class]);

        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);

        $result = $manager->deposit(new DepositData(
            holder: $user,
            amount: Money::fromDecimal('1500.00', 'NGN'),
        ));
        $transaction = $result['transaction'];

        $wallet = Wallet::query()->firstOrFail();
        $this->assertSame(150000, $wallet->balance);
        $this->assertSame(TransactionType::CREDIT, $transaction->type);
        $this->assertSame(TransactionCategory::DEPOSIT, $transaction->category);
        $this->assertSame(150000, $transaction->amount);
        $this->assertSame(0, $transaction->balance_before);
        $this->assertSame(150000, $transaction->balance_after);

        Event::assertDispatched(WalletCredited::class);
    }

    public function test_deposit_of_zero_is_rejected(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);

        $this->expectException(InvalidAmountException::class);

        $manager->deposit(new DepositData(holder: $user, amount: Money::zero('NGN')));
    }

    public function test_deposit_is_rejected_when_it_would_exceed_max_balance(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);

        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));

        Wallet::query()->firstOrFail()->update(['max_balance' => 15000]);

        $this->expectException(InvalidAmountException::class);

        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));
    }

    public function test_deposit_is_rejected_on_a_frozen_wallet(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);

        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));
        $wallet = Wallet::query()->firstOrFail();
        $manager->freeze($wallet->getKey(), 'suspicious activity', 1);

        $this->expectException(WalletNotUsableException::class);

        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));
    }

    public function test_deposit_with_the_same_reference_is_idempotent(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);

        $first = $manager->deposit(new DepositData(
            holder: $user,
            amount: Money::fromDecimal('100.00', 'NGN'),
            reference: 'dep-fixed-ref',
        ));

        $second = $manager->deposit(new DepositData(
            holder: $user,
            amount: Money::fromDecimal('100.00', 'NGN'),
            reference: 'dep-fixed-ref',
        ));

        $this->assertSame($first['transaction']->getKey(), $second['transaction']->getKey());
        $this->assertSame(10000, Wallet::query()->firstOrFail()->balance);
    }

    public function test_a_configured_deposit_fee_is_charged_as_a_separate_transaction(): void
    {
        config(['wallet.fees.deposit' => ['type' => 'flat', 'value' => 100, 'min' => null, 'cap' => null]]);

        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);

        $result = $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));

        $this->assertSame(10000, $result['transaction']->amount);
        $this->assertNotNull($result['fee_transaction']);
        $this->assertSame(TransactionType::DEBIT, $result['fee_transaction']->type);
        $this->assertSame(TransactionCategory::FEE, $result['fee_transaction']->category);
        $this->assertSame(100, $result['fee_transaction']->amount);
        $this->assertSame(9900, Wallet::query()->firstOrFail()->balance);
    }

    public function test_no_fee_transaction_is_created_when_the_deposit_fee_is_zero(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);

        $result = $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));

        $this->assertNull($result['fee_transaction']);
        $this->assertSame(10000, Wallet::query()->firstOrFail()->balance);
    }

    public function test_deposit_is_rejected_when_the_fee_would_drop_the_wallet_below_its_minimum_balance(): void
    {
        config(['wallet.fees.deposit' => ['type' => 'flat', 'value' => 20000, 'min' => null, 'cap' => null]]);

        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);

        $this->expectException(InsufficientFundsException::class);

        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));
    }
}
