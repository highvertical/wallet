<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\FeeResolver;
use Highvertical\Wallet\Application\Services\LimitEnforcer;
use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Contracts\Walletable;
use Highvertical\Wallet\Domain\Contracts\WalletRepository;
use Highvertical\Wallet\Domain\Data\TransferData;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionStatus;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Enums\WalletOperation;
use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Highvertical\Wallet\Domain\Exceptions\CurrencyMismatchException;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Events\LowBalanceDetected;
use Highvertical\Wallet\Events\WalletTransferred;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Highvertical\Wallet\Infrastructure\Models\WalletTransfer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TransferFundsAction
{
    public function __construct(
        private WalletRepository $wallets,
        private WalletLocker $locker,
        private FeeResolver $feeResolver,
        private LimitEnforcer $limitEnforcer
    ) {
    }

    /**
     * @return array{transfer: WalletTransfer, debit_transaction: WalletTransaction, credit_transaction: WalletTransaction, fee_transaction: ?WalletTransaction}
     */
    public function handle(TransferData $data): array
    {
        foreach (['fromHolder' => $data->fromHolder, 'toHolder' => $data->toHolder] as $holder) {
            if (! $holder instanceof Walletable) {
                throw new InvalidArgumentException(sprintf(
                    '%s must implement %s to own a wallet.',
                    get_class($holder),
                    Walletable::class
                ));
            }
        }

        if (! $data->amount->isPositive()) {
            throw new InvalidAmountException('Transfer amount must be greater than zero.');
        }

        $fromWallet = $this->wallets->findOrCreate(
            $data->fromHolder->getMorphClass(),
            $data->fromHolder->getKey(),
            $data->walletName,
            $data->amount->currency()
        );

        $toWallet = $this->wallets->find(
            $data->toHolder->getMorphClass(),
            $data->toHolder->getKey(),
            $data->walletName,
            $data->amount->currency()
        );

        if ($toWallet === null) {
            throw new CurrencyMismatchException('Recipient does not have a wallet in this currency.');
        }

        if ($fromWallet->getKey() === $toWallet->getKey()) {
            throw new InvalidAmountException('Cannot transfer to the same wallet.');
        }

        $reference = $data->reference ?? (string) Str::uuid();
        $debitReference = $reference.'-debit';
        $creditReference = $reference.'-credit';
        $feeReference = $reference.'-fee';

        $existingDebit = WalletTransaction::query()->where('reference', $debitReference)->first();

        if ($existingDebit !== null) {
            $existingTransfer = WalletTransfer::query()->where('debit_transaction_id', $existingDebit->getKey())->first();

            if ($existingTransfer !== null) {
                return [
                    'transfer' => $existingTransfer,
                    'debit_transaction' => $existingDebit,
                    'credit_transaction' => $existingTransfer->creditTransaction,
                    'fee_transaction' => WalletTransaction::query()->where('reference', $feeReference)->first(),
                ];
            }
        }

        $result = $this->locker->lockPair(
            $fromWallet->getKey(),
            $toWallet->getKey(),
            function (Wallet $fromWallet, Wallet $toWallet) use ($data, $debitReference, $creditReference, $feeReference) {
                if ($fromWallet->status !== WalletStatus::ACTIVE || $toWallet->status !== WalletStatus::ACTIVE) {
                    throw new WalletNotUsableException('Both wallets must be active to transfer funds.');
                }

                if ($fromWallet->currency !== $toWallet->currency || $fromWallet->currency !== strtoupper($data->amount->currency())) {
                    throw new CurrencyMismatchException('Both wallets must share the transfer currency.');
                }

                $this->limitEnforcer->assertWithinLimit($fromWallet->getKey(), $data->amount, WalletOperation::TRANSFER);

                $fee = $this->feeResolver->resolve($data->amount, WalletOperation::TRANSFER);
                $totalDebitMinorUnits = $data->amount->minorUnits() + $fee->minorUnits();

                $heldMinorUnits = (int) WalletHold::query()
                    ->where('wallet_id', $fromWallet->getKey())
                    ->where('status', HoldStatus::ACTIVE)
                    ->sum('amount');

                $availableBalance = $fromWallet->balance - $heldMinorUnits;
                $minBalance = $fromWallet->min_balance ?? 0;

                if (($availableBalance - $totalDebitMinorUnits) < $minBalance) {
                    throw new InsufficientFundsException('Insufficient available balance to complete this transaction.');
                }

                if ($toWallet->max_balance !== null && ($toWallet->balance + $data->amount->minorUnits()) > $toWallet->max_balance) {
                    throw new InvalidAmountException("This transfer would exceed the recipient wallet's maximum balance.");
                }

                $fromBalanceBefore = $fromWallet->balance;
                $fromBalanceAfterAmount = $fromBalanceBefore - $data->amount->minorUnits();

                $debitTransaction = new WalletTransaction([
                    'wallet_id' => $fromWallet->getKey(),
                    'type' => TransactionType::DEBIT,
                    'category' => TransactionCategory::TRANSFER_OUT,
                    'amount' => $data->amount->minorUnits(),
                    'reference' => $debitReference,
                    'status' => TransactionStatus::COMPLETED,
                    'description' => $data->note,
                    'meta' => $data->meta,
                ]);
                $debitTransaction->balance_before = $fromBalanceBefore;
                $debitTransaction->balance_after = $fromBalanceAfterAmount;
                $debitTransaction->initiated_by = $data->initiatedBy;
                $debitTransaction->initiated_ip = $data->initiatedIp;

                try {
                    $debitTransaction->save();
                } catch (QueryException $exception) {
                    $existingDebit = WalletTransaction::query()->where('reference', $debitReference)->first();
                    $existingTransfer = $existingDebit === null
                        ? null
                        : WalletTransfer::query()->where('debit_transaction_id', $existingDebit->getKey())->first();

                    if ($existingTransfer !== null) {
                        return [
                            'transfer' => $existingTransfer,
                            'debit_transaction' => $existingDebit,
                            'credit_transaction' => $existingTransfer->creditTransaction,
                            'fee_transaction' => WalletTransaction::query()->where('reference', $feeReference)->first(),
                            'low_balance' => false,
                        ];
                    }

                    throw $exception;
                }

                $fromFinalBalance = $fromBalanceAfterAmount;
                $feeTransaction = null;

                if ($fee->isPositive()) {
                    $feeTransaction = new WalletTransaction([
                        'wallet_id' => $fromWallet->getKey(),
                        'type' => TransactionType::DEBIT,
                        'category' => TransactionCategory::FEE,
                        'amount' => $fee->minorUnits(),
                        'reference' => $feeReference,
                        'status' => TransactionStatus::COMPLETED,
                        'description' => 'Transfer fee',
                    ]);
                    $fromFinalBalance = $fromBalanceAfterAmount - $fee->minorUnits();
                    $feeTransaction->balance_before = $fromBalanceAfterAmount;
                    $feeTransaction->balance_after = $fromFinalBalance;
                    $feeTransaction->initiated_by = $data->initiatedBy;
                    $feeTransaction->initiated_ip = $data->initiatedIp;
                    $feeTransaction->save();
                }

                $fromWallet->balance = $fromFinalBalance;

                $lowBalance = false;

                if ($fromWallet->max_balance !== null) {
                    $threshold = intdiv($fromWallet->max_balance * (int) config('wallet.low_balance_threshold_percent', 10), 100);
                    $lowBalance = ! $fromWallet->low_balance_alert && $fromFinalBalance <= $threshold;
                    $fromWallet->low_balance_alert = $fromFinalBalance <= $threshold;
                }

                $fromWallet->save();

                $toBalanceBefore = $toWallet->balance;
                $toBalanceAfter = $toBalanceBefore + $data->amount->minorUnits();

                $creditTransaction = new WalletTransaction([
                    'wallet_id' => $toWallet->getKey(),
                    'type' => TransactionType::CREDIT,
                    'category' => TransactionCategory::TRANSFER_IN,
                    'amount' => $data->amount->minorUnits(),
                    'reference' => $creditReference,
                    'status' => TransactionStatus::COMPLETED,
                    'description' => $data->note,
                ]);
                $creditTransaction->balance_before = $toBalanceBefore;
                $creditTransaction->balance_after = $toBalanceAfter;
                $creditTransaction->save();

                $toWallet->balance = $toBalanceAfter;
                $toWallet->save();

                $transfer = new WalletTransfer([
                    'from_wallet_id' => $fromWallet->getKey(),
                    'to_wallet_id' => $toWallet->getKey(),
                    'debit_transaction_id' => $debitTransaction->getKey(),
                    'credit_transaction_id' => $creditTransaction->getKey(),
                    'amount' => $data->amount->minorUnits(),
                    'fee' => $fee->minorUnits(),
                    'status' => TransactionStatus::COMPLETED,
                    'note' => $data->note,
                ]);
                $transfer->save();

                return [
                    'transfer' => $transfer,
                    'debit_transaction' => $debitTransaction,
                    'credit_transaction' => $creditTransaction,
                    'fee_transaction' => $feeTransaction,
                    'low_balance' => $lowBalance,
                    'wallet' => $fromWallet,
                ];
            }
        );

        event(new WalletTransferred($result['transfer']));

        if ($result['low_balance'] ?? false) {
            event(new LowBalanceDetected($result['wallet']));
        }

        return [
            'transfer' => $result['transfer'],
            'debit_transaction' => $result['debit_transaction'],
            'credit_transaction' => $result['credit_transaction'],
            'fee_transaction' => $result['fee_transaction'],
        ];
    }
}
