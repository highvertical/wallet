<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Highvertical\Wallet\Domain\Exceptions\CurrencyMismatchException;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Events\WalletHoldPlaced;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
        ?int $expiresAfterHours = null,
        ?string $reference = null
    ): WalletHold {
        if (! $amount->isPositive()) {
            throw new InvalidAmountException('Hold amount must be greater than zero.');
        }

        $reference ??= (string) Str::uuid();

        $existing = WalletHold::query()->where('reference', $reference)->first();

        if ($existing !== null) {
            return $existing;
        }

        $hold = $this->locker->lock($walletId, function (Wallet $wallet) use ($amount, $reason, $subject, $expiresAfterHours, $reference) {
            if ($wallet->status !== WalletStatus::ACTIVE) {
                throw new WalletNotUsableException('This wallet is not currently usable.');
            }

            if ($wallet->currency !== strtoupper($amount->currency())) {
                throw new CurrencyMismatchException('The hold currency does not match the wallet currency.');
            }

            $heldMinorUnits = (int) WalletHold::query()
                ->where('wallet_id', $wallet->getKey())
                ->active()
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
                'reference' => $reference,
                'expires_at' => Carbon::now()->addHours(
                    $expiresAfterHours ?? (int) config('wallet.hold_default_ttl_hours', 72)
                ),
            ]);
            $hold->status = HoldStatus::ACTIVE;

            if ($subject !== null) {
                $hold->subject_type = $subject->getMorphClass();
                $hold->subject_id = $subject->getKey();
            }

            try {
                $hold->save();
            } catch (QueryException $exception) {
                $existing = WalletHold::query()->where('reference', $reference)->first();

                if ($existing !== null) {
                    return $existing;
                }

                throw $exception;
            }

            return $hold;
        });

        event(new WalletHoldPlaced($hold));

        return $hold;
    }
}
