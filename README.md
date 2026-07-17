# Highvertical Wallet

A centralized, ledger-based multi-currency wallet engine for Laravel 8-13:
deposits, withdrawals, transfers, holds, reversals, admin adjustments, and a
full audit trail. Money is always handled as integer minor units — no floats
ever touch a balance.

## Requirements

- PHP ^8.0 with the `bcmath` extension
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
14 permissions, including freeze/unfreeze, adjust-balance, place-hold,
release-hold, capture-hold, reverse-transaction). `release-hold` and
`capture-hold` are separate permissions so a role can be granted the
reversible, funds-preserving ability to release a hold without also being
able to capture funds from it. Adjust `config('wallet.roles')` before
seeding if you want a different split.

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
['transaction' => $tx, 'fee_transaction' => $fee] = $wallet->deposit(new DepositData(
    holder: $user,
    amount: Money::fromDecimal('150.00', 'NGN'),
    reference: 'paystack-ref-123', // optional, makes the call idempotent
));

// Withdraw
['transaction' => $tx, 'fee_transaction' => $fee] = $wallet->withdraw(new WithdrawData(
    holder: $user,
    amount: Money::fromDecimal('50.00', 'NGN'),
));

// Transfer between two holders. If the recipient's wallet is in a
// different currency, the amount is converted automatically at the live
// rate (see "Multi-currency transfers" below).
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

`deposit()`, like `withdraw()` and `transfer()`, returns an
`array{transaction: WalletTransaction, fee_transaction: ?WalletTransaction}`
rather than a bare `WalletTransaction` — `fee_transaction` is populated when
`config('wallet.fees.deposit')` resolves to a positive fee, and is `null`
otherwise.

### Idempotency

Every money-movement Action accepts an optional `reference`. Passing the same
reference twice returns the original transaction/transfer instead of moving
money again — safe to retry after a timeout or client-side double-submit.

### Locking

All balance mutations run inside `WalletLocker`, which takes a pessimistic
row lock (`lockForUpdate()`) on the wallet(s) involved, locking in ascending
primary-key order for transfers/holds spanning two wallets to avoid deadlocks.

### Multi-currency transfers

A transfer whose sender and recipient wallets are in different currencies is
converted automatically: the sender is debited in their own currency, the
rate is fetched, and the recipient is credited the converted amount in
theirs. The rate is resolved (and cached) *before* any wallet row lock is
taken, so a slow or failing HTTP call never holds a lock. Same-currency
transfers never invoke the rate provider at all.

If the recipient holds wallets in more than one currency under the same
wallet name, pass `recipientCurrency` on `TransferData` (or `recipient_currency`
in the HTTP request) to say which one to credit — an ambiguous recipient
with no `recipientCurrency` given throws `CurrencyMismatchException` rather
than guessing. A recipient with no wallet in any eligible currency still
throws the same exception as before this feature.

Rate resolution is a swappable contract, exactly like fees and limits: bind
your own `Highvertical\Wallet\Domain\Contracts\ExchangeRateProvider` in your
app's service provider to replace the bundled `HttpExchangeRateProvider`
(e.g. a fixed-rate table, or a paid FX API). See `config('wallet.exchange')`
for the default HTTP provider's settings, or set
`WALLET_EXCHANGE_ENABLED=false` to disable cross-currency conversion
entirely and restore the original strict-currency-match behavior.

## HTTP API

Routes are registered under `wallet.auth_guard` (default `sanctum`,
configurable), prefixed with `config('wallet.api_version_prefix')` (default
`v1`), and gated per-route via `can:` middleware — see
[routes/api.php](routes/api.php) and the full request/response contract in
[openapi.yaml](openapi.yaml).

| Method | URI | Ability | Purpose |
|---|---|---|---|
| GET | `/v1/wallet` | `wallet.view-own` | List the authenticated user's own wallets |
| POST | `/v1/wallet/deposit` | `wallet.deposit` | Deposit into the caller's own wallet |
| POST | `/v1/wallet/withdraw` | `wallet.withdraw` | Withdraw from the caller's own wallet |
| POST | `/v1/wallet/transfer` | `wallet.transfer` | Transfer to another holder |
| GET | `/v1/wallet/transactions` | `wallet.view-transactions` | List the caller's own transactions |
| GET | `/v1/wallet/transactions/{transaction}` | `view,transaction` (policy) | Show one transaction, scoped to its owner |
| GET | `/v1/wallet/transactions-export` | `wallet.export-report` | Export the caller's transaction history |
| GET | `/v1/wallet/admin/wallets/{wallet}` | `view,wallet` (policy) | Show any wallet (owner or `wallet.view-all`) |
| POST | `/v1/wallet/admin/wallets/{wallet}/freeze` | `wallet.freeze` | Freeze a wallet |
| POST | `/v1/wallet/admin/wallets/{wallet}/unfreeze` | `wallet.unfreeze` | Unfreeze a wallet |
| POST | `/v1/wallet/admin/wallets/{wallet}/adjust` | `wallet.adjust-balance` | Signed manual adjustment |
| POST | `/v1/wallet/admin/wallets/{wallet}/holds` | `wallet.place-hold` | Place a hold |
| POST | `/v1/wallet/admin/holds/{hold}/release` | `wallet.release-hold` | Release a hold |
| POST | `/v1/wallet/admin/holds/{hold}/capture` | `wallet.capture-hold` | Capture a hold (full or partial) |
| POST | `/v1/wallet/admin/transactions/{transaction}/reverse` | `wallet.reverse-transaction` | Reverse a completed transaction |

