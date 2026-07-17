<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Listeners;

use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\LowBalanceDetected;
use Highvertical\Wallet\Events\TransactionReversed;
use Highvertical\Wallet\Events\WalletBalanceReconciled;
use Highvertical\Wallet\Events\WalletCredited;
use Highvertical\Wallet\Events\WalletDebited;
use Highvertical\Wallet\Events\WalletFrozen;
use Highvertical\Wallet\Events\WalletHoldCaptured;
use Highvertical\Wallet\Events\WalletHoldExpired;
use Highvertical\Wallet\Events\WalletHoldPlaced;
use Highvertical\Wallet\Events\WalletHoldReleased;
use Highvertical\Wallet\Events\WalletTransferred;
use Highvertical\Wallet\Events\WalletUnfrozen;
use Highvertical\Wallet\Notifications\WalletActivityNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registered against every wallet domain event (see WalletServiceProvider),
 * gated internally by wallet.notifications.enabled so a host app can flip
 * one config flag instead of touching the provider's listener map.
 */
final class SendTransactionNotification implements ShouldQueue
{
    public int $tries = 3;

    public function handle(object $event): void
    {
        if (! config('wallet.notifications.enabled')) {
            return;
        }

        $action = $this->action($event);
        $data = $this->data($event);

        foreach ($this->recipients($event) as $recipient) {
            if (method_exists($recipient, 'notify')) {
                $recipient->notify(new WalletActivityNotification($action, $data));
            }
        }
    }

    /**
     * A failed notification is a delivery-channel problem, not a data-loss
     * one (the ledger already recorded the transaction), so this only needs
     * to be observable rather than retried indefinitely.
     */
    public function failed(object $event, Throwable $exception): void
    {
        Log::error('wallet.notification_failed', [
            'action' => $this->action($event),
            'exception' => $exception->getMessage(),
        ]);
    }

    private function action(object $event): string
    {
        return match (true) {
            $event instanceof WalletCredited => 'credited',
            $event instanceof WalletDebited => 'debited',
            $event instanceof WalletTransferred => 'transferred',
            $event instanceof WalletFrozen => 'frozen',
            $event instanceof WalletUnfrozen => 'unfrozen',
            $event instanceof LowBalanceDetected => 'low_balance_detected',
            $event instanceof WalletHoldPlaced => 'hold_placed',
            $event instanceof WalletHoldReleased => 'hold_released',
            $event instanceof WalletHoldCaptured => 'hold_captured',
            $event instanceof WalletHoldExpired => 'hold_expired',
            $event instanceof TransactionReversed => 'transaction_reversed',
            $event instanceof WalletBalanceReconciled => 'balance_reconciled',
            default => 'unknown',
        };
    }

    private function data(object $event): array
    {
        return match (true) {
            $event instanceof WalletCredited, $event instanceof WalletDebited => $this->amountData(
                $event->transaction->amount,
                $event->transaction->wallet->currency
            ),
            $event instanceof WalletTransferred => $this->amountData(
                $event->transfer->amount,
                $event->transfer->fromWallet->currency
            ),
            $event instanceof WalletHoldPlaced, $event instanceof WalletHoldReleased, $event instanceof WalletHoldExpired => $this->amountData(
                $event->hold->amount,
                $event->hold->wallet->currency
            ),
            $event instanceof WalletHoldCaptured => $this->amountData(
                $event->transaction->amount,
                $event->transaction->wallet->currency
            ),
            $event instanceof TransactionReversed => $this->amountData(
                $event->reversal->amount,
                $event->reversal->wallet->currency
            ),
            $event instanceof WalletBalanceReconciled => $this->amountData(
                $event->newBalance,
                $event->wallet->currency
            ),
            default => [],
        };
    }

    /**
     * @return list<Model>
     */
    private function recipients(object $event): array
    {
        $holders = match (true) {
            $event instanceof WalletCredited, $event instanceof WalletDebited => [$event->transaction->wallet->holder],
            $event instanceof WalletTransferred => [$event->transfer->fromWallet->holder, $event->transfer->toWallet->holder],
            $event instanceof WalletFrozen, $event instanceof WalletUnfrozen, $event instanceof LowBalanceDetected => [$event->wallet->holder],
            $event instanceof WalletHoldPlaced, $event instanceof WalletHoldReleased, $event instanceof WalletHoldExpired => [$event->hold->wallet->holder],
            $event instanceof WalletHoldCaptured => [$event->hold->wallet->holder],
            $event instanceof TransactionReversed => [$event->original->wallet->holder],
            $event instanceof WalletBalanceReconciled => [$event->wallet->holder],
            default => [],
        };

        return array_values(array_filter($holders));
    }

    private function amountData(int $minorUnits, string $currency): array
    {
        return [
            'amount' => Money::fromMinorUnits($minorUnits, $currency)->toDecimal(),
            'currency' => $currency,
        ];
    }
}
