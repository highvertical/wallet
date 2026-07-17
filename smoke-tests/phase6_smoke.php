<?php

declare(strict_types=1);

require '/Applications/XAMPP/xamppfiles/htdocs/my-package-app/vendor/autoload.php';

spl_autoload_register(function (string $class) {
    $prefix = 'Highvertical\\Wallet\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = '/Applications/XAMPP/xamppfiles/htdocs/my-package-app/packages/highvertical/wallet/src/'.str_replace('\\', '/', $relative).'.php';
    if (is_file($path)) {
        require $path;
    }
});

$app = new Illuminate\Foundation\Application();

$app->singleton('config', function () {
    return new Illuminate\Config\Repository([
        'wallet' => require '/Applications/XAMPP/xamppfiles/htdocs/my-package-app/packages/highvertical/wallet/config/wallet.php',
        'app' => ['debug' => false],
        'logging' => [
            'default' => 'stack',
            'channels' => ['stack' => ['driver' => 'stack', 'channels' => []]],
        ],
        'queue' => [
            'default' => 'sync',
            'connections' => ['sync' => ['driver' => 'sync']],
        ],
        'cache' => [
            'default' => 'array',
            'stores' => ['array' => ['driver' => 'array']],
        ],
        'auth' => [
            'defaults' => ['guard' => 'testguard', 'passwords' => 'users'],
            'guards' => [
                'testguard' => ['driver' => 'testguard'],
            ],
        ],
    ]);
});

// HTTP layer needs a real request/route guard, so route to a token-based guard.
config(['wallet.auth_guard' => 'testguard']);

Illuminate\Support\Facades\Facade::setFacadeApplication($app);

$app->singleton('events', function ($app) {
    return new Illuminate\Events\Dispatcher($app);
});

$app->singleton('db', function ($app) {
    $capsule = new Illuminate\Database\Capsule\Manager($app);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setEventDispatcher($app['events']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    return $capsule->getDatabaseManager();
});

$app->alias('db', Illuminate\Database\ConnectionResolverInterface::class);
Illuminate\Database\Eloquent\Model::setConnectionResolver($app['db']);

$app->bind('db.schema', function ($app) {
    return $app['db']->connection()->getSchemaBuilder();
});

$db = $app['db'];

// Registered once at boot, like a real app's AppServiceProvider::boot() would -
// morph map must be set before any model is persisted, since holder_type/
// recipient_type columns store whatever getMorphClass() returns *at write time*.
Illuminate\Database\Eloquent\Relations\Relation::morphMap(['user' => TestUser::class]);

$app->singleton('log', function ($app) {
    return new Illuminate\Log\LogManager($app);
});

$app->singleton('cache', function ($app) {
    return new Illuminate\Cache\CacheManager($app);
});

$app->singleton(Illuminate\Cache\RateLimiter::class, function ($app) {
    return new Illuminate\Cache\RateLimiter($app->make('cache')->store());
});

$app->singleton('queue', function ($app) {
    $manager = new Illuminate\Queue\QueueManager($app);
    (new Illuminate\Queue\QueueServiceProvider($app))->registerConnectors($manager);

    return $manager;
});

$app['events']->setQueueResolver(function () use ($app) {
    return $app['queue'];
});

$app->singleton(Illuminate\Bus\Dispatcher::class, function ($app) {
    return new Illuminate\Bus\Dispatcher($app, function () use ($app) {
        return $app['queue'];
    });
});
$app->alias(Illuminate\Bus\Dispatcher::class, Illuminate\Contracts\Bus\Dispatcher::class);

$app->singleton(Illuminate\Notifications\ChannelManager::class, function ($app) {
    return new Illuminate\Notifications\ChannelManager($app);
});
$app->alias(Illuminate\Notifications\ChannelManager::class, Illuminate\Contracts\Notifications\Dispatcher::class);
$app->alias(Illuminate\Notifications\ChannelManager::class, Illuminate\Contracts\Notifications\Factory::class);

// --- HTTP-specific infrastructure (new for Phase 6) ---

$app->singleton('auth', function ($app) {
    return new Illuminate\Auth\AuthManager($app);
});
$app->alias('auth', Illuminate\Contracts\Auth\Factory::class);

// Lightweight bearer-token guard standing in for Sanctum: resolves the user
// straight from the Authorization header, real end-to-end (no mocking of Auth).
$app['auth']->viaRequest('testguard', function (Illuminate\Http\Request $request) {
    $token = $request->bearerToken();

    return $token ? TestUser::find($token) : null;
});

$app->singleton(Illuminate\Contracts\Auth\Access\Gate::class, function ($app) {
    return new Illuminate\Auth\Access\Gate($app, fn () => call_user_func($app['auth']->userResolver()));
});

// Bound before WalletServiceProvider::boot() runs, since registerExceptionRendering()
// resolves this during boot() to register the WalletException renderable() hook.
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, function ($app) {
    return new Illuminate\Foundation\Exceptions\Handler($app);
});

