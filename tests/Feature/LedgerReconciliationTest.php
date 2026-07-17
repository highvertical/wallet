<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletBalanceReconciled;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Event;

final class LedgerReconciliationTest extends TestCase
{
    private function fundedWallet(WalletManager $manager, string $amount = '1000.00'): Wallet
    {
        $user = TestUser::create(['name' => 'Alice']);
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal($amount, 'NGN')));

        return Wallet::query()->firstOrFail();
    }

    public function test_it_reports_no_mismatches_for_a_correctly_balanced_wallet(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $this->fundedWallet($manager, '1000.00');

        $mismatches = $manager->reconcileLedger();

        $this->assertSame([], $mismatches);
    }

    public function test_it_detects_a_corrupted_balance_without_fixing_it_by_default(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');

        Wallet::query()->whereKey($wallet->getKey())->update(['balance' => 50000]);

        $mismatches = $manager->reconcileLedger();

        $this->assertCount(1, $mismatches);
        $this->assertSame($wallet->getKey(), $mismatches[0]['wallet_id']);
        $this->assertSame(100000, $mismatches[0]['expected_balance']);
        $this->assertSame(50000, $mismatches[0]['actual_balance']);
        $this->assertSame(50000, $mismatches[0]['difference']);
        $this->assertSame(50000, $wallet->fresh()->balance);
    }

    public function test_fix_corrects_the_balance_and_dispatches_an_event(): void
    {
        Event::fake([WalletBalanceReconciled::class]);

        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');

        Wallet::query()->whereKey($wallet->getKey())->update(['balance' => 50000]);

        $mismatches = $manager->reconcileLedger(fix: true);

        $this->assertCount(1, $mismatches);
        $this->assertSame(100000, $wallet->fresh()->balance);
        Event::assertDispatched(WalletBalanceReconciled::class, function (WalletBalanceReconciled $event) use ($wallet) {
            return $event->wallet->getKey() === $wallet->getKey()
                && $event->previousBalance === 50000
                && $event->newBalance === 100000;
        });
    }

    public function test_it_can_scope_reconciliation_to_a_single_wallet(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $walletA = $this->fundedWallet($manager, '1000.00');
        $walletB = $this->fundedWallet($manager, '1000.00');

        Wallet::query()->whereKey($walletA->getKey())->update(['balance' => 1]);
        Wallet::query()->whereKey($walletB->getKey())->update(['balance' => 1]);

        $mismatches = $manager->reconcileLedger(walletId: $walletB->getKey());

        $this->assertCount(1, $mismatches);
        $this->assertSame($walletB->getKey(), $mismatches[0]['wallet_id']);
    }

    public function test_the_console_command_reports_mismatches_with_a_non_zero_exit_code(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');

        Wallet::query()->whereKey($wallet->getKey())->update(['balance' => 50000]);

        $this->artisan('wallet:reconcile')
            ->assertExitCode(1);

        $this->assertSame(50000, $wallet->fresh()->balance);
    }

    public function test_the_console_command_fixes_mismatches_when_given_the_fix_flag(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $wallet = $this->fundedWallet($manager, '1000.00');

        Wallet::query()->whereKey($wallet->getKey())->update(['balance' => 50000]);

        $this->artisan('wallet:reconcile', ['--fix' => true])
            ->assertExitCode(0);

        $this->assertSame(100000, $wallet->fresh()->balance);
    }

    public function test_the_console_command_reports_success_when_nothing_is_out_of_balance(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $this->fundedWallet($manager, '1000.00');

        $this->artisan('wallet:reconcile')
            ->expectsOutput('All wallets are balanced.')
            ->assertExitCode(0);
    }
}
