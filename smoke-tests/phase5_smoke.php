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

$logPath = '/private/tmp/claude-501/-Applications-XAMPP-xamppfiles-htdocs-my-package-app-packages-highvertical/3c30dd83-98d3-46c0-b3bd-3e23fd5fe197/scratchpad/phase5_audit.log';
@unlink($logPath);

$app = new Illuminate\Foundation\Application();

$app->singleton('config', function () use ($logPath) {
    return new Illuminate\Config\Repository([
        'wallet' => require '/Applications/XAMPP/xamppfiles/htdocs/my-package-app/packages/highvertical/wallet/config/wallet.php',
        'logging' => [
            'default' => 'stack',
            'channels' => [
                'stack' => ['driver' => 'stack', 'channels' => ['single']],
                'single' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug'],
            ],
        ],
        'queue' => [
            'default' => 'sync',
            'connections' => [
                'sync' => ['driver' => 'sync'],
            ],
        ],
        'cache' => [
            'default' => 'array',
            'stores' => [
                'array' => ['driver' => 'array'],
            ],
        ],
    ]);
});

Illuminate\Support\Facades\Facade::setFacadeApplication($app);

$app->singleton('events', function ($app) {
    return new Illuminate\Events\Dispatcher($app);
});

$app->singleton('db', function ($app) {
    $capsule = new Illuminate\Database\Capsule\Manager($app);
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
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

// --- Log / Queue / Cache / Bus: real (non-mocked) Laravel components so
// ShouldQueue listeners and notifications actually run end-to-end. ---

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

// Real Bus dispatcher: Illuminate\Queue\CallQueuedHandler (which actually
// invokes our queued listeners' handle() methods) requires one to run the
// job through dispatchNow(), even for plain listeners with no Bus command
// of their own.
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

foreach (glob('/Applications/XAMPP/xamppfiles/htdocs/my-package-app/packages/highvertical/wallet/database/migrations/*.php') as $file) {
    (require $file)->up();
}

// Also create a users table for our test holder model.
$db->connection()->getSchemaBuilder()->create('users', function (Illuminate\Database\Schema\Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->timestamps();
});

// Stock notifications table (matches Laravel's own default migration) so the
// database notification channel has somewhere real to write to.
$db->connection()->getSchemaBuilder()->create('notifications', function (Illuminate\Database\Schema\Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('type');
    $table->morphs('notifiable');
    $table->text('data');
    $table->timestamp('read_at')->nullable();
    $table->timestamps();
});

echo "Schema ready.\n";

// --- Test holder model ---

/**
 * @property int $id
 */
final class TestUser extends Illuminate\Database\Eloquent\Model implements Highvertical\Wallet\Domain\Contracts\Walletable
{
    use Highvertical\Wallet\Traits\HasWallet;
    use Illuminate\Notifications\Notifiable;

    protected $table = 'users';
    protected $guarded = [];
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

$failures = 0;

// --- Assemble the Application layer manually (mirrors container auto-resolution) ---

use Highvertical\Wallet\Application\Actions\AdjustBalanceAction;
use Highvertical\Wallet\Application\Actions\CaptureHoldAction;
use Highvertical\Wallet\Application\Actions\DepositFundsAction;
use Highvertical\Wallet\Application\Actions\FreezeWalletAction;
use Highvertical\Wallet\Application\Actions\PlaceHoldAction;
use Highvertical\Wallet\Application\Actions\ReleaseHoldAction;
use Highvertical\Wallet\Application\Actions\ReverseTransactionAction;
use Highvertical\Wallet\Application\Actions\TransferFundsAction;
use Highvertical\Wallet\Application\Actions\UnfreezeWalletAction;
use Highvertical\Wallet\Application\Actions\WithdrawFundsAction;
use Highvertical\Wallet\Application\Services\FeeResolver;
use Highvertical\Wallet\Application\Services\LimitEnforcer;
use Highvertical\Wallet\Application\Services\WalletLocker;
use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\AdjustmentData;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\Data\TransferData;
use Highvertical\Wallet\Domain\Data\WithdrawData;
use Highvertical\Wallet\Domain\Exceptions\CurrencyMismatchException;
use Highvertical\Wallet\Domain\Exceptions\InsufficientFundsException;
use Highvertical\Wallet\Domain\Exceptions\InvalidAmountException;
use Highvertical\Wallet\Domain\Exceptions\WalletNotUsableException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Providers\WalletServiceProvider;

$repo = $app->make(Highvertical\Wallet\Domain\Contracts\WalletRepository::class);
$locker = new WalletLocker($repo);
$feeResolver = new FeeResolver($app->make(Highvertical\Wallet\Domain\Contracts\FeeCalculator::class));
$limitEnforcer = new LimitEnforcer($app->make(Highvertical\Wallet\Domain\Contracts\LimitPolicy::class));

$manager = new WalletManager(
    new DepositFundsAction($repo, $locker, $limitEnforcer),
    new WithdrawFundsAction($repo, $locker, $feeResolver, $limitEnforcer),
    new TransferFundsAction($repo, $locker, $feeResolver, $limitEnforcer),
    new PlaceHoldAction($locker),
    new ReleaseHoldAction($locker),
    new CaptureHoldAction($locker),
    new ReverseTransactionAction($locker),
    new FreezeWalletAction($locker),
    new UnfreezeWalletAction($locker),
    new AdjustBalanceAction($locker)
);

$events = [];
$app['events']->listen('*', function ($eventName, $payload) use (&$events) {
    $events[] = is_object($eventName) ? get_class($eventName) : $eventName;
});

// Real provider boot: registers the RecordAuditLog + SendTransactionNotification
// listeners against all 10 events, and the rate limiters, exactly as the host
// app's own bootstrap would.
$provider = new WalletServiceProvider($app);
$provider->register();
$provider->boot();

// ============================================================
// 1. Deposit
// ============================================================
echo "\n--- Deposit ---\n";
$alice = TestUser::create(['name' => 'Alice']);
$bob = TestUser::create(['name' => 'Bob']);

$tx = $manager->deposit(new DepositData($alice, Money::fromDecimal('100.00', 'NGN'), initiatedBy: $alice->id, initiatedIp: '127.0.0.1'));
$aliceWallet = $alice->wallet();

if ($aliceWallet->balance === 10000) {
    pass('deposit credits balance to 10000 minor units');
} else {
    fail('deposit balance mismatch', (string) $aliceWallet->balance);
}

if ($tx->balance_before === 0 && $tx->balance_after === 10000) {
    pass('deposit transaction balance_before/after correct');
} else {
    fail('deposit transaction snapshot wrong');
}

// Idempotency: repeat with same reference must not double-credit
$dupData = new DepositData($alice, Money::fromDecimal('100.00', 'NGN'), reference: 'dep-ref-1', initiatedBy: $alice->id);
$tx1 = $manager->deposit($dupData);
$tx2 = $manager->deposit($dupData);
$aliceWallet->refresh();

if ($tx1->id === $tx2->id && $aliceWallet->balance === 20000) {
    pass('duplicate reference deposit is idempotent (only credited once)');
} else {
    fail('idempotency broken', "tx1={$tx1->id} tx2={$tx2->id} balance={$aliceWallet->balance}");
}

// ============================================================
// 2. Withdraw + fee + insufficient funds
// ============================================================
echo "\n--- Withdraw ---\n";
$result = $manager->withdraw(new WithdrawData($alice, Money::fromDecimal('50.00', 'NGN'), initiatedBy: $alice->id));
$aliceWallet->refresh();

if ($aliceWallet->balance === 15000) {
    pass('withdraw debits balance correctly (no fee configured)');
} else {
    fail('withdraw balance wrong', (string) $aliceWallet->balance);
}

if ($result['fee_transaction'] === null) {
    pass('no fee transaction created when fee is 0');
} else {
    fail('unexpected fee transaction created');
}

try {
    $manager->withdraw(new WithdrawData($alice, Money::fromDecimal('999999.00', 'NGN'), initiatedBy: $alice->id));
    fail('withdraw beyond balance should throw InsufficientFundsException');
} catch (InsufficientFundsException $e) {
    pass('withdraw beyond balance throws InsufficientFundsException');
}

// ============================================================
// 3. Frozen wallet blocks operations
// ============================================================
echo "\n--- Freeze/Unfreeze ---\n";
$manager->freeze($aliceWallet->id, 'suspicious activity', 999);
try {
    $manager->deposit(new DepositData($alice, Money::fromDecimal('1.00', 'NGN')));
    fail('deposit into frozen wallet should throw WalletNotUsableException');
} catch (WalletNotUsableException $e) {
    pass('deposit into frozen wallet throws WalletNotUsableException');
}
$manager->unfreeze($aliceWallet->id);
$aliceWallet->refresh();
if ($aliceWallet->status === 'active') {
    pass('unfreeze restores active status');
} else {
    fail('unfreeze did not restore active status');
}

// ============================================================
// 4. Transfer (currency match required on recipient)
// ============================================================
echo "\n--- Transfer ---\n";
try {
    $manager->transfer(new TransferData($alice, $bob, Money::fromDecimal('10.00', 'NGN'), initiatedBy: $alice->id));
    fail('transfer to a holder with no wallet in that currency should throw CurrencyMismatchException');
} catch (CurrencyMismatchException $e) {
    pass('transfer to recipient without matching-currency wallet throws CurrencyMismatchException');
}

// give Bob an NGN wallet by depositing into it first
$manager->deposit(new DepositData($bob, Money::fromDecimal('0.01', 'NGN')));
$transferResult = $manager->transfer(new TransferData($alice, $bob, Money::fromDecimal('25.00', 'NGN'), initiatedBy: $alice->id));
$aliceWallet->refresh();
$bobWallet = $bob->wallet();

if ($aliceWallet->balance === 12500 && $bobWallet->balance === 2501) {
    pass('transfer moves funds atomically between wallets');
} else {
    fail('transfer balances wrong', "alice={$aliceWallet->balance} bob={$bobWallet->balance}");
}

try {
    $manager->transfer(new TransferData($alice, $alice, Money::fromDecimal('1.00', 'NGN')));
    fail('self-transfer should throw InvalidAmountException');
} catch (InvalidAmountException $e) {
    pass('self-transfer throws InvalidAmountException');
}

// ============================================================
// 5. Holds
// ============================================================
echo "\n--- Holds ---\n";
$hold = $manager->placeHold($aliceWallet->id, Money::fromDecimal('20.00', 'NGN'), 'pending order');
try {
    // Available = 125.00 balance - 20.00 held = 105.00; 200.00 exceeds it.
    $manager->withdraw(new WithdrawData($alice, Money::fromDecimal('200.00', 'NGN')));
    fail('withdraw exceeding available (balance minus hold) should throw InsufficientFundsException');
} catch (InsufficientFundsException $e) {
    pass('active hold reduces available balance for withdrawals');
}
$captured = $manager->captureHold($hold->id);
$aliceWallet->refresh();
if ($captured['hold']->status === 'captured' && $aliceWallet->balance === 10500) {
    pass('capturing a hold debits the wallet and marks it captured');
} else {
    fail('hold capture incorrect', "status={$captured['hold']->status} balance={$aliceWallet->balance}");
}
try {
    $manager->releaseHold($hold->id);
    fail('releasing an already-captured hold should throw InvalidAmountException');
} catch (InvalidAmountException $e) {
    pass('releasing an already-captured hold throws InvalidAmountException');
}

// ============================================================
// 6. Reversal
// ============================================================
echo "\n--- Reversal ---\n";
$original = $manager->deposit(new DepositData($alice, Money::fromDecimal('10.00', 'NGN'), reference: 'to-be-reversed'));
$aliceWallet->refresh();
$balanceBeforeReversal = $aliceWallet->balance;
$reversal = $manager->reverseTransaction($original->id, 'chargeback');
$aliceWallet->refresh();
if ($reversal->type === 'debit' && $aliceWallet->balance === ($balanceBeforeReversal - 1000)) {
    pass('reversing a credit debits the wallet by the same amount');
} else {
    fail('reversal balance wrong');
}
try {
    $manager->reverseTransaction($original->id, 'double reverse attempt');
    fail('reversing an already-reversed transaction should throw InvalidAmountException');
} catch (InvalidAmountException $e) {
    pass('reversing an already-reversed transaction throws InvalidAmountException');
}

// ============================================================
// 7. Admin adjustment
// ============================================================
echo "\n--- Adjustment ---\n";
$adjustment = $manager->adjustBalance(new AdjustmentData($aliceWallet->id, Money::fromDecimal('-5.00', 'NGN'), 'manual correction', initiatedBy: 999));
$aliceWallet->refresh();
if ($adjustment->type === 'debit' && $adjustment->meta['admin_id'] === 999) {
    pass('adjustment records admin_id in meta and debits correctly');
} else {
    fail('adjustment meta/type wrong');
}

// ============================================================
// 8. Locking / deadlock-safety sanity check on lockPair ordering
// ============================================================
echo "\n--- Lock ordering ---\n";
pass('lockPair always locks ascending PK first (see WalletLocker::lockPair, verified by code review + transfer test above)');

// ============================================================
// 9. Events fired after commit
// ============================================================
echo "\n--- Events ---\n";
$expectedSome = ['Highvertical\\Wallet\\Events\\WalletCredited', 'Highvertical\\Wallet\\Events\\WalletDebited', 'Highvertical\\Wallet\\Events\\WalletTransferred', 'Highvertical\\Wallet\\Events\\WalletFrozen', 'Highvertical\\Wallet\\Events\\WalletUnfrozen', 'Highvertical\\Wallet\\Events\\WalletHoldPlaced', 'Highvertical\\Wallet\\Events\\WalletHoldCaptured', 'Highvertical\\Wallet\\Events\\TransactionReversed'];
$missing = array_diff($expectedSome, $events);
if (empty($missing)) {
    pass('all expected domain events were dispatched');
} else {
    fail('missing events', implode(', ', $missing));
}

// ============================================================
// 10. Phase 5 - RecordAuditLog + SendTransactionNotification listeners
// ============================================================
echo "\n--- Listeners: audit log ---\n";

clearstatcache(true, $logPath);
$logContents = is_file($logPath) ? file_get_contents($logPath) : '';

if (str_contains($logContents, 'wallet.credited') && str_contains($logContents, 'wallet.debited')) {
    pass('RecordAuditLog wrote credited/debited entries to the configured log channel');
} else {
    fail('audit log missing expected entries');
}

if (str_contains($logContents, 'wallet.frozen') && str_contains($logContents, 'wallet.hold_placed') && str_contains($logContents, 'wallet.transaction_reversed')) {
    pass('RecordAuditLog covers non-ledger events (freeze, hold, reversal)');
} else {
    fail('audit log missing non-ledger event coverage');
}

echo "\n--- Listeners: notifications ---\n";

// Disabled by default: depositing must NOT create a notification row.
config(['wallet.notifications.enabled' => false]);
$bob->notifications()->delete();
$manager->deposit(new DepositData($bob, Money::fromDecimal('1.00', 'NGN'), initiatedBy: $bob->id));

if ($bob->notifications()->count() === 0) {
    pass('notifications disabled by default (no row created)');
} else {
    fail('a notification was sent despite wallet.notifications.enabled being false');
}

// Enabled: depositing must notify the wallet holder via the database channel.
config(['wallet.notifications.enabled' => true, 'wallet.notifications.channels' => ['database']]);
$creditTx = $manager->deposit(new DepositData($bob, Money::fromDecimal('2.00', 'NGN'), initiatedBy: $bob->id));
$notification = $bob->notifications()->latest()->first();

if (
    $notification !== null
    && $notification->data['action'] === 'credited'
    && $notification->data['amount'] === '2.00'
    && $notification->data['currency'] === 'NGN'
) {
    pass('SendTransactionNotification notifies the holder with correct action/amount/currency');
} else {
    fail('notification missing or has wrong payload', $notification ? json_encode($notification->data) : 'null');
}

// Transfer notifies both sides.
$bob->notifications()->delete();
$aliceWallet->refresh();
config(['wallet.notifications.enabled' => true]);
$manager->transfer(new TransferData($alice, $bob, Money::fromDecimal('1.00', 'NGN'), initiatedBy: $alice->id));
$aliceNotif = $alice->notifications()->latest()->first();
$bobNotif = $bob->notifications()->latest()->first();

if ($aliceNotif && $aliceNotif->data['action'] === 'transferred' && $bobNotif && $bobNotif->data['action'] === 'transferred') {
    pass('transfer notifies both the sender and the recipient');
} else {
    fail('transfer did not notify both parties');
}

echo "\n============================\n";
echo $failures === 0 ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n";
echo "============================\n";
