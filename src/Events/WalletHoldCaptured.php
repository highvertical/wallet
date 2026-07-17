<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Events;

use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;

final class WalletHoldCaptured
{
    public function __construct(public WalletHold $hold, public WalletTransaction $transaction)
    {
    }
}
