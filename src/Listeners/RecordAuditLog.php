<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Listeners;

use Highvertical\Wallet\Events\LowBalanceDetected;
use Highvertical\Wallet\Events\TransactionReversed;
use Highvertical\Wallet\Events\WalletCredited;
use Highvertical\Wallet\Events\WalletDebited;
use Highvertical\Wallet\Events\WalletFrozen;
use Highvertical\Wallet\Events\WalletHoldCaptured;
use Highvertical\Wallet\Events\WalletHoldPlaced;
use Highvertical\Wallet\Events\WalletHoldReleased;
use Highvertical\Wallet\Events\WalletTransferred;
use Highvertical\Wallet\Events\WalletUnfrozen;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Registered against every wallet domain event (see WalletServiceProvider).
 * The WalletTransaction ledger is already the audit trail for money
 * movement; this listener additionally covers events with no transaction
 * row of their own (freeze/unfreeze, holds, low-balance alerts) and gives
 * host apps a single log channel to pipe wallet activity into.
 */
final class RecordAuditLog implements ShouldQueue
{
    public function handle(object $event): void
    {
        Log::channel((string) config('wallet.audit_log_channel', 'stack'))
            ->info('wallet.'.$this->action($event), $this->context($event));
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
            $event instanceof TransactionReversed => 'transaction_reversed',
            default => 'unknown',
        };
    }

    private function context(object $event): array
    {
        return match (true) {
            $event instanceof WalletCredited, $event instanceof WalletDebited => [
                'wallet_id' => $event->transaction->wallet_id,
                'transaction_uuid' => $event->transaction->uuid,
                'type' => $event->transaction->type,
                'category' => $event->transaction->category,
                'amount' => $event->transaction->amount,
                'balance_before' => $event->transaction->balance_before,
                'balance_after' => $event->transaction->balance_after,
                'reference' => $event->transaction->reference,
                'initiated_by' => $event->transaction->initiated_by,
                'initiated_ip' => $event->transaction->initiated_ip,
            ],
            $event instanceof WalletTransferred => [
                'transfer_uuid' => $event->transfer->uuid,
                'from_wallet_id' => $event->transfer->from_wallet_id,
                'to_wallet_id' => $event->transfer->to_wallet_id,
                'amount' => $event->transfer->amount,
                'fee' => $event->transfer->fee,
                'debit_transaction_id' => $event->transfer->debit_transaction_id,
                'credit_transaction_id' => $event->transfer->credit_transaction_id,
            ],
            $event instanceof WalletFrozen, $event instanceof WalletUnfrozen => [
                'wallet_id' => $event->wallet->id,
                'status' => $event->wallet->status,
                'frozen_reason' => $event->wallet->frozen_reason,
                'frozen_by' => $event->wallet->frozen_by,
            ],
            $event instanceof LowBalanceDetected => [
                'wallet_id' => $event->wallet->id,
                'balance' => $event->wallet->balance,
                'max_balance' => $event->wallet->max_balance,
            ],
            $event instanceof WalletHoldPlaced, $event instanceof WalletHoldReleased => [
                'hold_uuid' => $event->hold->uuid,
                'wallet_id' => $event->hold->wallet_id,
                'amount' => $event->hold->amount,
                'reason' => $event->hold->reason,
                'status' => $event->hold->status,
            ],
            $event instanceof WalletHoldCaptured => [
                'hold_uuid' => $event->hold->uuid,
                'wallet_id' => $event->hold->wallet_id,
                'transaction_uuid' => $event->transaction->uuid,
                'amount' => $event->transaction->amount,
            ],
            $event instanceof TransactionReversed => [
                'wallet_id' => $event->original->wallet_id,
                'original_transaction_uuid' => $event->original->uuid,
                'reversal_transaction_uuid' => $event->reversal->uuid,
                'amount' => $event->reversal->amount,
            ],
            default => [],
        };
    }
}
