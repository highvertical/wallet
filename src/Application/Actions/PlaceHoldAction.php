<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletHoldPlaced;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class PlaceHoldAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    public function handle(
        int $walletId,
        Money $amount,
        string $reason,
        ?Model $subject = null,
        ?int $expiresAfterHours = null
    ): WalletHold {
        if (! $amount->isPositive()) {
            throw new InvalidAmountException('Hold amount must be greater than zero.');
        }

        $hold = $this->locker->lock($walletId, function (Wallet $wallet) use ($amount, $reason, $subject, $expiresAfterHours) {
            if ($wallet->status !== WalletStatus::ACTIVE) {
                throw new WalletNotUsableException('This wallet is not currently usable.');
            }

            $heldMinorUnits = (int) WalletHold::query()
                ->where('wallet_id', $wallet->getKey())
                ->where('status', HoldStatus::ACTIVE)
                ->sum('amount');

            $availableBalance = $wallet->balance - $heldMinorUnits;
            $minBalance = $wallet->min_balance ?? 0;

            if (($availableBalance - $amount->minorUnits()) < $minBalance) {
                throw new InsufficientFundsException('Insufficient available balance to place this hold.');
            }

            $hold = new WalletHold([
                'wallet_id' => $wallet->getKey(),
                'amount' => $amount->minorUnits(),
                'reason' => $reason,
                'expires_at' => Carbon::now()->addHours(
                    $expiresAfterHours ?? (int) config('wallet.hold_default_ttl_hours', 72)
                ),
            ]);
            $hold->status = HoldStatus::ACTIVE;

            if ($subject !== null) {
                $hold->subject_type = $subject->getMorphClass();
                $hold->subject_id = $subject->getKey();
            }

            $hold->save();

            return $hold;
        });

        event(new WalletHoldPlaced($hold));

        return $hold;
    }
}
