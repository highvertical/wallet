<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\FeeResolver;
use Highvertical\Wallet\Application\Services\LimitEnforcer;
use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Contracts\Walletable;
use Highvertical\Wallet\Domain\Contracts\WalletRepository;
use Highvertical\Wallet\Domain\Data\WithdrawData;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionStatus;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Enums\WalletOperation;
use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Events\LowBalanceDetected;
use Highvertical\Wallet\Events\WalletDebited;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WithdrawFundsAction
{
    public function __construct(
        private WalletRepository $wallets,
        private WalletLocker $locker,
        private FeeResolver $feeResolver,
        private LimitEnforcer $limitEnforcer
    ) {
    }

    /**
     * @return array{transaction: WalletTransaction, fee_transaction: ?WalletTransaction}
     */
    public function handle(WithdrawData $data): array
    {
        if (! $data->holder instanceof Walletable) {
            throw new InvalidArgumentException(sprintf(
                '%s must implement %s to own a wallet.',
                get_class($data->holder),
                Walletable::class
            ));
        }

        if (! $data->amount->isPositive()) {
            throw new InvalidAmountException('Withdrawal amount must be greater than zero.');
        }

        $wallet = $this->wallets->findOrCreate(
            $data->holder->getMorphClass(),
            $data->holder->getKey(),
            $data->walletName,
            $data->amount->currency()
        );

        $reference = $data->reference ?? (string) Str::uuid();
        $feeReference = $reference.'-fee';

        $existing = WalletTransaction::query()->where('reference', $reference)->first();

        if ($existing !== null) {
            return [
                'transaction' => $existing,
                'fee_transaction' => WalletTransaction::query()->where('reference', $feeReference)->first(),
            ];
        }

        $result = $this->locker->lock($wallet->getKey(), function (Wallet $wallet) use ($data, $reference, $feeReference) {
            if ($wallet->status !== WalletStatus::ACTIVE) {
                throw new WalletNotUsableException('This wallet is not currently usable.');
            }

            $this->limitEnforcer->assertWithinLimit($wallet->getKey(), $data->amount, WalletOperation::WITHDRAWAL);

            $fee = $this->feeResolver->resolve($data->amount, WalletOperation::WITHDRAWAL);
            $totalMinorUnits = $data->amount->minorUnits() + $fee->minorUnits();

            $heldMinorUnits = (int) WalletHold::query()
                ->where('wallet_id', $wallet->getKey())
                ->where('status', HoldStatus::ACTIVE)
                ->sum('amount');

            $availableBalance = $wallet->balance - $heldMinorUnits;
            $minBalance = $wallet->min_balance ?? 0;

            if (($availableBalance - $totalMinorUnits) < $minBalance) {
                throw new InsufficientFundsException('Insufficient available balance to complete this transaction.');
            }

            $balanceBeforeAmount = $wallet->balance;
            $balanceAfterAmount = $balanceBeforeAmount - $data->amount->minorUnits();

            $transaction = new WalletTransaction([
                'wallet_id' => $wallet->getKey(),
                'type' => TransactionType::DEBIT,
                'category' => TransactionCategory::WITHDRAWAL,
                'amount' => $data->amount->minorUnits(),
                'reference' => $reference,
                'status' => TransactionStatus::COMPLETED,
                'description' => $data->description,
                'meta' => $data->meta,
            ]);
            $transaction->balance_before = $balanceBeforeAmount;
            $transaction->balance_after = $balanceAfterAmount;
            $transaction->initiated_by = $data->initiatedBy;
            $transaction->initiated_ip = $data->initiatedIp;

            try {
                $transaction->save();
            } catch (QueryException $exception) {
                $existing = WalletTransaction::query()->where('reference', $reference)->first();

                if ($existing !== null) {
                    return [
                        'transaction' => $existing,
                        'fee_transaction' => WalletTransaction::query()->where('reference', $feeReference)->first(),
                        'low_balance' => false,
                    ];
                }

                throw $exception;
            }

            $finalBalance = $balanceAfterAmount;
            $feeTransaction = null;

            if ($fee->isPositive()) {
                $feeTransaction = new WalletTransaction([
                    'wallet_id' => $wallet->getKey(),
                    'type' => TransactionType::DEBIT,
                    'category' => TransactionCategory::FEE,
                    'amount' => $fee->minorUnits(),
                    'reference' => $feeReference,
                    'status' => TransactionStatus::COMPLETED,
                    'description' => 'Withdrawal fee',
                ]);
                $finalBalance = $balanceAfterAmount - $fee->minorUnits();
                $feeTransaction->balance_before = $balanceAfterAmount;
                $feeTransaction->balance_after = $finalBalance;
                $feeTransaction->initiated_by = $data->initiatedBy;
                $feeTransaction->initiated_ip = $data->initiatedIp;
                $feeTransaction->save();
            }

            $wallet->balance = $finalBalance;

            $lowBalance = false;

            if ($wallet->max_balance !== null) {
                $threshold = (int) intdiv($wallet->max_balance * (int) config('wallet.low_balance_threshold_percent', 10), 100);
                $lowBalance = ! $wallet->low_balance_alert && $finalBalance <= $threshold;
                $wallet->low_balance_alert = $finalBalance <= $threshold;
            }

            $wallet->save();

            return [
                'transaction' => $transaction,
                'fee_transaction' => $feeTransaction,
                'low_balance' => $lowBalance,
                'wallet' => $wallet,
            ];
        });

        event(new WalletDebited($result['transaction']));

        if ($result['low_balance'] ?? false) {
            event(new LowBalanceDetected($result['wallet']));
        }

        return [
            'transaction' => $result['transaction'],
            'fee_transaction' => $result['fee_transaction'],
        ];
    }
}
