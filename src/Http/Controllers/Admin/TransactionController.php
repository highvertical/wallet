<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Controllers\Admin;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Http\Controllers\Controller;
use Highvertical\Wallet\Http\Requests\ReverseTransactionRequest;
use Highvertical\Wallet\Http\Resources\WalletTransactionResource;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;

/**
 * Admin: wallet.reverse-transaction is a flat permission enforced by route
 * middleware in routes/api.php - see WalletPolicy docblock for why no
 * per-instance check is needed here.
 */
final class TransactionController extends Controller
{
    public function __construct(private WalletManager $wallet)
    {
    }

    public function reverse(ReverseTransactionRequest $request, WalletTransaction $transaction): WalletTransactionResource
    {
        $reversal = $this->wallet->reverseTransaction(
            $transaction->getKey(),
            (string) $request->input('reason'),
            $request->user()->getAuthIdentifier()
        );

        return new WalletTransactionResource($reversal);
    }
}
