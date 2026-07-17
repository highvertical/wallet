<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionStatus;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletHoldCaptured;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class CaptureHoldAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    /**
     * @return array{hold: WalletHold, transaction: WalletTransaction}
     */
    public function handle(int $holdId, ?Money $amount = null): array
    {
        $existingHold = WalletHold::query()->find($holdId);

        if ($existingHold === null) {
            throw new ModelNotFoundException(sprintf('No hold found with id "%d".', $holdId));
        }

        $result = $this->locker->lock($existingHold->wallet_id, function (Wallet $wallet) use ($holdId, $amount) {
            $hold = WalletHold::query()->lockForUpdate()->findOrFail($holdId);

            if ($hold->status !== HoldStatus::ACTIVE) {
                throw new InvalidAmountException('This hold is no longer active.');
            }

            $captureMinorUnits = $amount?->minorUnits() ?? $hold->amount;

            if ($captureMinorUnits <= 0 || $captureMinorUnits > $hold->amount) {
                throw new InvalidAmountException('Capture amount must be positive and cannot exceed the held amount.');
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = $balanceBefore - $captureMinorUnits;

            $transaction = new WalletTransaction([
                'wallet_id' => $wallet->getKey(),
                'type' => TransactionType::DEBIT,
                'category' => TransactionCategory::HOLD_CAPTURE,
                'amount' => $captureMinorUnits,
                'reference' => $hold->uuid.'-capture',
                'status' => TransactionStatus::COMPLETED,
                'description' => $hold->reason,
            ]);
            $transaction->balance_before = $balanceBefore;
            $transaction->balance_after = $balanceAfter;
            $transaction->save();

            $wallet->balance = $balanceAfter;
            $wallet->save();

            $hold->status = HoldStatus::CAPTURED;
            $hold->capture_transaction_id = $transaction->getKey();
            $hold->save();

            return ['hold' => $hold, 'transaction' => $transaction];
        });

        event(new WalletHoldCaptured($result['hold'], $result['transaction']));

        return $result;
    }
}
