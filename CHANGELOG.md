# Changelog

All notable changes to `highvertical/wallet` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