Set `WALLET_API_VERSION_PREFIX=` (empty) to keep routes unversioned (e.g. for
an existing integration built against unversioned paths before `v1` was
introduced) — route *names* (`wallet.deposit`, etc.) are unaffected either way.

Every route also passes through a named rate limiter (`wallet-user`,
`wallet-history`, or `wallet-admin` — see `config('wallet.rate_limits')`).

**Validation caps:** `amount` fields are capped at 32 characters before
regex validation; `meta` payloads at 50 keys and 8192 bytes when
JSON-encoded; `per_page` on `/v1/wallet/transactions` is clamped
server-side to `[1, 100]` regardless of the requested value.

**Note:** responses wrapping a freshly-created model return HTTP **201**, not
200 — this is standard Laravel `JsonResource` behavior
(`wasRecentlyCreated === true`), regardless of HTTP verb. Deposit, adjust,
place-hold, and reverse-transaction all return 201; withdraw, transfer,
freeze/unfreeze, and release/capture return 200.

Transfers default the recipient's model class to the authenticated user's own
class. To transfer to a *different* holder model, register a Laravel morph
map (`Relation::enforceMorphMap()`) and pass its alias as `recipient_type` in
the request — an unrecognized value is rejected with 422.

Transfers also accept an optional `recipient_currency` to disambiguate a
recipient with wallets in more than one currency (see "Multi-currency
transfers" above). The transfer response includes `to_currency` (the
recipient wallet's currency), and `exchange_rate`/`converted_amount` — both
`null` for a same-currency transfer, otherwise the rate applied and the
credited amount in the recipient's currency.

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
| `WalletHoldExpired` | A hold's TTL passed (`wallet:expire-holds`) |
| `TransactionReversed` | Reverse transaction |
| `WalletFrozen` / `WalletUnfrozen` | Freeze / unfreeze |
| `LowBalanceDetected` | Withdraw/transfer that crosses `low_balance_threshold_percent` of `max_balance` |
| `WalletBalanceReconciled` | A drifted balance was corrected (`wallet:reconcile --fix`) |

Listen for any of them the usual Laravel way (`EventServiceProvider` or
`Event::listen()`).

## Console commands

```bash
# Expire holds whose TTL has passed (status -> EXPIRED, funds freed from
# available-balance calculations). Schedule this periodically if you use
# hold expiry — nothing expires holds automatically otherwise.
php artisan wallet:expire-holds

# Compare each wallet's `balance` column against the sum of its ledger
# (SUM(CREDIT) - SUM(DEBIT)). Reports drift; exits 1 if any wallet is out
# of balance, 0 if all balanced or --fix was applied.
php artisan wallet:reconcile
php artisan wallet:reconcile --wallet=42   # scope to one wallet
php artisan wallet:reconcile --fix         # correct drifted balances, dispatching WalletBalanceReconciled
```

## Configuration

See [config/wallet.php](config/wallet.php) for the full annotated reference:
auth guard, default currency, per-currency decimal places, holder model
override, fees (flat/percentage, in minor units / basis points), business
limits (daily/monthly caps, separate from rate limiting), low-balance alert
threshold, hold default TTL, audit log channel, notifications toggle, the
default permissions/roles/rate-limits seeded by this package, and currency
exchange (`config('wallet.exchange')`): enabled toggle, the default HTTP
endpoint/response-path (ships pointed at the free `open.er-api.com`, no key
required), optional API key + query param name, cache TTL, and request
timeout.

## Exceptions

All package exceptions extend `Highvertical\Wallet\Domain\Exceptions\WalletException`
(itself a `RuntimeException`) and carry an HTTP status code via `statusCode()`,
auto-rendered as JSON when the request expects JSON or matches the
(versioned) `wallet/*` path:

| Exception | Status | When |
|---|---|---|
| `InvalidAmountException` | 422 | Zero/negative amount where a positive one is required, or exceeds `max_balance` |
| `InsufficientFundsException` | 402 | Would drop available balance below `min_balance` |
| `CurrencyMismatchException` | 422 | Mismatched currencies in an operation, or transfer recipient has no eligible wallet, or has wallets in multiple currencies and no `recipient_currency` was given |
| `WalletNotUsableException` | 423 | Wallet is frozen/inactive |
| `LimitExceededException` | 429 | A configured business limit (daily/monthly) was exceeded |
| `ExchangeRateUnavailableException` | 503 | The exchange rate provider could not resolve a rate for a cross-currency transfer |

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=512M
```

The suite runs on Orchestra Testbench against an in-memory SQLite database —
no external services required. See [tests/](tests/) for Domain unit tests,
Application-layer Action tests, and HTTP feature tests covering both the
self-service and admin routes.

Static analysis runs at Larastan level 5 (`phpstan.neon.dist`); pre-existing
debt is snapshotted in `phpstan-baseline.neon` so CI only fails on *new*
errors introduced going forward.

## License

MIT. See [LICENSE](LICENSE).
