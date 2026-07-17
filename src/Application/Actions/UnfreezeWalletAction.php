<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Highvertical\Wallet\Events\WalletUnfrozen;
use Highvertical\Wallet\Infrastructure\Models\Wallet;

final class UnfreezeWalletAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    public function handle(int $walletId): Wallet
    {
        $wallet = $this->locker->lock($walletId, function (Wallet $wallet) {
            $wallet->status = WalletStatus::ACTIVE;
            $wallet->frozen_reason = null;
            $wallet->frozen_at = null;
            $wallet->frozen_by = null;
            $wallet->save();

            return $wallet;
        });

        event(new WalletUnfrozen($wallet));

        return $wallet;
    }
}
