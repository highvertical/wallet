<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Database\Seeders\WalletPermissionSeeder;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class WalletPermissionSeederTest extends TestCase
{
    public function test_it_seeds_every_configured_permission(): void
    {
        (new WalletPermissionSeeder())->run();

        $this->assertSame(
            collect(config('wallet.permissions'))->sort()->values()->all(),
            Permission::pluck('name')->sort()->values()->all()
        );
    }

    public function test_it_seeds_both_default_roles_with_their_configured_permissions(): void
    {
        (new WalletPermissionSeeder())->run();

        $this->assertSame(['wallet-admin', 'wallet-user'], Role::pluck('name')->sort()->values()->all());

        $userRole = Role::where('name', 'wallet-user')->first();
        $this->assertSame(
            collect(config('wallet.roles.wallet-user'))->sort()->values()->all(),
            $userRole->permissions()->pluck('name')->sort()->values()->all()
        );

        $adminRole = Role::where('name', 'wallet-admin')->first();
        $this->assertSame(
            collect(config('wallet.permissions'))->sort()->values()->all(),
            $adminRole->permissions()->pluck('name')->sort()->values()->all()
        );
    }

    public function test_running_it_twice_is_idempotent(): void
    {
        (new WalletPermissionSeeder())->run();
        (new WalletPermissionSeeder())->run();

        $this->assertSame(14, Permission::count());
        $this->assertSame(2, Role::count());
    }

    public function test_assigned_roles_grant_permissions_through_gate(): void
    {
        (new WalletPermissionSeeder())->run();

        $user = TestUser::create(['name' => 'Regular']);
        $admin = TestUser::create(['name' => 'Admin']);

        $user->assignRole('wallet-user');
        $admin->assignRole('wallet-admin');

        $this->assertTrue($user->hasPermissionTo('wallet.deposit'));
        $this->assertFalse($user->hasPermissionTo('wallet.freeze'));
        $this->assertTrue($admin->hasPermissionTo('wallet.freeze'));

        $this->assertTrue(Gate::forUser($user)->allows('wallet.deposit'));
        $this->assertTrue(Gate::forUser($user)->denies('wallet.freeze'));
        $this->assertTrue(Gate::forUser($admin)->allows('wallet.freeze'));
    }
}
