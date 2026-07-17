<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Controllers\Admin;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\AdjustmentData;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Http\Controllers\Controller;
use Highvertical\Wallet\Http\Requests\AdjustBalanceRequest;
use Highvertical\Wallet\Http\Requests\FreezeWalletRequest;
use Highvertical\Wallet\Http\Resources\WalletResource;
use Highvertical\Wallet\Http\Resources\WalletTransactionResource;
use Highvertical\Wallet\Infrastructure\Models\Wallet;

/**
 * Admin: acts on any wallet by id. `wallets.show` is instance-scoped via
 * the `can:view,wallet` route middleware + WalletPolicy (owner or
 * wallet.view-all); every mutation below is a flat wallet.* permission,
 * enforced by the route middleware in routes/api.php - an admin with the
 * permission may act on any wallet, so there's nothing further to check here.
 */
final class WalletController extends Controller
{
    public function __construct(private WalletManager $wallet)
    {
    }

    public function show(Wallet $wallet): WalletResource
    {
        return new WalletResource($wallet);
    }

    public function freeze(FreezeWalletRequest $request, Wallet $wallet): WalletResource
    {
        $frozen = $this->wallet->freeze($wallet->getKey(), (string) $request->input('reason'), $request->user()->getAuthIdentifier());

        return new WalletResource($frozen);
    }

    public function unfreeze(Wallet $wallet): WalletResource
    {
        return new WalletResource($this->wallet->unfreeze($wallet->getKey()));
    }

    public function adjustBalance(AdjustBalanceRequest $request, Wallet $wallet): WalletTransactionResource
    {
        $transaction = $this->wallet->adjustBalance(new AdjustmentData(
            walletId: $wallet->getKey(),
            amount: Money::fromDecimal((string) $request->input('amount'), $wallet->currency),
            reason: (string) $request->input('reason'),
            initiatedBy: $request->user()->getAuthIdentifier(),
            initiatedIp: $request->ip(),
            reference: $request->input('reference'),
            meta: (array) $request->input('meta', [])
        ));

        return new WalletTransactionResource($transaction);
    }
}
