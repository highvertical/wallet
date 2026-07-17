<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\AdjustmentData;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Exceptions\CurrencyMismatchException;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletCredited;
use Highvertical\Wallet\Events\WalletDebited;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class AdjustBalanceActionTest extends TestCase
{
    private function fundedWallet(WalletManager $manager, string $amount = '1000.00'): Wallet
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal($amount, 'NGN')));

        return Wallet::query()->firstOrFail();
    }

    public function test_positive_adjustment_credits_the_wallet(): void
    {
        Event::fake([WalletCredited::class]);

        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '100.00');

        $transaction = $manager->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::fromDecimal('50.00', 'NGN'),
            reason: 'goodwill credit',
            initiatedBy: 7,
        ));

        $this->assertSame(TransactionType::CREDIT, $transaction->type);
        $this->assertSame(TransactionCategory::ADJUSTMENT, $transaction->category);
        $this->assertSame(7, $transaction->meta['admin_id']);
        $this->assertSame(15000, $wallet->fresh()->balance);
        Event::assertDispatched(WalletCredited::class);
    }

    public function test_negative_adjustment_debits_the_wallet(): void
    {
        Event::fake([WalletDebited::class]);

        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '100.00');

        $transaction = $manager->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::fromDecimal('-30.00', 'NGN'),
            reason: 'correction',
            initiatedBy: 7,
        ));

        $this->assertSame(TransactionType::DEBIT, $transaction->type);
        $this->assertSame(7000, $wallet->fresh()->balance);
    }

    public function test_zero_adjustment_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '100.00');

        $this->expectException(InvalidAmountException::class);

        $manager->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::zero('NGN'),
            reason: 'noop',
            initiatedBy: 7,
        ));
    }

    public function test_debit_adjustment_beyond_available_balance_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '50.00');

        $this->expectException(InsufficientFundsException::class);

        $manager->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::fromDecimal('-100.00', 'NGN'),
            reason: 'correction',
            initiatedBy: 7,
        ));
    }

    public function test_credit_adjustment_beyond_max_balance_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '100.00');
        $wallet->update(['max_balance' => 15000]);

        $this->expectException(InvalidAmountException::class);

        $manager->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::fromDecimal('100.00', 'NGN'),
            reason: 'goodwill credit',
            initiatedBy: 7,
        ));
    }

    public function test_adjustment_with_the_same_reference_is_idempotent(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '100.00');

        $first = $manager->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::fromDecimal('50.00', 'NGN'),
            reason: 'goodwill credit',
            initiatedBy: 7,
            reference: 'adj-fixed-ref',
        ));

        $second = $manager->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::fromDecimal('50.00', 'NGN'),
            reason: 'goodwill credit',
            initiatedBy: 7,
            reference: 'adj-fixed-ref',
        ));

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(15000, $wallet->fresh()->balance);
    }

    public function test_adjustment_is_rejected_on_a_frozen_wallet(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '100.00');
        $manager->freeze($wallet->getKey(), 'suspicious activity', 1);

        $this->expectException(WalletNotUsableException::class);

        $manager->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::fromDecimal('50.00', 'NGN'),
            reason: 'goodwill credit',
            initiatedBy: 7,
        ));
    }

    public function test_adjustment_with_a_currency_that_does_not_match_the_wallet_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '100.00');

        $this->expectException(CurrencyMismatchException::class);

        $manager->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::fromDecimal('50.00', 'USD'),
            reason: 'goodwill credit',
            initiatedBy: 7,
        ));
    }
}
