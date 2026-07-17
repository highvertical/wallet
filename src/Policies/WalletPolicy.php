<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Policies;

use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Illuminate\Database\Eloquent\Model;

/**
 * Every admin mutation (freeze, adjust, hold, reverse, ...) is a flat
 * wallet.* permission check done by the `can:wallet.*` route middleware
 * directly (see routes/api.php) - an admin acts on any wallet, so there is
 * no per-instance rule to express. The one ability that genuinely depends
 * on which wallet is being looked at is view: a holder may see their own
 * wallet, an admin with wallet.view-all may see anyone's.
 */
final class WalletPolicy
{
    public function view(Model $user, Wallet $wallet): bool
    {
        return $this->owns($user, $wallet) || $user->can('wallet.view-all');
    }

    private function owns(Model $user, Wallet $wallet): bool
    {
        return $wallet->holder_type === $user->getMorphClass()
            && (string) $wallet->holder_id === (string) $user->getKey();
    }
}