$app->singleton('files', function () {
    return new Illuminate\Filesystem\Filesystem();
});

$app->singleton('router', function ($app) {
    return new Illuminate\Routing\Router($app['events'], $app);
});
$app->alias('router', Illuminate\Routing\Router::class);
$app->alias('router', Illuminate\Contracts\Routing\Registrar::class);

// Non-singleton: rebuilt against whichever 'request' is currently bound, since
// FormRequest's failedValidation() unconditionally asks the redirector for a URL.
$app->bind('url', function ($app) {
    return new Illuminate\Routing\UrlGenerator($app['router']->getRoutes(), $app['request']);
});
$app->alias('url', Illuminate\Routing\UrlGenerator::class);

// Replicates Illuminate\Foundation\Providers\FormRequestServiceProvider::boot(),
// which is what actually makes FormRequest::rules()/authorize() get invoked and
// validated when a controller method type-hints one.
$app->afterResolving(Illuminate\Contracts\Validation\ValidatesWhenResolved::class, function ($resolved) {
    $resolved->validateResolved();
});
$app->resolving(Illuminate\Foundation\Http\FormRequest::class, function ($request, $app) {
    Illuminate\Foundation\Http\FormRequest::createFrom($app['request'], $request);
    $request->setContainer($app)->setRedirector($app->make('redirect'));
});

// Constructed directly (not via $app->make(Redirector::class)): Laravel's core
// registerCoreContainerAliases() aliases Redirector::class back to 'redirect',
// so resolving the FQCN from inside this closure would recurse into itself forever.
$app->singleton('redirect', function ($app) {
    return new Illuminate\Routing\Redirector($app->make('url'));
});

// This package never renders Blade views (JSON API only) - stubbed only so the
// container can autowire Illuminate\Routing\ResponseFactory's constructor,
// which every response()->json()/streamDownload() call in our Controllers
// resolves through.
$app->singleton('view', function () {
    return new class implements Illuminate\Contracts\View\Factory {
        public function exists($view) { return false; }
        public function file($path, $data = [], $mergeData = []) { throw new RuntimeException('View rendering is not used by this API package.'); }
        public function make($view, $data = [], $mergeData = []) { throw new RuntimeException('View rendering is not used by this API package.'); }
        public function share($key, $value = null) {}
        public function composer($views, $callback) {}
        public function creator($views, $callback) {}
        public function addNamespace($namespace, $hints) {}
        public function replaceNamespace($namespace, $hints) {}
    };
});
$app->alias('view', Illuminate\Contracts\View\Factory::class);

$app->singleton(Illuminate\Contracts\Routing\ResponseFactory::class, function ($app) {
    return new Illuminate\Routing\ResponseFactory($app['view'], $app->make('redirect'));
});

$app->singleton('translator', function () {
    return new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
});

