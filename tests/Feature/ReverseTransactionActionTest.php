<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Data\WithdrawData;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionStatus;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\TransactionReversed;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;

final class ReverseTransactionActionTest extends TestCase
{
    public function test_reversing_a_credit_debits_the_wallet_back(): void
    {
        Event::fake([TransactionReversed::class]);

        $manager = $this->app->make(WalletManager::class);
        $user = TestUser::create(['name' => 'Alice']);
        $deposit = $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('500.00', 'NGN')));

        $reversal = $manager->reverseTransaction($deposit->getKey(), 'chargeback', 1);

        $this->assertSame(TransactionType::DEBIT, $reversal->type);
        $this->assertSame(TransactionCategory::REVERSAL, $reversal->category);
        $this->assertSame(0, Wallet::query()->firstOrFail()->balance);
        $this->assertSame(TransactionStatus::REVERSED, $deposit->fresh()->status);
        Event::assertDispatched(TransactionReversed::class);
    }

    public function test_reversing_a_debit_credits_the_wallet_back(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $user = TestUser::create(['name' => 'Alice']);
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('1000.00', 'NGN')));
        $withdrawal = $manager->withdraw(new WithdrawData(holder: $user, amount: Money::fromDecimal('300.00', 'NGN')));

        $reversal = $manager->reverseTransaction($withdrawal['transaction']->getKey(), 'reverted', 1);

        $this->assertSame(TransactionType::CREDIT, $reversal->type);
        $this->assertSame(100000, Wallet::query()->firstOrFail()->balance);
    }

    public function test_reversing_an_already_reversed_transaction_is_rejected(): void
    {
        $manager = $this->app->make(WalletManager::class);
        $user = TestUser::create(['name' => 'Alice']);
        $deposit = $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('500.00', 'NGN')));
        $manager->reverseTransaction($deposit->getKey(), 'chargeback', 1);

        $this->expectException(InvalidAmountException::class);

        $manager->reverseTransaction($deposit->getKey(), 'chargeback again', 1);
    }

    public function test_reversing_an_unknown_transaction_throws_not_found(): void
    {
        $manager = $this->app->make(WalletManager::class);

        $this->expectException(ModelNotFoundException::class);

        $manager->reverseTransaction(999999, 'chargeback', 1);
    }
}
