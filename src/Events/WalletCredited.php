<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Events;

use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;

final class WalletCredited
{
    public function __construct(public WalletTransaction $transaction)
    {
    }
}