$app->singleton('validator', function ($app) {
    return new Illuminate\Validation\Factory($app['translator'], $app);
});
$app->alias('validator', Illuminate\Contracts\Validation\Factory::class);

$router = $app['router'];
$router->aliasMiddleware('auth', Illuminate\Auth\Middleware\Authenticate::class);
$router->aliasMiddleware('can', Illuminate\Auth\Middleware\Authorize::class);
$router->aliasMiddleware('throttle', Illuminate\Routing\Middleware\ThrottleRequests::class);
$router->middlewareGroup('api', [Illuminate\Routing\Middleware\SubstituteBindings::class]);

// Bind wallet's Domain contracts to their default Infrastructure implementations,
// same as WalletServiceProvider::register() does in the real app.
$app->bind(
    Highvertical\Wallet\Domain\Contracts\WalletRepository::class,
    Highvertical\Wallet\Infrastructure\Repositories\EloquentWalletRepository::class
);
$app->bind(
    Highvertical\Wallet\Domain\Contracts\FeeCalculator::class,
    Highvertical\Wallet\Infrastructure\FeeCalculators\ConfigDrivenFeeCalculator::class
);
$app->bind(
    Highvertical\Wallet\Domain\Contracts\LimitPolicy::class,
    Highvertical\Wallet\Infrastructure\LimitPolicies\RollingWindowLimitPolicy::class
);

// WalletManager is what the Controllers ask the container for.
$app->singleton(Highvertical\Wallet\Application\WalletManager::class, function ($app) {
    $repo = $app->make(Highvertical\Wallet\Domain\Contracts\WalletRepository::class);
    $locker = new Highvertical\Wallet\Application\Services\WalletLocker($repo);
    $feeResolver = new Highvertical\Wallet\Application\Services\FeeResolver($app->make(Highvertical\Wallet\Domain\Contracts\FeeCalculator::class));
    $limitEnforcer = new Highvertical\Wallet\Application\Services\LimitEnforcer($app->make(Highvertical\Wallet\Domain\Contracts\LimitPolicy::class));

    return new Highvertical\Wallet\Application\WalletManager(
        new Highvertical\Wallet\Application\Actions\DepositFundsAction($repo, $locker, $limitEnforcer),
        new Highvertical\Wallet\Application\Actions\WithdrawFundsAction($repo, $locker, $feeResolver, $limitEnforcer),
        new Highvertical\Wallet\Application\Actions\TransferFundsAction($repo, $locker, $feeResolver, $limitEnforcer),
        new Highvertical\Wallet\Application\Actions\PlaceHoldAction($locker),
        new Highvertical\Wallet\Application\Actions\ReleaseHoldAction($locker),
        new Highvertical\Wallet\Application\Actions\CaptureHoldAction($locker),
        new Highvertical\Wallet\Application\Actions\ReverseTransactionAction($locker),
        new Highvertical\Wallet\Application\Actions\FreezeWalletAction($locker),
        new Highvertical\Wallet\Application\Actions\UnfreezeWalletAction($locker),
        new Highvertical\Wallet\Application\Actions\AdjustBalanceAction($locker)
    );
});

foreach (glob('/Applications/XAMPP/xamppfiles/htdocs/my-package-app/packages/highvertical/wallet/database/migrations/*.php') as $file) {
    (require $file)->up();
}

$db->connection()->getSchemaBuilder()->create('users', function (Illuminate\Database\Schema\Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->timestamps();
});

$db->connection()->getSchemaBuilder()->create('notifications', function (Illuminate\Database\Schema\Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('type');
    $table->morphs('notifiable');
    $table->text('data');
    $table->timestamp('read_at')->nullable();
    $table->timestamps();
});

echo "Schema ready.\n";

/**
 * @property int $id
 */
final class TestUser extends Illuminate\Database\Eloquent\Model implements Highvertical\Wallet\Domain\Contracts\Walletable, Illuminate\Contracts\Auth\Authenticatable
{
    use Highvertical\Wallet\Traits\HasWallet;
    use Illuminate\Notifications\Notifiable;
    use Illuminate\Auth\Authenticatable;
    use Illuminate\Foundation\Auth\Access\Authorizable;

