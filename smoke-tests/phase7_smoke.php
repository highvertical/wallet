<?php

declare(strict_types=1);

/**
 * Phase 7 smoke test: WalletPermissionSeeder against real Spatie
 * spatie/laravel-permission tables (SQLite in-memory), verifying seeded
 * permissions/roles and that Gate::allows() resolves them for real -
 * replacing the fake Gate::before stand-in phase6_smoke.php used ("stands
 * in for Spatie's real tables until Phase 7").
 *
 * Run from the my-package-app root: php packages/highvertical/wallet/smoke-tests/phase7_smoke.php
 * Uses this package's own vendor/autoload.php (composer install was run
 * standalone inside packages/highvertical/wallet), not the host app's -
 * so Highvertical\Wallet\*, Illuminate\*, and Spatie\Permission\* are all
 * autoloaded from a single place with no manual spl_autoload_register.
 */
require __DIR__.'/../vendor/autoload.php';

$app = new Illuminate\Foundation\Application();

$permissionConfig = require __DIR__.'/../vendor/spatie/laravel-permission/config/permission.php';

$app->singleton('config', function () use ($permissionConfig) {
    return new Illuminate\Config\Repository([
        'wallet' => require __DIR__.'/../config/wallet.php',
        'permission' => $permissionConfig,
        'auth' => [
            'defaults' => ['guard' => 'web', 'passwords' => 'users'],
            'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
            'providers' => ['users' => ['driver' => 'eloquent', 'model' => TestUser::class]],
        ],
        'cache' => [
            'default' => 'array',
            'stores' => ['array' => ['driver' => 'array']],
        ],
    ]);
});

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

$app->singleton('cache', function ($app) {
    return new Illuminate\Cache\CacheManager($app);
});

$app->singleton(Illuminate\Contracts\Auth\Access\Gate::class, function ($app) {
    return new Illuminate\Auth\Access\Gate($app, fn () => null);
});

$app->singleton(Spatie\Permission\PermissionRegistrar::class);

$db = $app['db'];

$db->connection()->getSchemaBuilder()->create('users', function (Illuminate\Database\Schema\Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->timestamps();
});

(require __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();

echo "Schema ready.\n";

final class TestUser extends Illuminate\Database\Eloquent\Model implements Illuminate\Contracts\Auth\Access\Authorizable
{
    use Spatie\Permission\Traits\HasRoles;
    use Illuminate\Foundation\Auth\Access\Authorizable;

    protected $table = 'users';
    protected $guarded = [];
}

$failures = 0;

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

function assertTrue(bool $condition, string $label, string $detail = ''): void
{
    $condition ? pass($label) : fail($label, $detail);
}

function assertSame($expected, $actual, string $label): void
{
    $expected === $actual
        ? pass($label)
        : fail($label, 'expected '.var_export($expected, true).' got '.var_export($actual, true));
}

// --- Seed once ---

echo "\n-- Seeding permissions and roles --\n";
(new Highvertical\Wallet\Database\Seeders\WalletPermissionSeeder())->run();

$allPermissions = Spatie\Permission\Models\Permission::pluck('name')->sort()->values()->all();
$expectedPermissions = collect(config('wallet.permissions'))->sort()->values()->all();
assertSame($expectedPermissions, $allPermissions, 'All 13 config-driven permissions seeded');

$roleNames = Spatie\Permission\Models\Role::pluck('name')->sort()->values()->all();
assertSame(['wallet-admin', 'wallet-user'], $roleNames, 'Both default roles seeded');

$userRole = Spatie\Permission\Models\Role::where('name', 'wallet-user')->first();
$userRolePerms = $userRole->permissions()->pluck('name')->sort()->values()->all();
assertSame(
    collect(config('wallet.roles.wallet-user'))->sort()->values()->all(),
    $userRolePerms,
    'wallet-user role has exactly its configured permission subset'
);

$adminRole = Spatie\Permission\Models\Role::where('name', 'wallet-admin')->first();
$adminRolePerms = $adminRole->permissions()->pluck('name')->sort()->values()->all();
assertSame($expectedPermissions, $adminRolePerms, 'wallet-admin role has all 13 permissions');

// --- Idempotency: re-run must not duplicate or error ---

echo "\n-- Re-running seeder (idempotency) --\n";
(new Highvertical\Wallet\Database\Seeders\WalletPermissionSeeder())->run();

assertSame(13, Spatie\Permission\Models\Permission::count(), 'Permission count still 13 after re-seed');
assertSame(2, Spatie\Permission\Models\Role::count(), 'Role count still 2 after re-seed');

// --- Real end-to-end: assign roles to real users, check via HasRoles + Gate ---

echo "\n-- Assigning roles to real users --\n";
$regularUser = TestUser::create(['name' => 'Regular']);
$adminUser = TestUser::create(['name' => 'Admin']);

$regularUser->assignRole('wallet-user');
$adminUser->assignRole('wallet-admin');

assertTrue($regularUser->hasPermissionTo('wallet.deposit'), 'wallet-user can deposit');
assertTrue(! $regularUser->hasPermissionTo('wallet.freeze'), 'wallet-user cannot freeze (admin-only)');
assertTrue($adminUser->hasPermissionTo('wallet.freeze'), 'wallet-admin can freeze');
assertTrue($adminUser->hasPermissionTo('wallet.view-all'), 'wallet-admin can view-all');

// Replicates the one Gate hook Spatie's own PermissionServiceProvider
// registers in packageBooted() (see callAfterResolving(Gate::class, ...))
// so Gate::allows()/$user->can() - what WalletPolicy actually calls - are
// exercised against real seeded data instead of the fake stand-in phase6
// used.
$gate = $app->make(Illuminate\Contracts\Auth\Access\Gate::class);
$app->make(Spatie\Permission\PermissionRegistrar::class)->registerPermissions($gate);

assertTrue($gate->forUser($regularUser)->allows('wallet.deposit'), 'Gate::allows resolves wallet-user deposit permission');
assertTrue($gate->forUser($regularUser)->denies('wallet.freeze'), 'Gate::denies blocks wallet-user from freeze');
assertTrue($gate->forUser($adminUser)->allows('wallet.freeze'), 'Gate::allows resolves wallet-admin freeze permission');

echo "\n".($failures === 0 ? "All checks passed.\n" : "{$failures} check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
