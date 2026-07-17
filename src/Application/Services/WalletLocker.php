<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Application\Services;

use Highvertical\Wallet\Domain\Contracts\WalletRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one place lockForUpdate() is called (01-ARCHITECTURE.md SS6.1). Action
 * classes never call the repository's lockForUpdate() directly - they go
 * through here, so this is also the one place a DB::transaction() opens
 * around a balance mutation.
 */
final class WalletLocker
{
    public function __construct(private WalletRepository $wallets)
    {
    }

    /**
     * @template TReturn
     * @param  callable(Model): TReturn  $callback
     * @return TReturn
     */
    public function lock(int $walletId, callable $callback)
    {
        return DB::transaction(fn () => $callback($this->wallets->lockForUpdate($walletId)));
    }

    /**
     * Locks both wallets in a single transaction, always in ascending
     * primary-key order, so a concurrent opposite-direction transfer can
     * never deadlock against this one. $callback still receives the wallets
     * in their original (a, b) argument order regardless of lock order.
     *
     * @template TReturn
     * @param  callable(Model, Model): TReturn  $callback
     * @return TReturn
     */
    public function lockPair(int $walletIdA, int $walletIdB, callable $callback)
    {
        return DB::transaction(function () use ($walletIdA, $walletIdB, $callback) {
            [$firstId, $secondId] = $walletIdA <= $walletIdB
                ? [$walletIdA, $walletIdB]
                : [$walletIdB, $walletIdA];

            $first = $this->wallets->lockForUpdate($firstId);
            $second = $firstId === $secondId ? $first : $this->wallets->lockForUpdate($secondId);

            $walletA = $first->getKey() === $walletIdA ? $first : $second;
            $walletB = $first->getKey() === $walletIdA ? $second : $first;

            return $callback($walletA, $walletB);
        });
    }
}
