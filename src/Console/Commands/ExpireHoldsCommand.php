<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Console\Commands;

use Highvertical\Wallet\Application\Actions\ExpireHoldsAction;
use Illuminate\Console\Command;

final class ExpireHoldsCommand extends Command
{
    protected $signature = 'wallet:expire-holds';

    protected $description = 'Flip ACTIVE wallet holds whose TTL has passed to EXPIRED, freeing the funds they were locking.';

    public function handle(ExpireHoldsAction $action): int
    {
        $count = $action->handle();

        $this->info(sprintf('Expired %d hold(s).', $count));

        return self::SUCCESS;
    }
}
