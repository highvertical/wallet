<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Data\AdjustmentData;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionStatus;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Events\WalletCredited;
use Highvertical\Wallet\Events\WalletDebited;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Illuminate\Support\Str;

final class AdjustBalanceAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    public function handle(AdjustmentData $data): WalletTransaction
    {
        if ($data->amount->isZero()) {
            throw new InvalidAmountException('Adjustment amount cannot be zero.');
        }

        $reference = $data->reference ?? (string) Str::uuid();

        $transaction = $this->locker->lock($data->walletId, function (Wallet $wallet) use ($data, $reference) {
            $isCredit = $data->amount->isPositive();
            $magnitude = $data->amount->abs()->minorUnits();

            if (! $isCredit) {
                $heldMinorUnits = (int) WalletHold::query()
                    ->where('wallet_id', $wallet->getKey())
                    ->where('status', HoldStatus::ACTIVE)
                    ->sum('amount');

                $availableBalance = $wallet->balance - $heldMinorUnits;
                $minBalance = $wallet->min_balance ?? 0;

                if (($availableBalance - $magnitude) < $minBalance) {
                    throw new InsufficientFundsException('Insufficient available balance for this adjustment.');
                }
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore + $data->amount->minorUnits();

            if ($isCredit && $wallet->max_balance !== null && $balanceAfter > $wallet->max_balance) {
                throw new InvalidAmountException("This adjustment would exceed the wallet's maximum balance.");
            }

            $transaction = new WalletTransaction([
                'wallet_id' => $wallet->getKey(),
                'type' => $isCredit ? TransactionType::CREDIT : TransactionType::DEBIT,
                'category' => TransactionCategory::ADJUSTMENT,
                'amount' => $magnitude,
                'reference' => $reference,
                'status' => TransactionStatus::COMPLETED,
                'description' => $data->reason,
                'meta' => array_merge($data->meta, ['admin_id' => $data->initiatedBy]),
            ]);
            $transaction->balance_before = $balanceBefore;
            $transaction->balance_after = $balanceAfter;
            $transaction->initiated_by = $data->initiatedBy;
            $transaction->initiated_ip = $data->initiatedIp;
            $transaction->save();

            $wallet->balance = $balanceAfter;
            $wallet->save();

            return $transaction;
        });

        event($transaction->type === TransactionType::CREDIT ? new WalletCredited($transaction) : new WalletDebited($transaction));

        return $transaction;
    }
}
