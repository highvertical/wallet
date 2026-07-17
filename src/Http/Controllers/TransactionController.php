<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Controllers;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Data\TransferData;
use Highvertical\Wallet\Domain\Data\WithdrawData;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Http\Requests\DepositRequest;
use Highvertical\Wallet\Http\Requests\TransferRequest;
use Highvertical\Wallet\Http\Requests\WithdrawRequest;
use Highvertical\Wallet\Http\Resources\WalletTransactionResource;
use Highvertical\Wallet\Http\Resources\WalletTransferResource;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Self-service: deposit/withdraw/transfer always act on the authenticated
 * user's own wallet; index/show/export are scoped to the authenticated
 * user's own transactions. Authorization is the `can:wallet.*` route
 * middleware (routes/api.php), except show() which is instance-scoped via
 * the `can:view,transaction` middleware + WalletTransactionPolicy.
 */
final class TransactionController extends Controller
{
    public function __construct(private WalletManager $wallet)
    {
    }

    public function deposit(DepositRequest $request): WalletTransactionResource
    {
        $transaction = $this->wallet->deposit(new DepositData(
            holder: $request->user(),
            amount: Money::fromDecimal((string) $request->input('amount'), $this->currency($request)),
            walletName: (string) $request->input('wallet_name', 'default'),
            reference: $request->input('reference'),
            description: $request->input('description'),
            meta: (array) $request->input('meta', []),
            initiatedBy: $request->user()->getAuthIdentifier(),
            initiatedIp: $request->ip()
        ));

        return new WalletTransactionResource($transaction);
    }

    public function withdraw(WithdrawRequest $request): JsonResponse
    {
        $result = $this->wallet->withdraw(new WithdrawData(
            holder: $request->user(),
            amount: Money::fromDecimal((string) $request->input('amount'), $this->currency($request)),
            walletName: (string) $request->input('wallet_name', 'default'),
            reference: $request->input('reference'),
            description: $request->input('description'),
            meta: (array) $request->input('meta', []),
            initiatedBy: $request->user()->getAuthIdentifier(),
            initiatedIp: $request->ip()
        ));

        return response()->json([
            'transaction' => new WalletTransactionResource($result['transaction']),
            'fee_transaction' => $result['fee_transaction'] ? new WalletTransactionResource($result['fee_transaction']) : null,
        ]);
    }

    public function transfer(TransferRequest $request): JsonResponse
    {
        $recipientClass = get_class($request->user());

        if ($request->filled('recipient_type')) {
            $morphed = Relation::getMorphedModel((string) $request->input('recipient_type'));

            abort_if($morphed === null, 422, 'Unknown recipient_type.');

            $recipientClass = $morphed;
        }

        $recipient = $recipientClass::query()->findOrFail($request->input('recipient_id'));

        $result = $this->wallet->transfer(new TransferData(
            fromHolder: $request->user(),
            toHolder: $recipient,
            amount: Money::fromDecimal((string) $request->input('amount'), $this->currency($request)),
            walletName: (string) $request->input('wallet_name', 'default'),
            reference: $request->input('reference'),
            note: $request->input('note'),
            meta: (array) $request->input('meta', []),
            initiatedBy: $request->user()->getAuthIdentifier(),
            initiatedIp: $request->ip()
        ));

        return response()->json([
            'transfer' => new WalletTransferResource($result['transfer']),
            'debit_transaction' => new WalletTransactionResource($result['debit_transaction']),
            'credit_transaction' => new WalletTransactionResource($result['credit_transaction']),
            'fee_transaction' => $result['fee_transaction'] ? new WalletTransactionResource($result['fee_transaction']) : null,
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $transactions = WalletTransaction::query()
            ->with('wallet')
            ->whereHas('wallet', $this->ownWalletScope($request))
            ->latest()
            ->paginate((int) $request->input('per_page', 25));

        return WalletTransactionResource::collection($transactions);
    }

    public function show(WalletTransaction $transaction): WalletTransactionResource
    {
        return new WalletTransactionResource($transaction->loadMissing('wallet'));
    }

    public function export(Request $request): StreamedResponse
    {
        $transactions = WalletTransaction::query()
            ->with('wallet')
            ->whereHas('wallet', $this->ownWalletScope($request))
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($transactions) {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['uuid', 'type', 'category', 'amount', 'currency', 'balance_after', 'reference', 'status', 'created_at']);

            foreach ($transactions as $transaction) {
                $currency = $transaction->wallet->currency;

                fputcsv($handle, [
                    $transaction->uuid,
                    $transaction->type,
                    $transaction->category,
                    Money::fromMinorUnits($transaction->amount, $currency)->toDecimal(),
                    $currency,
                    Money::fromMinorUnits($transaction->balance_after, $currency)->toDecimal(),
                    $transaction->reference,
                    $transaction->status,
                    optional($transaction->created_at)->toIso8601String(),
                ]);
            }

            fclose($handle);
        }, 'wallet-transactions.csv', ['Content-Type' => 'text/csv']);
    }

    private function ownWalletScope(Request $request): \Closure
    {
        $user = $request->user();

        return function ($query) use ($user) {
            $query->where('holder_type', $user->getMorphClass())->where('holder_id', $user->getKey());
        };
    }

    private function currency(Request $request): string
    {
        return (string) $request->input('currency', config('wallet.default_currency'));
    }
}
