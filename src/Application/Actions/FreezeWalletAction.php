<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Highvertical\Wallet\Events\WalletFrozen;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Illuminate\Support\Carbon;

final class FreezeWalletAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    public function handle(int $walletId, string $reason, int $frozenBy): Wallet
    {
        $wallet = $this->locker->lock($walletId, function (Wallet $wallet) use ($reason, $frozenBy) {
            $wallet->status = WalletStatus::FROZEN;
            $wallet->frozen_reason = $reason;
            $wallet->frozen_at = Carbon::now();
            $wallet->frozen_by = $frozenBy;
            $wallet->save();

            return $wallet;
        });

        event(new WalletFrozen($wallet));

        return $wallet;
    }
}