    protected $table = 'users';
    protected $guarded = [];

    /** @var array<int, array<int, string>> Simulated permission store, keyed by user id (stands in for Spatie's real tables until Phase 7). */
    public static array $permissionStore = [];

    public function grantedPermissions(): array
    {
        return self::$permissionStore[$this->getKey()] ?? [];
    }
}

function grant(TestUser $user, array $permissions): void
{
    TestUser::$permissionStore[$user->getKey()] = $permissions;
}

function pass(string $label): void
{
    echo "  [PASS] {$label}\n";
}

function fail(string $label, string $detail = ''): void
{
    echo "  [FAIL] {$label} {$detail}\n";
    global $failures;
    $failures++;
}

function assertStatus(Symfony\Component\HttpFoundation\Response $response, int $expected, string $label): void
{
    if ($response->getStatusCode() === $expected) {
        pass($label);
    } else {
        fail($label, "expected {$expected} got {$response->getStatusCode()}: ".substr($response->getContent(), 0, 300));
    }
}

function json(Symfony\Component\HttpFoundation\Response $response): array
{
    return json_decode($response->getContent(), true) ?? [];
}

$failures = 0;

// Real user-permission gate: stands in for Spatie's Gate::before hook (Phase 7
// installs the real package + seeder) - returning null defers to normal Gate
// resolution so Policy-backed abilities like "view" still fall through correctly.
Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
    return in_array($ability, $user->grantedPermissions(), true) ? true : null;
});

// Real provider boot: registers routes, rate limiters, event listeners,
// policies, and exception rendering exactly as the host app's own bootstrap would.
$provider = new Highvertical\Wallet\Providers\WalletServiceProvider($app);
$provider->register();
$provider->boot();

echo 'Routes registered: '.count($router->getRoutes())."\n";

/**
 * Dispatches a real HTTP request through the router/middleware pipeline,
 * mirroring what Illuminate\Foundation\Http\Kernel::handle() does, since we
 * don't have a full Kernel in this hand-assembled container.
 */
function request(string $method, string $uri, array $data = [], ?TestUser $actingAs = null): Symfony\Component\HttpFoundation\Response
{
    global $app;

    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($actingAs !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$actingAs->getKey();
    }

    $query = $method === 'GET' ? $data : [];
    $content = $method === 'GET' ? null : json_encode($data);

    $req = Illuminate\Http\Request::create($uri, $method, $query, [], [], $server, $content);
    $req->setUserResolver(function ($guard = null) use ($app) {
        return $app['auth']->guard($guard)->user();
    });

    $app->instance('request', $req);

    // RequestGuard caches the resolved user for its lifetime (see RequestGuard::user()),
    // and AuthManager memoizes guard instances across calls - both correct for a real
    // one-request-per-process Kernel, but our single long-running process dispatches
    // many simulated requests, so the guard must be rebuilt each time or every request
    // after the first authenticated one would keep resolving to that same cached user.
    $app['auth']->forgetGuards();

    try {
        return $app['router']->dispatch($req);
    } catch (Throwable $e) {
        return $app->make(Illuminate\Contracts\Debug\ExceptionHandler::class)->render($req, $e);
    }
}

// ============================================================
// Setup: users + permissions
// ============================================================
$alice = TestUser::create(['name' => 'Alice']);
$bob = TestUser::create(['name' => 'Bob']);
$admin = TestUser::create(['name' => 'Admin']);
$rateUser = TestUser::create(['name' => 'RateTester']);

grant($alice, ['wallet.view-own', 'wallet.deposit', 'wallet.withdraw', 'wallet.transfer', 'wallet.view-transactions', 'wallet.export-report']);
grant($bob, ['wallet.view-own', 'wallet.deposit', 'wallet.withdraw', 'wallet.transfer', 'wallet.view-transactions']);
grant($admin, ['wallet.view-all', 'wallet.freeze', 'wallet.unfreeze', 'wallet.adjust-balance', 'wallet.place-hold', 'wallet.release-hold', 'wallet.reverse-transaction']);
grant($rateUser, ['wallet.view-own']);

