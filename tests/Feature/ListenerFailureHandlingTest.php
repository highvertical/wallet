<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletFrozen;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Listeners\RecordAuditLog;
use Highvertical\Wallet\Listeners\SendTransactionNotification;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ListenerFailureHandlingTest extends TestCase
{
    private function frozenWalletEvent(): WalletFrozen
    {
        $manager = $this->app->make(WalletManager::class);
        $user = TestUser::create(['name' => 'Alice']);
        $manager->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('100.00', 'NGN')));
        $wallet = Wallet::query()->firstOrFail();

        return new WalletFrozen($manager->freeze($wallet->getKey(), 'suspected fraud', 1));
    }

    public function test_record_audit_log_failed_handler_logs_the_exhausted_failure(): void
    {
        $event = $this->frozenWalletEvent();
        Log::spy();

        (new RecordAuditLog())->failed($event, new RuntimeException('log channel unreachable'));

        Log::shouldHaveReceived('error')->once()->with('wallet.audit_log_failed', \Mockery::on(
            fn (array $context) => $context['action'] === 'frozen' && $context['exception'] === 'log channel unreachable'
        ));
    }

    public function test_send_transaction_notification_failed_handler_logs_the_exhausted_failure(): void
    {
        $event = $this->frozenWalletEvent();
        Log::spy();

        (new SendTransactionNotification())->failed($event, new RuntimeException('mailer unreachable'));

        Log::shouldHaveReceived('error')->once()->with('wallet.notification_failed', \Mockery::on(
            fn (array $context) => $context['action'] === 'frozen' && $context['exception'] === 'mailer unreachable'
        ));
    }
}
