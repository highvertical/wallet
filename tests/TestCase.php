<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests;

use Highvertical\Wallet\Providers\WalletServiceProvider;
use Highvertical\Wallet\Tests\Support\TestUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            WalletServiceProvider::class,
            PermissionServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => TestUser::class]);

        // No Sanctum installed; the package only needs an Authenticatable
        // guard, so the real "web" guard stands in here (mirrors how the
        // Phase 6/7 smoke tests used their own guard for the same reason).
        $app['config']->set('wallet.auth_guard', 'web');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Wallet's own migrations: WalletServiceProvider::boot() only
        // registers this path with the Migrator (Illuminate's real
        // loadMigrationsFrom is registration-only), so it's run for real
        // here via Testbench's own immediate-executing loadMigrationsFrom.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Spatie's permission tables ship as a .stub file, which the
        // Migrator doesn't discover by path globbing - run it directly,
        // same approach the Phase 7 smoke test used.
        (require __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function createUserWithRole(string $role): TestUser
    {
        (new \Highvertical\Wallet\Database\Seeders\WalletPermissionSeeder())->run();

        $user = TestUser::create(['name' => ucfirst($role)]);
        $user->assignRole($role);

        return $user;
    }
}