// ============================================================
// 1. Authentication
// ============================================================
echo "\n--- Authentication ---\n";
assertStatus(request('GET', '/wallet'), 401, 'unauthenticated request to a protected route is rejected');
assertStatus(request('GET', '/wallet', [], $alice), 200, 'authenticated request with wallet.view-own succeeds');

// ============================================================
// 2. Self-service: deposit / withdraw / validation
// ============================================================
echo "\n--- Self-service deposit/withdraw ---\n";
$res = request('POST', '/wallet/deposit', ['amount' => '100.00', 'currency' => 'NGN'], $alice);
assertStatus($res, 201, 'deposit as authorized user returns 201 (JsonResource wraps a freshly-created model)');
$body = json($res);
if (($body['data']['amount'] ?? null) === '100.00' && ($body['data']['type'] ?? null) === 'credit') {
    pass('deposit response resource has correct amount/type');
} else {
    fail('deposit response shape wrong', json_encode($body));
}
$aliceWallet = $alice->wallet();
if ($aliceWallet && $aliceWallet->balance === 10000) {
    pass('deposit persisted balance correctly through the HTTP layer');
} else {
    fail('deposit balance mismatch', (string) ($aliceWallet->balance ?? 'null'));
}

$res = request('POST', '/wallet/withdraw', ['amount' => '30.00', 'currency' => 'NGN'], $alice);
assertStatus($res, 200, 'withdraw as authorized user returns 200');
$aliceWallet->refresh();
if ($aliceWallet->balance === 7000) {
    pass('withdraw persisted balance correctly through the HTTP layer');
} else {
    fail('withdraw balance mismatch', (string) $aliceWallet->balance);
}

$res = request('POST', '/wallet/deposit', ['amount' => 'not-a-number', 'currency' => 'NGN'], $alice);
assertStatus($res, 422, 'malformed amount fails Form Request validation with 422');

$res = request('POST', '/wallet/withdraw', ['amount' => '999999.00', 'currency' => 'NGN'], $alice);
assertStatus($res, 402, 'withdrawing beyond balance renders InsufficientFundsException as 402 via registerExceptionRendering()');
if ((json($res)['message'] ?? '') !== '') {
    pass('WalletException JSON body carries a message');
} else {
    fail('WalletException JSON body missing message');
}

// Permission enforcement: strip withdraw permission and confirm 403.
grant($alice, ['wallet.view-own', 'wallet.deposit', 'wallet.transfer', 'wallet.view-transactions', 'wallet.export-report']);
assertStatus(request('POST', '/wallet/withdraw', ['amount' => '1.00', 'currency' => 'NGN'], $alice), 403, 'removing wallet.withdraw permission is enforced by the can: route middleware');
grant($alice, ['wallet.view-own', 'wallet.deposit', 'wallet.withdraw', 'wallet.transfer', 'wallet.view-transactions', 'wallet.export-report']);

// ============================================================
// 3. Transfer (self-type default + morph map + rejection of unknown types)
// ============================================================
echo "\n--- Transfer ---\n";
request('POST', '/wallet/deposit', ['amount' => '1.00', 'currency' => 'NGN'], $bob); // give Bob an NGN wallet

$res = request('POST', '/wallet/transfer', ['recipient_id' => $bob->id, 'amount' => '10.00', 'currency' => 'NGN'], $alice);
assertStatus($res, 200, 'transfer with default (same-class) recipient_type succeeds');
$body = json($res);
if (isset($body['transfer'], $body['debit_transaction'], $body['credit_transaction'])) {
    pass('transfer response contains transfer/debit_transaction/credit_transaction resources');
} else {
    fail('transfer response shape wrong', json_encode($body));
}

