<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Events;

use Highvertical\Wallet\Infrastructure\Models\WalletHold;

final class WalletHoldPlaced
{
    public function __construct(public WalletHold $hold)
    {
    }
}
