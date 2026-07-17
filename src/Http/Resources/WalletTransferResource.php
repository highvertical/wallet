<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Resources;

use Highvertical\Wallet\Domain\ValueObjects\Money;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Highvertical\Wallet\Infrastructure\Models\WalletTransfer
 */
final class WalletTransferResource extends JsonResource
{
    public function toArray($request): array
    {
        $currency = $this->fromWallet->currency;

        return [
            'id' => $this->getKey(),
            'uuid' => $this->uuid,
            'from_wallet_id' => $this->from_wallet_id,
            'to_wallet_id' => $this->to_wallet_id,
            'currency' => $currency,
            'amount' => Money::fromMinorUnits($this->amount, $currency)->toDecimal(),
            'fee' => Money::fromMinorUnits($this->fee, $currency)->toDecimal(),
            'note' => $this->note,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
