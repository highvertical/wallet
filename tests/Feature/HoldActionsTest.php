<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\Exceptions\CurrencyMismatchException;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletHoldCaptured;
use Highvertical\Wallet\Events\WalletHoldPlaced;
use Highvertical\Wallet\Events\WalletHoldReleased;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;

final class HoldActionsTest extends TestCase
{
    private function fundedWallet(WalletManager $manager, string $amount = '1000.00'): Wallet
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal($amount, 'NGN')));

        return Wallet::query()->firstOrFail();
    }

    public function test_it_places_a_hold_without_moving_balance(): void
    {
        Event::fake([WalletHoldPlaced::class]);

        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');

        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');

        $this->assertSame(HoldStatus::ACTIVE, $hold->status);
        $this->assertSame(30000, $hold->amount);
        $this->assertSame(100000, $wallet->fresh()->balance);
        Event::assertDispatched(WalletHoldPlaced::class);
    }

    public function test_hold_beyond_available_balance_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '100.00');

        $this->expectException(InsufficientFundsException::class);

        $manager->placeHold($wallet->getKey(), Money::fromDecimal('200.00', 'NGN'), 'pending order');
    }

    public function test_hold_of_zero_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '100.00');

        $this->expectException(InvalidAmountException::class);

        $manager->placeHold($wallet->getKey(), Money::zero('NGN'), 'pending order');
    }

    public function test_placing_a_hold_with_the_same_reference_is_idempotent(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');

        $first = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order', null, null, 'hold-fixed-ref');
        $second = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order', null, null, 'hold-fixed-ref');

        $this->assertSame($first->getKey(), $second->getKey());
    }

    public function test_hold_is_rejected_on_a_frozen_wallet(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $manager->freeze($wallet->getKey(), 'suspicious activity', 1);

        $this->expectException(WalletNotUsableException::class);

        $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');
    }

    public function test_hold_with_a_currency_that_does_not_match_the_wallet_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');

        $this->expectException(CurrencyMismatchException::class);

        $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'USD'), 'pending order');
    }

    public function test_it_releases_an_active_hold(): void
    {
        Event::fake([WalletHoldReleased::class]);

        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');

        $released = $manager->releaseHold($hold->getKey());

        $this->assertSame(HoldStatus::RELEASED, $released->status);
        $this->assertNotNull($released->released_at);
        Event::assertDispatched(WalletHoldReleased::class);
    }

    public function test_releasing_an_already_released_hold_is_idempotent(): void
    {
        Event::fake([WalletHoldReleased::class]);

        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');
        $manager->releaseHold($hold->getKey());

        $replayed = $manager->releaseHold($hold->getKey());

        $this->assertSame(HoldStatus::RELEASED, $replayed->status);
        $this->assertSame($hold->getKey(), $replayed->getKey());
        Event::assertDispatchedTimes(WalletHoldReleased::class, 1);
    }

    public function test_releasing_a_captured_hold_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');
        $manager->captureHold($hold->getKey());

        $this->expectException(InvalidAmountException::class);

        $manager->releaseHold($hold->getKey());
    }

    public function test_releasing_an_unknown_hold_throws_not_found(): void
    {
        $manager = $this->app->make(WalletManager::class);

        $this->expectException(ModelNotFoundException::class);

        $manager->releaseHold(999999);
    }

    public function test_it_captures_the_full_held_amount_by_default(): void
    {
        Event::fake([WalletHoldCaptured::class]);

        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');

        $result = $manager->captureHold($hold->getKey());

        $this->assertSame(HoldStatus::CAPTURED, $result['hold']->status);
        $this->assertSame(30000, $result['transaction']->amount);
        $this->assertSame(70000, $wallet->fresh()->balance);
        Event::assertDispatched(WalletHoldCaptured::class);
    }

    public function test_it_captures_a_partial_amount(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');

        $result = $manager->captureHold($hold->getKey(), Money::fromDecimal('100.00', 'NGN'));

        $this->assertSame(10000, $result['transaction']->amount);
        $this->assertSame(90000, $wallet->fresh()->balance);
    }

    public function test_capturing_beyond_the_held_amount_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');

        $this->expectException(InvalidAmountException::class);

        $manager->captureHold($hold->getKey(), Money::fromDecimal('400.00', 'NGN'));
    }

    public function test_recapturing_the_same_amount_is_idempotent(): void
    {
        Event::fake([WalletHoldCaptured::class]);

        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');
        $first = $manager->captureHold($hold->getKey());

        $second = $manager->captureHold($hold->getKey());

        $this->assertSame($first['transaction']->getKey(), $second['transaction']->getKey());
        $this->assertSame(70000, $wallet->fresh()->balance);
        Event::assertDispatchedTimes(WalletHoldCaptured::class, 1);
    }

    public function test_capturing_with_a_currency_that_does_not_match_the_wallet_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');

        $this->expectException(CurrencyMismatchException::class);

        $manager->captureHold($hold->getKey(), Money::fromDecimal('100.00', 'USD'));
    }

    public function test_recapturing_with_a_different_amount_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');
        $manager->captureHold($hold->getKey(), Money::fromDecimal('300.00', 'NGN'));

        $this->expectException(InvalidAmountException::class);

        $manager->captureHold($hold->getKey(), Money::fromDecimal('100.00', 'NGN'));
    }
}
