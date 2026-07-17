<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Auth guard
    |--------------------------------------------------------------------------
    |
    | Guard used by the package's own routes. The package only requires
    | Illuminate\Contracts\Auth\Authenticatable, so any guard works.
    |
    */
    'auth_guard' => env('WALLET_AUTH_GUARD', 'sanctum'),

    /*
    |--------------------------------------------------------------------------
    | API version prefix
    |--------------------------------------------------------------------------
    |
    | Prepended to every route in routes/api.php (e.g. "v1" -> /v1/wallet/...).
    | Set to an empty string to keep routes unversioned, e.g. for an existing
    | integration built against unversioned paths before this was introduced.
    |
    */
    'api_version_prefix' => env('WALLET_API_VERSION_PREFIX', 'v1'),

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | ISO 4217 code used when a wallet is created without one specified.
    |
    */
    'default_currency' => env('WALLET_DEFAULT_CURRENCY', 'NGN'),

    /*
    |--------------------------------------------------------------------------
    | Currencies
    |--------------------------------------------------------------------------
    |
    | Minor-unit precision per currency, consulted by the Money value object
    | for every conversion between minor units and a display string.
    |
    */
    'currencies' => [
        'NGN' => ['decimal_places' => 2],
        'USD' => ['decimal_places' => 2],
        'GBP' => ['decimal_places' => 2],
        'EUR' => ['decimal_places' => 2],
        'JPY' => ['decimal_places' => 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency exchange
    |--------------------------------------------------------------------------
    |
    | Automated conversion for cross-currency transfers (deposits/withdrawals
    | stay single-currency). Read by the default HttpExchangeRateProvider;
    | bind a custom ExchangeRateProvider to use a different source. The rate
    | is fetched (and cached) before any wallet row lock is taken. Disabling
    | restores the pre-conversion behaviour of rejecting any transfer whose
    | sender/recipient wallets differ in currency.
    |
    */
    'exchange' => [
        'enabled' => (bool) env('WALLET_EXCHANGE_ENABLED', true),
        'endpoint' => env('WALLET_EXCHANGE_ENDPOINT', 'https://open.er-api.com/v6/latest/{from}'),
        'response_path' => env('WALLET_EXCHANGE_RESPONSE_PATH', 'rates.{to}'),
        'api_key' => env('WALLET_EXCHANGE_API_KEY'),
        'api_key_query_param' => env('WALLET_EXCHANGE_API_KEY_PARAM', 'access_key'),
        'cache_ttl_seconds' => (int) env('WALLET_EXCHANGE_CACHE_TTL', 3600),
        'timeout_seconds' => (int) env('WALLET_EXCHANGE_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Holder model
    |--------------------------------------------------------------------------
    |
    | Optional explicit binding if the host app can't rely on trait
    | auto-detection of the model that owns a wallet.
    |
    */
    'holder_model' => env('WALLET_HOLDER_MODEL'),

    /*
    |--------------------------------------------------------------------------
    | Fees
    |--------------------------------------------------------------------------
    |
    | Each entry: type (flat|percentage), value (minor units for flat, basis
    | points for percentage e.g. 150 = 1.5% - kept integer so no float ever
    | touches money), optional cap/min in minor units. Read by the default
    | ConfigDrivenFeeCalculator.
    |
    */
    'fees' => [
        'deposit' => [
            'type' => 'flat',
            'value' => 0,
            'min' => null,
            'cap' => null,
        ],
        'withdrawal' => [
            'type' => 'flat',
            'value' => 0,
            'min' => null,
            'cap' => null,
        ],
        'transfer' => [
            'type' => 'flat',
            'value' => 0,
            'min' => null,
            'cap' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Business-level daily/monthly caps in minor units, separate from and in
    | addition to the infrastructure rate limiter below. Nullable disables
    | the corresponding cap. Read by the default RollingWindowLimitPolicy.
    |
    */
    'limits' => [
        'deposit' => [
            'daily' => null,
            'monthly' => null,
        ],
        'withdrawal' => [
            'daily' => null,
            'monthly' => null,
        ],
        'transfer' => [
            'daily' => null,
            'monthly' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Low balance alert
    |--------------------------------------------------------------------------
    |
    | Percent of max_balance at which LowBalanceDetected fires. Only
    | meaningful for wallets that have a max_balance configured.
    |
    */
    'low_balance_threshold_percent' => 10,

    /*
    |--------------------------------------------------------------------------
    | Holds
    |--------------------------------------------------------------------------
    */
    'hold_default_ttl_hours' => 72,

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Every domain event is recorded here via Listeners\RecordAuditLog. The
    | WalletTransaction ledger is already an immutable audit trail for money
    | movement (balance_before/after, initiated_by, initiated_ip); this
    | channel additionally covers events with no transaction row of their
    | own (freeze/unfreeze, holds, low-balance alerts) and gives host apps
    | one place to pipe wallet activity into their own log aggregation.
    |
    */
    'audit_log_channel' => env('WALLET_AUDIT_LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Off by default. When enabled, Listeners\SendTransactionNotification
    | notifies a wallet's holder of activity on their own wallet, provided
    | the holder model uses Illuminate's Notifiable trait. channels() is
    | passed straight to Notification::via().
    |
    */
    'notifications' => [
        'enabled' => (bool) env('WALLET_NOTIFICATIONS_ENABLED', false),
        'channels' => ['database'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    |
    | The full list of Spatie permission names this package seeds via
    | WalletPermissionSeeder.
    |
    */
    'permissions' => [
        'wallet.view-own',
        'wallet.deposit',
        'wallet.withdraw',
        'wallet.transfer',
        'wallet.view-transactions',
        'wallet.export-report',
        'wallet.view-all',
        'wallet.freeze',
        'wallet.unfreeze',
        'wallet.adjust-balance',
        'wallet.place-hold',
        'wallet.release-hold',
        'wallet.capture-hold',
        'wallet.reverse-transaction',
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | Default Spatie roles this package seeds via WalletPermissionSeeder, each
    | mapped to the subset of the permissions above it should hold. Mirrors
    | the self-service vs admin split already enforced by the routes/
    | controllers (see routes/api.php and src/Http/Controllers/Admin/).
    |
    */
    'roles' => [
        'wallet-user' => [
            'wallet.view-own',
            'wallet.deposit',
            'wallet.withdraw',
            'wallet.transfer',
            'wallet.view-transactions',
            'wallet.export-report',
        ],
        'wallet-admin' => [
            'wallet.view-own',
            'wallet.deposit',
            'wallet.withdraw',
            'wallet.transfer',
            'wallet.view-transactions',
            'wallet.export-report',
            'wallet.view-all',
            'wallet.freeze',
            'wallet.unfreeze',
            'wallet.adjust-balance',
            'wallet.place-hold',
            'wallet.release-hold',
            'wallet.capture-hold',
            'wallet.reverse-transaction',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Infrastructure-level request throttling (RateLimiter facade), separate
    | from the business limits above. limit = max requests per decay window.
    |
    */
    'rate_limits' => [
        'wallet-user' => [
            'limit' => 30,
            'decay_minutes' => 1,
        ],
        'wallet-history' => [
            'limit' => 60,
            'decay_minutes' => 1,
        ],
        'wallet-admin' => [
            'limit' => 100,
            'decay_minutes' => 1,
        ],
    ],

];
