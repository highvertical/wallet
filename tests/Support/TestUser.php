<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Support;

use Highvertical\Wallet\Domain\Contracts\Walletable;
use Highvertical\Wallet\Traits\HasWallet;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Minimal holder-model fixture standing in for a host app's User model:
 * Walletable + HasWallet (owns wallets), Authenticatable + Authorizable
 * (auth guard / Gate resolution), HasRoles (Spatie permission checks),
 * Notifiable (SendTransactionNotification listener target).
 */
final class TestUser extends Model implements AuthenticatableContract, AuthorizableContract, Walletable
{
    use Authenticatable;
    use Authorizable;
    use HasRoles;
    use HasWallet;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
