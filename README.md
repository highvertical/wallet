# Highvertical Wallet

A centralized, ledger-based multi-currency wallet engine for Laravel 8-13:
deposits, withdrawals, transfers, holds, reversals, admin adjustments, and a
full audit trail. Money is always handled as integer minor units — no floats
ever touch a balance.

## Requirements

- PHP ^8.0
- Laravel (`illuminate/*`) ^8.0 | ^9.0 | ^10.0 | ^11.0 | ^12.0 | ^13.0
- `spatie/laravel-permission` ^5.0 | ^6.0 | ^7.0 | ^8.0

## Installation

```bash
composer require highvertical/wallet
```

Publish the config:

```bash
php artisan vendor:publish --tag=wallet-config
```

Run the migrations (creates `wallets`, `wallet_transactions`, `wallet_transfers`,
`wallet_holds`, plus Spatie's permission tables if not already present):

```bash
php artisan migrate
```

Seed the default roles and permissions from your own `DatabaseSeeder`:

```php
(new \Highvertical\Wallet\Database\Seeders\WalletPermissionSeeder())->run();
```

This seeds two roles — `wallet-user` (self-service: view-own, deposit,
withdraw, transfer, view-transactions, export-report) and `wallet-admin` (all
13 permissions, including freeze/unfreeze, adjust-balance, place/release-hold,
reverse-transaction). Adjust `config('wallet.roles')` before seeding if you
want a different split.

## Making a model a wallet holder

Any Eloquent model (`User`, `Merchant`, ...) can own wallets:

```php
use Highvertical\Wallet\Domain\Contracts\Walletable;
use Highvertical\Wallet\Traits\HasWallet;

class User extends Authenticatable implements Walletable
{
    use HasWallet;
}
```

`HasWallet` gives you `$user->wallets()` (a `MorphMany`) and
`$user->wallet(string $walletName = 'default', ?string $currency = null)` to
fetch a specific wallet.

## Usage (`WalletManager`)

Everything goes through `Highvertical\Wallet\Application\WalletManager`,
resolvable from the container:

```php
use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Data\WithdrawData;
use Highvertical\Wallet\Domain\Data\TransferData;
use Highvertical\Wallet\Domain\Data\AdjustmentData;
use Highvertical\Wallet\Domain\ValueObjects\Money;

$wallet = app(WalletManager::class);

// Deposit — creates the wallet on first use (findOrCreate)
$transaction = $wallet->deposit(new DepositData(
    holder: $user,
    amount: Money::fromDecimal('150.00', 'NGN'),
    reference: 'paystack-ref-123', // optional, makes the call idempotent
));

// Withdraw
['transaction' => $tx, 'fee_transaction' => $fee] = $wallet->withdraw(new WithdrawData(
    holder: $user,
    amount: Money::fromDecimal('50.00', 'NGN'),
));

// Transfer between two holders (their wallets must already exist & match currency)
$result = $wallet->transfer(new TransferData(
    fromHolder: $sender,
    toHolder: $recipient,
    amount: Money::fromDecimal('20.00', 'NGN'),
));

// Admin: place / release / capture a hold
$hold = $wallet->placeHold($walletId, Money::fromDecimal('100.00', 'NGN'), 'reserved for order #42');
$wallet->releaseHold($hold->id);
['hold' => $hold, 'transaction' => $tx] = $wallet->captureHold($hold->id); // full amount by default

// Admin: freeze / unfreeze / adjust / reverse
$wallet->freeze($walletId, 'suspected fraud', $adminId);
$wallet->unfreeze($walletId);
$wallet->adjustBalance(new AdjustmentData(
    walletId: $walletId,
    amount: Money::fromDecimal('-30.00', 'NGN'), // signed: negative debits, positive credits
    reason: 'correction',
    initiatedBy: $adminId,
));
$wallet->reverseTransaction($transactionId, 'chargeback', $adminId);
```

`Money::fromDecimal()` validates against the currency's configured decimal
places (`config('wallet.currencies')`) and rejects malformed input (scientific
notation, non-numeric strings, too many decimal places).

### Idempotency

Every money-movement Action accepts an optional `reference`. Passing the same
reference twice returns the original transaction/transfer instead of moving
money again — safe to retry after a timeout or client-side double-submit.

### Locking

All balance mutations run inside `WalletLocker`, which takes a pessimistic
row lock (`lockForUpdate()`) on the wallet(s) involved, locking in ascending
primary-key order for transfers/holds spanning two wallets to avoid deadlocks.

## HTTP API

Routes are registered under `wallet.auth_guard` (default `sanctum`,
configurable) and gated per-route via `can:` middleware — see
[routes/api.php](routes/api.php).

| Method | URI | Ability | Purpose |
|---|---|---|---|
| GET | `/wallet` | `wallet.view-own` | List the authenticated user's own wallets |
| POST | `/wallet/deposit` | `wallet.deposit` | Deposit into the caller's own wallet |
| POST | `/wallet/withdraw` | `wallet.withdraw` | Withdraw from the caller's own wallet |
| POST | `/wallet/transfer` | `wallet.transfer` | Transfer to another holder |
| GET | `/wallet/transactions` | `wallet.view-transactions` | List the caller's own transactions |
| GET | `/wallet/transactions/{transaction}` | `view,transaction` (policy) | Show one transaction, scoped to its owner |
| GET | `/wallet/transactions-export` | `wallet.export-report` | Export the caller's transaction history |
| GET | `/wallet/admin/wallets/{wallet}` | `view,wallet` (policy) | Show any wallet (owner or `wallet.view-all`) |
| POST | `/wallet/admin/wallets/{wallet}/freeze` | `wallet.freeze` | Freeze a wallet |
| POST | `/wallet/admin/wallets/{wallet}/unfreeze` | `wallet.unfreeze` | Unfreeze a wallet |
| POST | `/wallet/admin/wallets/{wallet}/adjust` | `wallet.adjust-balance` | Signed manual adjustment |
| POST | `/wallet/admin/wallets/{wallet}/holds` | `wallet.place-hold` | Place a hold |
| POST | `/wallet/admin/holds/{hold}/release` | `wallet.release-hold` | Release a hold |
| POST | `/wallet/admin/holds/{hold}/capture` | `wallet.release-hold` | Capture a hold (full or partial) |
| POST | `/wallet/admin/transactions/{transaction}/reverse` | `wallet.reverse-transaction` | Reverse a completed transaction |

Every route also passes through a named rate limiter (`wallet-user`,
`wallet-history`, or `wallet-admin` — see `config('wallet.rate_limits')`).

**Note:** responses wrapping a freshly-created model return HTTP **201**, not
200 — this is standard Laravel `JsonResource` behavior
(`wasRecentlyCreated === true`), regardless of HTTP verb. Deposit, adjust,
place-hold, and reverse-transaction all return 201; withdraw, transfer,
freeze/unfreeze, and release/capture return 200.

Transfers default the recipient's model class to the authenticated user's own
class. To transfer to a *different* holder model, register a Laravel morph
map (`Relation::enforceMorphMap()`) and pass its alias as `recipient_type` in
the request — an unrecognized value is rejected with 422.

## Events

Every mutation fires a domain event, consumed internally by an audit-log
listener and an optional notification listener (`config('wallet.notifications.enabled')`):

| Event | Fired by |
|---|---|
| `WalletCredited` | Deposit, positive adjustment |
| `WalletDebited` | Withdraw, negative adjustment |
| `WalletTransferred` | Transfer |
| `WalletHoldPlaced` | Place hold |
| `WalletHoldReleased` | Release hold |
| `WalletHoldCaptured` | Capture hold |
| `TransactionReversed` | Reverse transaction |
| `WalletFrozen` / `WalletUnfrozen` | Freeze / unfreeze |
| `LowBalanceDetected` | Withdraw/transfer that crosses `low_balance_threshold_percent` of `max_balance` |

Listen for any of them the usual Laravel way (`EventServiceProvider` or
`Event::listen()`).

## Configuration

See [config/wallet.php](config/wallet.php) for the full annotated reference:
auth guard, default currency, per-currency decimal places, holder model
override, fees (flat/percentage, in minor units / basis points), business
limits (daily/monthly caps, separate from rate limiting), low-balance alert
threshold, hold default TTL, audit log channel, notifications toggle, and the
default permissions/roles/rate-limits seeded by this package.

## Exceptions

All package exceptions extend `Highvertical\Wallet\Domain\Exceptions\WalletException`
(itself a `RuntimeException`) and carry an HTTP status code via `statusCode()`,
auto-rendered as JSON when the request expects JSON or matches `wallet/*`:

| Exception | Status | When |
|---|---|---|
| `InvalidAmountException` | 422 | Zero/negative amount where a positive one is required, or exceeds `max_balance` |
| `InsufficientFundsException` | 402 | Would drop available balance below `min_balance` |
| `CurrencyMismatchException` | 422 | Mismatched currencies in an operation, or transfer recipient has no wallet in that currency |
| `WalletNotUsableException` | 423 | Wallet is frozen/inactive |
| `LimitExceededException` | 429 | A configured business limit (daily/monthly) was exceeded |

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite runs on Orchestra Testbench against an in-memory SQLite database —
no external services required. See [tests/](tests/) for Domain unit tests,
Application-layer Action tests, and HTTP feature tests covering both the
self-service and admin routes.

## License

MIT. See [LICENSE](LICENSE).