$res = request('POST', '/wallet/transfer', ['recipient_id' => $bob->id, 'recipient_type' => 'user', 'amount' => '1.00', 'currency' => 'NGN'], $alice);
assertStatus($res, 200, 'transfer with a recipient_type resolved through the morph map succeeds');

$res = request('POST', '/wallet/transfer', ['recipient_id' => $bob->id, 'recipient_type' => 'App\\Models\\NotRegistered', 'amount' => '1.00', 'currency' => 'NGN'], $alice);
assertStatus($res, 422, 'an unregistered recipient_type is rejected (422), never instantiated from raw user input');

// ============================================================
// 4. Transaction history: ownership scoping + WalletTransactionPolicy
// ============================================================
echo "\n--- Transaction history + WalletTransactionPolicy ---\n";
$aliceTx = Highvertical\Wallet\Infrastructure\Models\WalletTransaction::query()
    ->whereHas('wallet', fn ($q) => $q->where('holder_id', $alice->id)->where('holder_type', $alice->getMorphClass()))
    ->where('type', 'credit')
    ->first();

assertStatus(request('GET', "/wallet/transactions/{$aliceTx->id}", [], $alice), 200, 'owner can view their own transaction by id');
assertStatus(request('GET', "/wallet/transactions/{$aliceTx->id}", [], $bob), 403, 'a different authenticated user (with wallet.view-transactions but no ownership/view-all) cannot enumerate another user\'s transaction by id');
assertStatus(request('GET', "/wallet/transactions/{$aliceTx->id}", [], $admin), 200, 'wallet.view-all holder can view any transaction');

$res = request('GET', '/wallet/transactions', [], $alice);
assertStatus($res, 200, 'own transaction history listing succeeds');
$listedWalletIds = array_column(json($res)['data'] ?? [], 'wallet_id');
$bobWalletId = $bob->wallet()->id;
if (! in_array($bobWalletId, $listedWalletIds, true)) {
    pass('transaction history is scoped to the caller\'s own wallet only');
} else {
    fail('transaction history leaked another holder\'s wallet_id');
}

$res = request('GET', '/wallet/transactions-export', [], $alice);
assertStatus($res, 200, 'CSV export endpoint returns 200');
if (str_contains((string) $res->headers->get('Content-Type'), 'text/csv')) {
    pass('CSV export responds with text/csv content type');
} else {
    fail('CSV export content type wrong', (string) $res->headers->get('Content-Type'));
}

// ============================================================
// 5. WalletPolicy: admin wallet show is owner-or-view-all
// ============================================================
echo "\n--- WalletPolicy (admin wallets.show) ---\n";
assertStatus(request('GET', "/wallet/admin/wallets/{$aliceWallet->id}", [], $alice), 200, 'owner can view their own wallet via the admin show route');
assertStatus(request('GET', "/wallet/admin/wallets/{$aliceWallet->id}", [], $bob), 403, 'a non-owning, non-view-all user cannot view another holder\'s wallet');
assertStatus(request('GET', "/wallet/admin/wallets/{$aliceWallet->id}", [], $admin), 200, 'wallet.view-all holder can view any wallet');

// ============================================================
// 6. Admin mutations: freeze/unfreeze, adjust, holds, reversal
// ============================================================
echo "\n--- Admin: freeze/unfreeze ---\n";
assertStatus(request('POST', "/wallet/admin/wallets/{$aliceWallet->id}/freeze", ['reason' => 'suspicious activity'], $admin), 200, 'admin freeze succeeds');
$res = request('POST', '/wallet/deposit', ['amount' => '1.00', 'currency' => 'NGN'], $alice);
assertStatus($res, 423, 'depositing into a frozen wallet renders WalletNotUsableException as 423');
assertStatus(request('POST', "/wallet/admin/wallets/{$aliceWallet->id}/unfreeze", [], $admin), 200, 'admin unfreeze succeeds');
$aliceWallet->refresh();
if ($aliceWallet->status === 'active') {
    pass('wallet is active again after unfreeze');
} else {
    fail('wallet not restored to active after unfreeze');
}

