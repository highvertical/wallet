<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Infrastructure\LimitPolicies;

use Highvertical\Wallet\Domain\Contracts\LimitPolicy;
use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionStatus;
use Highvertical\Wallet\Domain\Enums\WalletOperation;
use Highvertical\Wallet\Domain\Exceptions\LimitExceededException;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Illuminate\Support\Facades\Date;

/**
 * Business caps (config('wallet.limits.{operation}.daily'|'monthly')), not to
 * be confused with the infrastructure rate limiter registered in
 * WalletServiceProvider. Must run inside the same wallet-row lock as the
 * mutation it's guarding, otherwise two concurrent requests could both pass
 * the check before either commits.
 */
final class RollingWindowLimitPolicy implements LimitPolicy
{
    public function assertWithinLimit(int $walletId, Money $amount, string $operation): void
    {
        $limits = (array) config("wallet.limits.{$operation}", []);

        foreach (['daily', 'monthly'] as $window) {
            $cap = $limits[$window] ?? null;

            if ($cap === null) {
                continue;
            }

            $windowStart = $window === 'daily' ? Date::now()->startOfDay() : Date::now()->startOfMonth();

            $sum = (int) WalletTransaction::query()
                ->where('wallet_id', $walletId)
                ->whereIn('category', $this->categoriesFor($operation))
                ->where('status', TransactionStatus::COMPLETED)
                ->where('created_at', '>=', $windowStart)
                ->sum('amount');

            if (($sum + $amount->minorUnits()) > $cap) {
                throw new LimitExceededException(sprintf(
                    'This %s would exceed the configured %s limit.',
                    $operation,
                    $window
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function categoriesFor(string $operation): array
    {
        return match ($operation) {
            WalletOperation::DEPOSIT => [TransactionCategory::DEPOSIT],
            WalletOperation::WITHDRAWAL => [TransactionCategory::WITHDRAWAL],
            WalletOperation::TRANSFER => [TransactionCategory::TRANSFER_OUT],
            default => [],
        };
    }
}
