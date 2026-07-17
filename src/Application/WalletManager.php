<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application;

use Highvertical\Wallet\Application\Actions\AdjustBalanceAction;
use Highvertical\Wallet\Application\Actions\CaptureHoldAction;
use Highvertical\Wallet\Application\Actions\DepositFundsAction;
use Highvertical\Wallet\Application\Actions\FreezeWalletAction;
use Highvertical\Wallet\Application\Actions\PlaceHoldAction;
use Highvertical\Wallet\Application\Actions\ReleaseHoldAction;
use Highvertical\Wallet\Application\Actions\ReverseTransactionAction;
use Highvertical\Wallet\Application\Actions\TransferFundsAction;
use Highvertical\Wallet\Application\Actions\UnfreezeWalletAction;
use Highvertical\Wallet\Application\Actions\WithdrawFundsAction;
use Highvertical\Wallet\Domain\Data\AdjustmentData;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Data\TransferData;
use Highvertical\Wallet\Domain\Data\WithdrawData;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;

/**
 * The primary DX entrypoint host apps use. Contains no logic itself, only
 * delegation - every rule lives in the Action it calls (01-ARCHITECTURE.md SS4).
 */
final class WalletManager
{
    public function __construct(
        private DepositFundsAction $depositFunds,
        private WithdrawFundsAction $withdrawFunds,
        private TransferFundsAction $transferFunds,
        private PlaceHoldAction $placeHold,
        private ReleaseHoldAction $releaseHold,
        private CaptureHoldAction $captureHold,
        private ReverseTransactionAction $reverseTransaction,
        private FreezeWalletAction $freezeWallet,
        private UnfreezeWalletAction $unfreezeWallet,
        private AdjustBalanceAction $adjustBalance
    ) {
    }

    public function deposit(DepositData $data): WalletTransaction
    {
        return $this->depositFunds->handle($data);
    }

    /**
     * @return array{transaction: WalletTransaction, fee_transaction: ?WalletTransaction}
     */
    public function withdraw(WithdrawData $data): array
    {
        return $this->withdrawFunds->handle($data);
    }

    /**
     * @return array{transfer: \Highvertical\Wallet\Infrastructure\Models\WalletTransfer, debit_transaction: WalletTransaction, credit_transaction: WalletTransaction, fee_transaction: ?WalletTransaction}
     */
    public function transfer(TransferData $data): array
    {
        return $this->transferFunds->handle($data);
    }

    public function placeHold(
        int $walletId,
        Money $amount,
        string $reason,
        ?Model $subject = null,
        ?int $expiresAfterHours = null
    ): WalletHold {
        return $this->placeHold->handle($walletId, $amount, $reason, $subject, $expiresAfterHours);
    }

    public function releaseHold(int $holdId): WalletHold
    {
        return $this->releaseHold->handle($holdId);
    }

    /**
     * @return array{hold: WalletHold, transaction: WalletTransaction}
     */
    public function captureHold(int $holdId, ?Money $amount = null): array
    {
        return $this->captureHold->handle($holdId, $amount);
    }

    public function reverseTransaction(int $transactionId, string $reason, ?int $reversedBy = null): WalletTransaction
    {
        return $this->reverseTransaction->handle($transactionId, $reason, $reversedBy);
    }

    public function freeze(int $walletId, string $reason, int $frozenBy): Wallet
    {
        return $this->freezeWallet->handle($walletId, $reason, $frozenBy);
    }

    public function unfreeze(int $walletId): Wallet
    {
        return $this->unfreezeWallet->handle($walletId);
    }

    public function adjustBalance(AdjustmentData $data): WalletTransaction
    {
        return $this->adjustBalance->handle($data);
    }
}
