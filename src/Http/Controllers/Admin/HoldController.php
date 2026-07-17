<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Controllers\Admin;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Http\Controllers\Controller;
use Highvertical\Wallet\Http\Requests\CaptureHoldRequest;
use Highvertical\Wallet\Http\Requests\PlaceHoldRequest;
use Highvertical\Wallet\Http\Resources\WalletHoldResource;
use Highvertical\Wallet\Http\Resources\WalletTransactionResource;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Illuminate\Http\JsonResponse;

/**
 * Admin: wallet.place-hold, wallet.release-hold and wallet.capture-hold are
 * flat permissions (see WalletPolicy docblock) enforced by route middleware
 * in routes/api.php. release and capture are kept as distinct permissions
 * so an admin role can be granted the (reversible, funds-preserving) ability
 * to release a hold without also being able to capture funds from it.
 */
final class HoldController extends Controller
{
    public function __construct(private WalletManager $wallet)
    {
    }

    public function store(PlaceHoldRequest $request, Wallet $wallet): WalletHoldResource
    {
        $hold = $this->wallet->placeHold(
            $wallet->getKey(),
            Money::fromDecimal((string) $request->input('amount'), $wallet->currency),
            (string) $request->input('reason'),
            null,
            $request->input('expires_after_hours') !== null ? (int) $request->input('expires_after_hours') : null,
            $request->input('reference')
        );

        return new WalletHoldResource($hold);
    }

    public function release(WalletHold $hold): WalletHoldResource
    {
        return new WalletHoldResource($this->wallet->releaseHold($hold->getKey()));
    }

    public function capture(CaptureHoldRequest $request, WalletHold $hold): JsonResponse
    {
        $amount = $request->filled('amount')
            ? Money::fromDecimal((string) $request->input('amount'), $hold->wallet->currency)
            : null;

        $result = $this->wallet->captureHold($hold->getKey(), $amount);

        return response()->json([
            'hold' => new WalletHoldResource($result['hold']),
            'transaction' => new WalletTransactionResource($result['transaction']),
        ]);
    }
}
