<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Data;

use Highvertical\Wallet\Domain\ValueObjects\Money;

final class AdjustmentData
{
    public function __construct(
        public int $walletId,
        public Money $amount,
        public string $reason,
        public int $initiatedBy,
        public ?string $initiatedIp = null,
        public ?string $reference = null,
        public array $meta = []
    ) {
    }
}
