<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletHoldExpired;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Carbon;

final class HoldExpiryTest extends TestCase
{
    private function fundedWallet(WalletManager $manager, string $amount = '1000.00'): Wallet
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal($amount, 'NGN')));

        return Wallet::query()->firstOrFail();
    }

    public function test_it_expires_holds_past_their_ttl_and_frees_the_balance(): void
    {
        Event::fake([WalletHoldExpired::class]);

        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order', expiresAfterHours: 1);

        $hold->expires_at = Carbon::now()->subMinute();
        $hold->save();

        $expiredCount = $manager->expireHolds();

        $this->assertSame(1, $expiredCount);
        $this->assertSame(HoldStatus::EXPIRED, $hold->fresh()->status);
        $this->assertNotNull($hold->fresh()->released_at);
        Event::assertDispatched(WalletHoldExpired::class);

        $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'another order');
    }

    public function test_it_leaves_active_unexpired_holds_alone(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order', expiresAfterHours: 72);

        $expiredCount = $manager->expireHolds();

        $this->assertSame(0, $expiredCount);
        $this->assertSame(HoldStatus::ACTIVE, $hold->fresh()->status);
    }

    public function test_it_leaves_holds_without_an_expiry_alone(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order');

        $hold->expires_at = null;
        $hold->save();

        $expiredCount = $manager->expireHolds();

        $this->assertSame(0, $expiredCount);
        $this->assertSame(HoldStatus::ACTIVE, $hold->fresh()->status);
    }

    public function test_it_does_not_expire_holds_already_released_or_captured(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order', expiresAfterHours: 1);
        $manager->releaseHold($hold->getKey());

        WalletHold::query()->whereKey($hold->getKey())->update(['expires_at' => Carbon::now()->subMinute()]);

        $expiredCount = $manager->expireHolds();

        $this->assertSame(0, $expiredCount);
        $this->assertSame(HoldStatus::RELEASED, $hold->fresh()->status);
    }

    public function test_the_console_command_expires_holds_and_reports_the_count(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');
        $hold = $manager->placeHold($wallet->getKey(), Money::fromDecimal('300.00', 'NGN'), 'pending order', expiresAfterHours: 1);

        WalletHold::query()->whereKey($hold->getKey())->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->artisan('wallet:expire-holds')
            ->expectsOutput('Expired 1 hold(s).')
            ->assertExitCode(0);

        $this->assertSame(HoldStatus::EXPIRED, $hold->fresh()->status);
    }
}
