<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletFrozen;
use Highvertical\Wallet\Events\WalletUnfrozen;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class FreezeUnfreezeActionTest extends TestCase
{
    public function test_freezing_sets_status_and_metadata(): void
    {
        Event::fake([WalletFrozen::class]);

        $manager = $this->app->make(WalletManager::class);
        $user = TestUser::create(['name' => 'Alice']);
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));
        $wallet = Wallet::query()->firstOrFail();

        $frozen = $manager->freeze($wallet->getKey(), 'suspected fraud', 42);

        $this->assertSame(WalletStatus::FROZEN, $frozen->status);
        $this->assertSame('suspected fraud', $frozen->frozen_reason);
        $this->assertSame(42, $frozen->frozen_by);
        $this->assertNotNull($frozen->frozen_at);
        Event::assertDispatched(WalletFrozen::class);
    }

    public function test_unfreezing_clears_status_and_metadata(): void
    {
        Event::fake([WalletUnfrozen::class]);

        $manager = $this->app->make(WalletManager::class);
        $user = TestUser::create(['name' => 'Alice']);
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));
        $wallet = Wallet::query()->firstOrFail();
        $manager->freeze($wallet->getKey(), 'suspected fraud', 42);

        $unfrozen = $manager->unfreeze($wallet->getKey());

        $this->assertSame(WalletStatus::ACTIVE, $unfrozen->status);
        $this->assertNull($unfrozen->frozen_reason);
        $this->assertNull($unfrozen->frozen_at);
        $this->assertNull($unfrozen->frozen_by);
        Event::assertDispatched(WalletUnfrozen::class);
    }
}
