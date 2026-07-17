<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Infrastructure\FeeCalculators;

use Highvertical\Wallet\Domain\Contracts\FeeCalculator;
use Highvertical\Wallet\Domain\ValueObjects\Money;

/**
 * Reads config('wallet.fees.{operation}'). Percentage fees are expressed as
 * basis points (1/100th of a percent, e.g. 150 = 1.5%) so the calculation
 * stays pure integer arithmetic - no floats touch money, ever.
 */
final class ConfigDrivenFeeCalculator implements FeeCalculator
{
    private const PERCENTAGE_DENOMINATOR = 10000;

    public function calculate(Money $amount, string $operation): Money
    {
        $config = (array) config("wallet.fees.{$operation}", []);
        $type = $config['type'] ?? 'flat';
        $value = (int) ($config['value'] ?? 0);

        $feeMinorUnits = $type === 'percentage'
            ? intdiv($amount->minorUnits() * $value, self::PERCENTAGE_DENOMINATOR)
            : $value;

        if (isset($config['min'])) {
            $feeMinorUnits = max($feeMinorUnits, (int) $config['min']);
        }

        if (isset($config['cap'])) {
            $feeMinorUnits = min($feeMinorUnits, (int) $config['cap']);
        }

        return Money::fromMinorUnits(max($feeMinorUnits, 0), $amount->currency());
    }
}
