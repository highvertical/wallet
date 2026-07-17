<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

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
        private LimitEnforcer $limitEnforcer
    ) {
    }

    public function handle(DepositData $data): WalletTransaction
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

        $existing = WalletTransaction::query()->where('reference', $reference)->first();

        if ($existing !== null) {
            return $existing;
        }

        $transaction = $this->locker->lock($wallet->getKey(), function (Wallet $wallet) use ($data, $reference) {
            if ($wallet->status !== WalletStatus::ACTIVE) {
                throw new WalletNotUsableException('This wallet is not currently usable.');
            }

            $this->limitEnforcer->assertWithinLimit($wallet->getKey(), $data->amount, WalletOperation::DEPOSIT);

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore + $data->amount->minorUnits();

            if ($wallet->max_balance !== null && $balanceAfter > $wallet->max_balance) {
                throw new InvalidAmountException("This deposit would exceed the wallet's maximum balance.");
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
            $transaction->balance_after = $balanceAfter;
            $transaction->initiated_by = $data->initiatedBy;
            $transaction->initiated_ip = $data->initiatedIp;

            try {
                $transaction->save();
            } catch (QueryException $exception) {
                $existing = WalletTransaction::query()->where('reference', $reference)->first();

                if ($existing !== null) {
                    return $existing;
                }

                throw $exception;
            }

            $wallet->balance = $balanceAfter;
            $wallet->save();

            return $transaction;
        });

        event(new WalletCredited($transaction));

        return $transaction;
    }
}
