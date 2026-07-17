<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Resources;

use Highvertical\Wallet\Domain\ValueObjects\Money;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Highvertical\Wallet\Infrastructure\Models\WalletHold
 */
final class WalletHoldResource extends JsonResource
{
    public function toArray($request): array
    {
        $currency = $this->wallet->currency;

        return [
            'id' => $this->getKey(),
            'uuid' => $this->uuid,
            'wallet_id' => $this->wallet_id,
            'currency' => $currency,
            'amount' => Money::fromMinorUnits($this->amount, $currency)->toDecimal(),
            'reason' => $this->reason,
            'status' => $this->status,
            'expires_at' => optional($this->expires_at)->toIso8601String(),
            'released_at' => optional($this->released_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
