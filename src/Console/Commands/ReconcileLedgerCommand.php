<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Console\Commands;

use Highvertical\Wallet\Application\Actions\ReconcileWalletLedgerAction;
use Illuminate\Console\Command;

final class ReconcileLedgerCommand extends Command
{
    protected $signature = 'wallet:reconcile {--wallet= : Reconcile a single wallet by ID instead of all wallets} {--fix : Correct any mismatched balances found}';

    protected $description = "Recompute each wallet's balance from its transaction ledger and report (or fix) any drift.";

    public function handle(ReconcileWalletLedgerAction $action): int
    {
        $walletId = $this->option('wallet') !== null ? (int) $this->option('wallet') : null;
        $fix = (bool) $this->option('fix');

        $mismatches = $action->handle($walletId, $fix);

        if ($mismatches === []) {
            $this->info('All wallets are balanced.');

            return self::SUCCESS;
        }

        $this->table(
            ['Wallet ID', 'Expected Balance', 'Actual Balance', 'Difference'],
            array_map(
                fn (array $mismatch) => [
                    $mismatch['wallet_id'],
                    $mismatch['expected_balance'],
                    $mismatch['actual_balance'],
                    $mismatch['difference'],
                ],
                $mismatches
            )
        );

        if ($fix) {
            $this->info(sprintf('Fixed %d wallet(s).', count($mismatches)));

            return self::SUCCESS;
        }

        $this->error(sprintf('%d wallet(s) out of balance. Re-run with --fix to correct.', count($mismatches)));

        return self::FAILURE;
    }
}
