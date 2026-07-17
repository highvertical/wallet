<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\FeeResolver;
use Highvertical\Wallet\Application\Services\LimitEnforcer;
use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Contracts\Walletable;
use Highvertical\Wallet\Domain\Contracts\WalletRepository;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionStatus;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Enums\WalletOperation;
use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Events\WalletCredited;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DepositFundsAction
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
    public function handle(DepositData $data): array
    {
        if (! $data->holder instanceof Walletable) {
            throw new InvalidArgumentException(sprintf(
                '%s must implement %s to own a wallet.',
                get_class($data->holder),
                Walletable::class
            ));
        }

        if (! $data->amount->isPositive()) {
            throw new InvalidAmountException('Deposit amount must be greater than zero.');
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

            $this->limitEnforcer->assertWithinLimit($wallet->getKey(), $data->amount, WalletOperation::DEPOSIT);

            $fee = $this->feeResolver->resolve($data->amount, WalletOperation::DEPOSIT);

            $balanceBefore = $wallet->balance;
            $balanceAfterAmount = $balanceBefore + $data->amount->minorUnits();
            $finalBalance = $balanceAfterAmount - $fee->minorUnits();

            if ($wallet->max_balance !== null && $balanceAfterAmount > $wallet->max_balance) {
                throw new InvalidAmountException("This deposit would exceed the wallet's maximum balance.");
            }

            if ($fee->isPositive() && $finalBalance < ($wallet->min_balance ?? 0)) {
                throw new InsufficientFundsException("This deposit's fee would drop the wallet below its minimum balance.");
            }

            $transaction = new WalletTransaction([
                'wallet_id' => $wallet->getKey(),
                'type' => TransactionType::CREDIT,
                'category' => TransactionCategory::DEPOSIT,
                'amount' => $data->amount->minorUnits(),
                'reference' => $reference,
                'status' => TransactionStatus::COMPLETED,
                'description' => $data->description,
                'meta' => $data->meta,
            ]);
            $transaction->balance_before = $balanceBefore;
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
                    ];
                }

                throw $exception;
            }

            $feeTransaction = null;

            if ($fee->isPositive()) {
                $feeTransaction = new WalletTransaction([
                    'wallet_id' => $wallet->getKey(),
                    'type' => TransactionType::DEBIT,
                    'category' => TransactionCategory::FEE,
                    'amount' => $fee->minorUnits(),
                    'reference' => $feeReference,
                    'status' => TransactionStatus::COMPLETED,
                    'description' => 'Deposit fee',
                ]);
                $feeTransaction->balance_before = $balanceAfterAmount;
                $feeTransaction->balance_after = $finalBalance;
                $feeTransaction->initiated_by = $data->initiatedBy;
                $feeTransaction->initiated_ip = $data->initiatedIp;
                $feeTransaction->save();
            }

            $wallet->balance = $finalBalance;
            $wallet->save();

            return [
                'transaction' => $transaction,
                'fee_transaction' => $feeTransaction,
            ];
        });

        event(new WalletCredited($result['transaction']));

        return $result;
    }
}
