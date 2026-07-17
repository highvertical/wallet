<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Actions;

use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Events\WalletUnfrozen;
use Highvertical\Wallet\Infrastructure\Models\Wallet;

final class UnfreezeWalletAction
{
    public function __construct(private WalletLocker $locker)
    {
    }

    public function handle(int $walletId): Wallet
    {
        $result = $this->locker->lock($walletId, function (Wallet $wallet) {
            // Already active is a harmless no-op retry; only a wallet that
            // was never frozen in the first place (e.g. deactivated by the
            // host app directly) is a genuine invalid transition.
            if ($wallet->status === WalletStatus::ACTIVE) {
                return ['wallet' => $wallet, 'changed' => false];
            }

            if ($wallet->status !== WalletStatus::FROZEN) {
                throw new WalletNotUsableException('Only a frozen wallet can be unfrozen.');
            }

            $wallet->status = WalletStatus::ACTIVE;
            $wallet->frozen_reason = null;
            $wallet->frozen_at = null;
            $wallet->frozen_by = null;
            $wallet->save();

            return ['wallet' => $wallet, 'changed' => true];
        });

        if ($result['changed']) {
            event(new WalletUnfrozen($result['wallet']));
        }

        return $result['wallet'];
    }
}
