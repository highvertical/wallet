<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Events;

use Highvertical\Wallet\Infrastructure\Models\Wallet;

final class WalletBalanceReconciled
{
    public function __construct(
        public Wallet $wallet,
        public int $previousBalance,
        public int $newBalance
    ) {
    }
}
