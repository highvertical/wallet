<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Events\WalletBalanceReconciled;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;

final class ReconcileWalletLedgerAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    /**
     * @return list<array{wallet_id: int, expected_balance: int, actual_balance: int, difference: int}>
     */
    public function handle(?int $walletId = null, bool $fix = false): array
    {
        $mismatches = [];

        Wallet::query()
            ->when($walletId !== null, fn ($query) => $query->whereKey($walletId))
            ->each(function (Wallet $wallet) use ($fix, &$mismatches) {
                $expectedBalance = $this->expectedBalance($wallet->getKey());

                if ($expectedBalance === $wallet->balance) {
                    return;
                }

                $mismatches[] = [
                    'wallet_id' => $wallet->getKey(),
                    'expected_balance' => $expectedBalance,
                    'actual_balance' => $wallet->balance,
                    'difference' => $expectedBalance - $wallet->balance,
                ];

                if ($fix) {
                    $previousBalance = $wallet->balance;

                    $fixed = $this->locker->lock($wallet->getKey(), function (Wallet $locked) use ($expectedBalance) {
                        $locked->balance = $expectedBalance;
                        $locked->save();

                        return $locked;
                    });

                    event(new WalletBalanceReconciled($fixed, $previousBalance, $expectedBalance));
                }
            });

        return $mismatches;
    }

    /**
     * Every transaction counts toward the ledger sum regardless of its
     * status - a REVERSED transaction keeps its original amount/type and
     * the reversal itself is a brand-new offsetting row, never an exclusion.
     */
    private function expectedBalance(int $walletId): int
    {
        $credits = (int) WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('type', TransactionType::CREDIT)
            ->sum('amount');

        $debits = (int) WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('type', TransactionType::DEBIT)
            ->sum('amount');

        return $credits - $debits;
    }
}
