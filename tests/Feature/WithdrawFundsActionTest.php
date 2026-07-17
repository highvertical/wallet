<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Data\WithdrawData;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\LowBalanceDetected;
use Highvertical\Wallet\Events\WalletDebited;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class WithdrawFundsActionTest extends TestCase
{
    private function fundedWallet(TestUser $user, WalletManager $manager, string $amount = '1000.00'): Wallet
    {
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal($amount, 'NGN')));

        return Wallet::query()->firstOrFail();
    }

    public function test_it_debits_the_wallet(): void
    {
        Event::fake([WalletDebited::class]);

        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);
        $this->fundedWallet($user, $manager);

        $result = $manager->withdraw(new WithdrawData(holder: $user, amount: Money::fromDecimal('300.00', 'NGN')));

        $this->assertSame(TransactionType::DEBIT, $result['transaction']->type);
        $this->assertSame(TransactionCategory::WITHDRAWAL, $result['transaction']->category);
        $this->assertSame(70000, Wallet::query()->firstOrFail()->balance);
        Event::assertDispatched(WalletDebited::class);
    }

    public function test_withdrawal_of_zero_is_rejected(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);
        $this->fundedWallet($user, $manager);

        $this->expectException(InvalidAmountException::class);

        $manager->withdraw(new WithdrawData(holder: $user, amount: Money::zero('NGN')));
    }

    public function test_withdrawal_beyond_available_balance_is_rejected(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);
        $this->fundedWallet($user, $manager, '100.00');

        $this->expectException(InsufficientFundsException::class);

        $manager->withdraw(new WithdrawData(holder: $user, amount: Money::fromDecimal('200.00', 'NGN')));
    }

    public function test_withdrawal_beyond_min_balance_floor_is_rejected(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($user, $manager, '100.00');
        $wallet->update(['min_balance' => 5000]);

        $this->expectException(InsufficientFundsException::class);

        $manager->withdraw(new WithdrawData(holder: $user, amount: Money::fromDecimal('80.00', 'NGN')));
    }

    public function test_active_holds_reduce_available_balance(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($user, $manager, '100.00');

        $manager->placeHold($wallet->getKey(), Money::fromDecimal('60.00', 'NGN'), 'reserved');

        $this->expectException(InsufficientFundsException::class);

        $manager->withdraw(new WithdrawData(holder: $user, amount: Money::fromDecimal('50.00', 'NGN')));
    }

    public function test_withdrawal_with_the_same_reference_is_idempotent(): void
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);
        $this->fundedWallet($user, $manager, '1000.00');

        $first = $manager->withdraw(new WithdrawData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN'), reference: 'wd-fixed'));
        $second = $manager->withdraw(new WithdrawData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN'), reference: 'wd-fixed'));

        $this->assertSame($first['transaction']->getKey(), $second['transaction']->getKey());
        $this->assertSame(90000, Wallet::query()->firstOrFail()->balance);
    }

    public function test_low_balance_detected_fires_once_threshold_crossed(): void
    {
        Event::fake([LowBalanceDetected::class]);

        $user = TestUser::create(['name' => 'Alice']);
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($user, $manager, '1000.00');
        $wallet->update(['max_balance' => 100000]);

        // Balance 100000, threshold 10% = 10000. Withdraw down to 5000 -> below threshold.
        $manager->withdraw(new WithdrawData(holder: $user, amount: Money::fromDecimal('950.00', 'NGN')));

        Event::assertDispatched(LowBalanceDetected::class);
    }
}
