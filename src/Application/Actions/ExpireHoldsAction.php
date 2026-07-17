<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Events\WalletHoldExpired;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Illuminate\Support\Carbon;

final class ExpireHoldsAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    public function handle(): int
    {
        $expiredCount = 0;

        WalletHold::query()
            ->where('status', HoldStatus::ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->select('id', 'wallet_id')
            ->each(function (WalletHold $candidate) use (&$expiredCount): void {
                $expired = $this->locker->lock($candidate->wallet_id, function (Wallet $wallet) use ($candidate) {
                    $hold = WalletHold::query()->lockForUpdate()->find($candidate->getKey());

                    if ($hold === null || $hold->status !== HoldStatus::ACTIVE || $hold->expires_at === null || $hold->expires_at->isAfter(Carbon::now())) {
                        return null;
                    }

                    $hold->status = HoldStatus::EXPIRED;
                    $hold->released_at = Carbon::now();
                    $hold->save();

                    return $hold;
                });

                if ($expired !== null) {
                    $expiredCount++;
                    event(new WalletHoldExpired($expired));
                }
            });

        return $expiredCount;
    }
}
