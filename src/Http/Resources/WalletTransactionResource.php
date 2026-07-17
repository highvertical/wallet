<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Resources;

use Highvertical\Wallet\Domain\ValueObjects\Money;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Highvertical\Wallet\Infrastructure\Models\WalletTransaction
 */
final class WalletTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        $currency = $this->wallet->currency;

        return [
            'id' => $this->getKey(),
            'uuid' => $this->uuid,
            'wallet_id' => $this->wallet_id,
            'type' => $this->type,
            'category' => $this->category,
            'currency' => $currency,
            'amount' => Money::fromMinorUnits($this->amount, $currency)->toDecimal(),
            'balance_before' => Money::fromMinorUnits($this->balance_before, $currency)->toDecimal(),
            'balance_after' => Money::fromMinorUnits($this->balance_after, $currency)->toDecimal(),
            'reference' => $this->reference,
            'description' => $this->description,
            'status' => $this->status,
            'meta' => $this->meta,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
