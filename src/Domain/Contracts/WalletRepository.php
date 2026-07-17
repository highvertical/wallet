<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The one persistence seam genuinely worth swapping: how a wallet row is
 * found-or-created and fetched-and-locked. Everything else (transactions,
 * transfers, holds) is used directly as an Eloquent model from the
 * Application layer - see 01-ARCHITECTURE.md SS4 for why that's deliberate.
 *
 * Typed against the base Eloquent Model, not Infrastructure\Models\Wallet,
 * so this contract (Domain) never imports Infrastructure. EloquentWalletRepository
 * narrows the return type to Wallet covariantly.
 */
interface WalletRepository
{
    public function findOrCreate(string $holderType, int|string $holderId, string $walletName, string $currency): Model;

    /**
     * Non-creating lookup. Used on the recipient leg of a transfer: a wallet
     * is never auto-created in a currency for someone else's account, only
     * for the caller's own holder via findOrCreate().
     */
    public function find(string $holderType, int|string $holderId, string $walletName, string $currency): ?Model;

    /**
     * Non-creating lookup across every currency variant of $walletName for
     * this holder (the unique key is holder+name+currency, so one holder can
     * have several same-named wallets in different currencies). Used only to
     * resolve an implicit recipient currency for a cross-currency transfer
     * when the sender didn't specify one and no same-currency wallet exists
     * - never creates anything, same invariant as find().
     *
     * @return list<Model>
     */
    public function findAllForHolder(string $holderType, int|string $holderId, string $walletName): array;

    /**
     * Must be called inside an open DB::transaction(). Locks the row and
     * returns it with a freshly re-read balance.
     */
    public function lockForUpdate(int $walletId): Model;
}
