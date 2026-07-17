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

        $result = $this->locker->lock($hold->wallet_id, function (Wallet $wallet) use ($holdId) {
            $hold = WalletHold::query()->lockForUpdate()->findOrFail($holdId);

            // Releasing is a no-op, not a conflict, on retry - it's the
            // terminal state matching what the caller asked for. Only a
            // hold that was captured or expired in the meantime is a
            // genuine invalid transition.
            if ($hold->status === HoldStatus::RELEASED) {
                return ['hold' => $hold, 'released' => false];
            }

            if ($hold->status !== HoldStatus::ACTIVE) {
                throw new InvalidAmountException('This hold is no longer active.');
            }

            $hold->status = HoldStatus::RELEASED;
            $hold->released_at = Carbon::now();
            $hold->save();

            return ['hold' => $hold, 'released' => true];
        });

        if ($result['released']) {
            event(new WalletHoldReleased($result['hold']));
        }

        return $result['hold'];
    }
}
