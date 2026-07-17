# Changelog

All notable changes to `highvertical/wallet` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Idempotency, currency-match, and wallet-status guards across
  `AdjustBalanceAction`, `PlaceHoldAction`, `ReleaseHoldAction`, and
  `CaptureHoldAction`: replaying the same `reference` returns the original
  result instead of moving money again, a mismatched currency is rejected,
  and mutations against a frozen/inactive wallet are rejected rather than
  silently applied.
- `Money::fromDecimal()` now caps total significant digits and rejects
  malformed/oversized input at the domain layer (previously only enforced
  by HTTP-layer validation, so non-HTTP callers had no such guard) and
  enforces the currency whitelist from `config('wallet.currencies')`.
- `DepositFundsAction` now guards against a resolved deposit fee driving the
  balance below `min_balance`.
- `UnfreezeWalletAction` is now idempotent — unfreezing an already-active
  wallet is a no-op instead of an error.
- Split the `wallet.release-hold` permission in two: `wallet.release-hold`
  (reversible, funds-preserving) and `wallet.capture-hold` (debits the
  wallet). `POST /holds/{hold}/capture` now requires `wallet.capture-hold`
  instead of sharing `wallet.release-hold`.
- `RecordAuditLog` and `SendTransactionNotification` listeners now bound
  their retries (`$tries = 3`) and implement `failed()` to log the
  exhausted failure instead of silently dropping it.
- Validation hardening on all self-service/admin Form Requests: `amount`
  capped at 32 characters before regex validation; `meta` capped at 50 keys
  and 8192 bytes when JSON-encoded (`Concerns\ValidatesMeta`);
  `GET /wallet/transactions` clamps `per_page` server-side to `[1, 100]`.
- API version prefix: routes now live under `config('wallet.api_version_prefix')`
  (default `v1`, e.g. `/v1/wallet/deposit`); set to an empty string to keep
  routes unversioned for a pre-existing integration. Route names are
  unaffected.
- Static analysis: Larastan wired in at level 5 (`phpstan.neon.dist`, run in
  CI), with pre-existing debt captured in `phpstan-baseline.neon` so only
  new errors fail the build.
- `openapi.yaml`: a full OpenAPI 3.0 spec documenting every route, request
  body, response shape, and error response across the self-service and
  admin surfaces.
- Multi-currency transfers: a transfer whose sender/recipient wallets differ
  in currency is converted automatically instead of being rejected. The rate
  is resolved (and cached) before any wallet row lock is taken, and
  same-currency transfers never invoke the rate provider. New
  `Domain\Contracts\ExchangeRateProvider` swappable-strategy contract
  (mirrors `FeeCalculator`/`LimitPolicy`), with a bundled
  `HttpExchangeRateProvider` default reading `config('wallet.exchange.*')`
  (endpoint, response path, optional API key, cache TTL, timeout — ships
  pointed at the free `open.er-api.com`). New
  `Money::convertTo()` (bcmath, round-half-up),
  `WalletRepository::findAllForHolder()`, `TransferData::$recipientCurrency`
  / request field `recipient_currency` (disambiguates a recipient with
  wallets in multiple currencies), new `ExchangeRateUnavailableException`
  (503), and new `wallet_transfers.exchange_rate`/`converted_amount` columns
  surfaced on the transfer response alongside `to_currency`. Set
  `WALLET_EXCHANGE_ENABLED=false` to restore the previous strict
  same-currency-only behavior. Requires the `bcmath` PHP extension.

### Fixed

- **Security:** `HttpExchangeRateProvider` no longer embeds the raw
  connection-failure exception message in `ExchangeRateUnavailableException`.
  Guzzle/cURL transfer exceptions routinely include the full request URI —
  including the `api_key` query parameter, when one is configured — and that
  message was rendered verbatim as JSON to the caller of a failed
  cross-currency transfer. The original exception is still reported via
  `report()` for logging; only the client-facing message was changed to a
  static one.
- **Security:** `HttpExchangeRateProvider::getRate()` now validates that
  `$from`/`$to` are 3-letter currency codes before interpolating them into
  the FX endpoint URL, instead of relying solely on `Money`'s currency
  whitelist (which is opt-in and skipped entirely when
  `wallet.currencies` is left unconfigured).
