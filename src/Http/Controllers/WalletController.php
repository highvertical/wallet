<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Controllers;

use Highvertical\Wallet\Http\Resources\WalletResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Self-service: every route here acts on the authenticated user's own
 * wallet(s), resolved via Traits\HasWallet - never by an id in the URL.
 * Authorization is the `can:wallet.view-own` route middleware (routes/api.php).
 */
final class WalletController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return WalletResource::collection($request->user()->wallets()->get());
    }
}
