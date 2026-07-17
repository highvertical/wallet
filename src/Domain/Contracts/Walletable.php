<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Contracts;

/**
 * Marker interface for any model that can own a wallet. Requires nothing
 * beyond what Eloquent already gives a model (getKey(), getMorphClass()).
 * Host apps opt a model in with Traits\HasWallet; the package never imports
 * a concrete host model.
 */
interface Walletable
{
}
