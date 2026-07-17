<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionStatus;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Events\TransactionReversed;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ReverseTransactionAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    public function handle(int $transactionId, string $reason, ?int $reversedBy = null): WalletTransaction
    {
        $existing = WalletTransaction::query()->find($transactionId);

        if ($existing === null) {
            throw new ModelNotFoundException(sprintf('No transaction found with id "%d".', $transactionId));
        }

        $reversal = $this->locker->lock($existing->wallet_id, function (Wallet $wallet) use ($transactionId, $reason, $reversedBy) {
            $original = WalletTransaction::query()->lockForUpdate()->findOrFail($transactionId);

            if ($original->status === TransactionStatus::REVERSED) {
                throw new InvalidAmountException('This transaction has already been reversed.');
            }

            $isReversingCredit = $original->type === TransactionType::CREDIT;
            $reversalType = $isReversingCredit ? TransactionType::DEBIT : TransactionType::CREDIT;

            $balanceBefore = $wallet->balance;
            $balanceAfter = $isReversingCredit
                ? $balanceBefore - $original->amount
                : $balanceBefore + $original->amount;

            if ($isReversingCredit && $balanceAfter < ($wallet->min_balance ?? 0)) {
                throw new InsufficientFundsException('Insufficient available balance to reverse this transaction.');
            }

            $reversal = new WalletTransaction([
                'wallet_id' => $wallet->getKey(),
                'type' => $reversalType,
                'category' => TransactionCategory::REVERSAL,
                'amount' => $original->amount,
                'reference' => $original->reference.'-reversal',
                'status' => TransactionStatus::COMPLETED,
                'description' => $reason,
                'meta' => ['reversed_transaction_uuid' => $original->uuid],
            ]);
            $reversal->balance_before = $balanceBefore;
            $reversal->balance_after = $balanceAfter;
            $reversal->initiated_by = $reversedBy;
            $reversal->save();

            $original->status = TransactionStatus::REVERSED;
            $original->save();

            $wallet->balance = $balanceAfter;
            $wallet->save();

            return ['original' => $original, 'reversal' => $reversal];
        });

        event(new TransactionReversed($reversal['original'], $reversal['reversal']));

        return $reversal['reversal'];
    }
}
