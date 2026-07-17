<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Events;

use Highvertical\Wallet\Infrastructure\Models\WalletTransfer;

final class WalletTransferred
{
    public function __construct(public WalletTransfer $transfer)
    {
    }
}
