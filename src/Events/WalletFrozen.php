<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Events;

use Highvertical\Wallet\Infrastructure\Models\Wallet;

final class WalletFrozen
{
    public function __construct(public Wallet $wallet)
    {
    }
}
