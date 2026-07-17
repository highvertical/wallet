<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Events\WalletHoldReleased;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

final class ReleaseHoldAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    public function handle(int $holdId): WalletHold
    {
        $hold = WalletHold::query()->find($holdId);

        if ($hold === null) {
            throw new ModelNotFoundException(sprintf('No hold found with id "%d".', $holdId));
        }

        $released = $this->locker->lock($hold->wallet_id, function (Wallet $wallet) use ($holdId) {
            $hold = WalletHold::query()->lockForUpdate()->findOrFail($holdId);

            if ($hold->status !== HoldStatus::ACTIVE) {
                throw new InvalidAmountException('This hold is no longer active.');
            }

            $hold->status = HoldStatus::RELEASED;
            $hold->released_at = Carbon::now();
            $hold->save();

            return $hold;
        });

        event(new WalletHoldReleased($released));

        return $released;
    }
}