echo "\n--- Admin: adjust balance ---\n";
$res = request('POST', "/wallet/admin/wallets/{$aliceWallet->id}/adjust", ['amount' => '-5.00', 'reason' => 'manual correction'], $admin);
assertStatus($res, 201, 'admin adjust-balance succeeds (JsonResource wraps a freshly-created transaction)');
if ((json($res)['data']['type'] ?? null) === 'debit') {
    pass('negative adjustment records as a debit transaction');
} else {
    fail('adjustment transaction type wrong', json_encode(json($res)));
}

echo "\n--- Admin: holds ---\n";
$res = request('POST', "/wallet/admin/wallets/{$aliceWallet->id}/holds", ['amount' => '5.00', 'reason' => 'pending order'], $admin);
assertStatus($res, 201, 'admin place-hold succeeds (JsonResource wraps a freshly-created hold)');
$holdId = json($res)['data']['id'] ?? null;

$res = request('POST', "/wallet/admin/holds/{$holdId}/release", [], $admin);
assertStatus($res, 200, 'admin release-hold succeeds');
if ((json($res)['data']['status'] ?? null) === 'released') {
    pass('released hold reflects released status');
} else {
    fail('hold status wrong after release', json_encode(json($res)));
}

$res = request('POST', "/wallet/admin/wallets/{$aliceWallet->id}/holds", ['amount' => '3.00', 'reason' => 'second hold'], $admin);
$holdId2 = json($res)['data']['id'] ?? null;
$res = request('POST', "/wallet/admin/holds/{$holdId2}/capture", [], $admin);
assertStatus($res, 200, 'admin capture-hold succeeds (shares wallet.release-hold permission by design)');
if (($body = json($res)) && ($body['hold']['status'] ?? null) === 'captured' && isset($body['transaction'])) {
    pass('captured hold returns hold + transaction resources');
} else {
    fail('hold capture response shape wrong', json_encode($body));
}

echo "\n--- Admin: reverse transaction ---\n";
// Top up first: the withdrawals/transfers/adjustment/hold-capture above have
// drawn the wallet down well below the original $100 deposit, and reversing a
// credit debits the wallet by that same amount - so without this the reversal
// would correctly (but inconveniently for this positive-path test) fail with
// InsufficientFundsException.
request('POST', '/wallet/deposit', ['amount' => '100.00', 'currency' => 'NGN'], $alice);
$res = request('POST', "/wallet/admin/transactions/{$aliceTx->id}/reverse", ['reason' => 'chargeback'], $admin);
assertStatus($res, 201, 'admin reverse-transaction succeeds (JsonResource wraps a freshly-created reversal transaction)');
if ((json($res)['data']['type'] ?? null) === 'debit') {
    pass('reversing a credit produces a debit transaction');
} else {
    fail('reversal transaction type wrong', json_encode(json($res)));
}

// ============================================================
// 7. Admin flat-permission enforcement (non-admin denied)
// ============================================================
echo "\n--- Admin permission enforcement ---\n";
assertStatus(request('POST', "/wallet/admin/wallets/{$aliceWallet->id}/freeze", ['reason' => 'test'], $alice), 403, 'a regular user without wallet.freeze cannot freeze any wallet');

// ============================================================
// 8. Rate limiting (throttle:wallet-user, 30/min)
// ============================================================
echo "\n--- Rate limiting ---\n";
$limit = (int) config('wallet.rate_limits.wallet-user.limit');
$last = null;
for ($i = 0; $i <= $limit; $i++) {
    $last = request('GET', '/wallet', [], $rateUser);
}
assertStatus($last, 429, "the ".($limit + 1)."th request within a minute is throttled by throttle:wallet-user");

echo "\n============================\n";
echo $failures === 0 ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n";
echo "============================\n";
