<?php

declare(strict_types=1);

use Highvertical\Wallet\Http\Controllers\Admin\HoldController;
use Highvertical\Wallet\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use Highvertical\Wallet\Http\Controllers\Admin\WalletController as AdminWalletController;
use Highvertical\Wallet\Http\Controllers\TransactionController;
use Highvertical\Wallet\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Wallet routes
|--------------------------------------------------------------------------
|
| Every ability check here is a route-middleware concern, not something
| re-checked inside a controller or Form Request - see WalletPolicy's
| docblock for why admin mutations are flat wallet.* permission checks
| while the two `show` routes are instance-scoped ("view,wallet" /
| "view,transaction") via WalletPolicy / WalletTransactionPolicy.
|
*/
Route::middleware(['api', 'auth:'.config('wallet.auth_guard')])
    ->prefix('wallet')
    ->name('wallet.')
    ->group(function () {
        Route::middleware(['throttle:wallet-user', 'can:wallet.view-own'])
            ->get('/', [WalletController::class, 'index'])
            ->name('index');

        Route::middleware(['throttle:wallet-user', 'can:wallet.deposit'])
            ->post('/deposit', [TransactionController::class, 'deposit'])
            ->name('deposit');

        Route::middleware(['throttle:wallet-user', 'can:wallet.withdraw'])
            ->post('/withdraw', [TransactionController::class, 'withdraw'])
            ->name('withdraw');

        Route::middleware(['throttle:wallet-user', 'can:wallet.transfer'])
            ->post('/transfer', [TransactionController::class, 'transfer'])
            ->name('transfer');

        Route::middleware(['throttle:wallet-history', 'can:wallet.view-transactions'])
            ->get('/transactions', [TransactionController::class, 'index'])
            ->name('transactions.index');

        Route::middleware(['throttle:wallet-history', 'can:view,transaction'])
            ->get('/transactions/{transaction}', [TransactionController::class, 'show'])
            ->name('transactions.show');

        Route::middleware(['throttle:wallet-history', 'can:wallet.export-report'])
            ->get('/transactions-export', [TransactionController::class, 'export'])
            ->name('transactions.export');

        Route::middleware('throttle:wallet-admin')
            ->prefix('admin')
            ->name('admin.')
            ->group(function () {
                Route::middleware('can:view,wallet')
                    ->get('/wallets/{wallet}', [AdminWalletController::class, 'show'])
                    ->name('wallets.show');

                Route::middleware('can:wallet.freeze')
                    ->post('/wallets/{wallet}/freeze', [AdminWalletController::class, 'freeze'])
                    ->name('wallets.freeze');

                Route::middleware('can:wallet.unfreeze')
                    ->post('/wallets/{wallet}/unfreeze', [AdminWalletController::class, 'unfreeze'])
                    ->name('wallets.unfreeze');

                Route::middleware('can:wallet.adjust-balance')
                    ->post('/wallets/{wallet}/adjust', [AdminWalletController::class, 'adjustBalance'])
                    ->name('wallets.adjust');

                Route::middleware('can:wallet.place-hold')
                    ->post('/wallets/{wallet}/holds', [HoldController::class, 'store'])
                    ->name('holds.store');

                Route::middleware('can:wallet.release-hold')
                    ->post('/holds/{hold}/release', [HoldController::class, 'release'])
                    ->name('holds.release');

                Route::middleware('can:wallet.release-hold')
                    ->post('/holds/{hold}/capture', [HoldController::class, 'capture'])
                    ->name('holds.capture');

                Route::middleware('can:wallet.reverse-transaction')
                    ->post('/transactions/{transaction}/reverse', [AdminTransactionController::class, 'reverse'])
                    ->name('transactions.reverse');
            });
    });
