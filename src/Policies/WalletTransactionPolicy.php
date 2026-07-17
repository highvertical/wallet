<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Policies;

use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;

/**
 * Guards TransactionController::show() (self-service, by id): a holder may
 * only ever look up a transaction that belongs to one of their own wallets,
 * regardless of the wallet.view-transactions permission they hold - that
 * permission grants "view your own history", not "view anyone's".
 * wallet.view-all additionally allows an admin to look up any transaction.
 */
final class WalletTransactionPolicy
{
    public function view(Model $user, WalletTransaction $transaction): bool
    {
        $wallet = $transaction->wallet;

        if ($wallet === null) {
            return false;
        }

        $owns = $wallet->holder_type === $user->getMorphClass()
            && (string) $wallet->holder_id === (string) $user->getKey();

        return $owns || $user->can('wallet.view-all');
    }
}
