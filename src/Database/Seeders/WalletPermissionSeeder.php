<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds every permission in config('wallet.permissions') and the default
 * wallet-user/wallet-admin roles in config('wallet.roles'), each synced to
 * its configured permission subset. Host apps call this from their own
 * DatabaseSeeder::call(WalletPermissionSeeder::class); it never runs on its
 * own. Permissions/roles use Spatie's default guard (config('auth.defaults.guard'))
 * since no guard_name is passed.
 */
final class WalletPermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ((array) config('wallet.permissions', []) as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        foreach ((array) config('wallet.roles', []) as $role => $permissions) {
            Role::firstOrCreate(['name' => $role])->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