- `TransferRequest` now rejects a non-scalar `recipient_id` (e.g. a JSON
  array) with a 422 instead of letting it reach `Model::findOrFail()`, which
  returns a `Collection` for an array argument and previously surfaced as an
  uncaught `TypeError` against `TransferData`'s `Model $toHolder` type.
- Removed two dead `use Illuminate\Http\Request;` imports
  (`WalletResource`, `Admin\WalletController`) left over from an earlier
  revision of those files.
- The `WalletException` JSON-rendering fallback path match
  (`$request->is('wallet/*')`) now respects `config('wallet.api_version_prefix')`
  instead of a hardcoded unversioned path.

- Hold expiry enforcement: `WalletHold::scopeActive()` now excludes holds
  whose `expires_at` has passed from every available-balance calculation;
  `php artisan wallet:expire-holds` flips expired holds to `EXPIRED` and
  dispatches `WalletHoldExpired`. Nothing expired holds automatically before
  this — the `EXPIRED` status existed on the enum but was dead code.
- Ledger reconciliation: `WalletManager::reconcileLedger()` and
  `php artisan wallet:reconcile {--wallet=} {--fix}` compare each wallet's
  `balance` column against its transaction ledger
  (`SUM(CREDIT) - SUM(DEBIT)`), report drift, and optionally correct it,
  dispatching `WalletBalanceReconciled`.
- Deposit fees: `config('wallet.fees.deposit')` is now consulted by
  `DepositFundsAction`, matching the existing withdraw/transfer fee
  behavior — a configured fee is charged as a separate `FEE`-category
  transaction (idempotent via a `-fee` reference suffix).
- Transaction export (`GET /wallet/transactions-export`) now streams via
  `lazy(500)` instead of loading the full result set into memory with
  `get()`; added HTTP test coverage (previously untested).

### Changed

- **Breaking:** `WalletManager::deposit()` now returns
  `array{transaction: WalletTransaction, fee_transaction: ?WalletTransaction}`
  instead of a bare `WalletTransaction`, matching the existing
  `withdraw()`/`transfer()` return shape. Update call sites to destructure
  `['transaction' => $tx]` (or `$result['transaction']`).

## [0.1.0] - 2026-07-17

Initial release.

### Added

- Ledger-based multi-currency wallet engine: `wallets`, `wallet_transactions`,
  `wallet_transfers`, `wallet_holds` tables.
- `Money` value object — integer minor-units arithmetic only, currency-aware,
  strict decimal parsing, no floats.
- Application actions: deposit, withdraw, transfer, place/release/capture
  hold, reverse transaction, freeze/unfreeze wallet, adjust balance —
  exposed through a single `WalletManager` facade.
- Idempotency via an optional `reference` on every money-movement action.
- Pessimistic per-wallet row locking (`WalletLocker`), ascending-PK-order
  locking for two-wallet operations to avoid deadlocks.
- Domain events for every mutation (`WalletCredited`, `WalletDebited`,
  `WalletTransferred`, `WalletHoldPlaced/Released/Captured`,
  `TransactionReversed`, `WalletFrozen/Unfrozen`, `LowBalanceDetected`),
  consumed by an audit-log listener and an optional notification listener.
- HTTP API: self-service routes (`/wallet/*`) and admin routes
  (`/wallet/admin/*`), gated per-route via `can:` middleware and named rate
  limiters.
- `WalletPolicy` / `WalletTransactionPolicy` for instance-scoped
  authorization (owner or `wallet.view-all`).
- `WalletPermissionSeeder` seeding 13 `spatie/laravel-permission` permissions
  and two default roles (`wallet-user`, `wallet-admin`).
- `HasWallet` trait + `Walletable` marker interface for opting any Eloquent
  model into owning wallets.
- Full PHPUnit/Orchestra Testbench test suite (Domain, Application, and HTTP
  layers).

[Unreleased]: https://github.com/highvertical/wallet/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/highvertical/wallet/releases/tag/v0.1.0
