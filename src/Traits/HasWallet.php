<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Traits;

use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Applied by the host app's holder model(s) (User, Merchant, ...) alongside
 * Domain\Contracts\Walletable to opt into owning wallets. No package change
 * needed for a new holder type - see 01-ARCHITECTURE.md SS7.
 */
trait HasWallet
{
    public function wallets(): MorphMany
    {
        return $this->morphMany(Wallet::class, 'holder');
    }

    public function wallet(string $walletName = 'default', ?string $currency = null): ?Wallet
    {
        return $this->wallets()
            ->where('name', $walletName)
            ->where('currency', $currency ?? config('wallet.default_currency'))
            ->first();
    }
}
