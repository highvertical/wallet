<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Domain\Data;

use Highvertical\Wallet\Domain\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;

final class TransferData
{
    public function __construct(
        public Model $fromHolder,
        public Model $toHolder,
        public Money $amount,
        public string $walletName = 'default',
        public ?string $reference = null,
        public ?string $note = null,
        public array $meta = [],
        public ?int $initiatedBy = null,
        public ?string $initiatedIp = null
    ) {
    }
}
