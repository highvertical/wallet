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
        $toCurrency = $this->toWallet->currency;

        return [
            'id' => $this->getKey(),
            'uuid' => $this->uuid,
            'from_wallet_id' => $this->from_wallet_id,
            'to_wallet_id' => $this->to_wallet_id,
            'currency' => $currency,
            'to_currency' => $toCurrency,
            'amount' => Money::fromMinorUnits($this->amount, $currency)->toDecimal(),
            'fee' => Money::fromMinorUnits($this->fee, $currency)->toDecimal(),
            'exchange_rate' => $this->exchange_rate,
            'converted_amount' => $this->converted_amount !== null
                ? Money::fromMinorUnits($this->converted_amount, $toCurrency)->toDecimal()
                : null,
            'note' => $this->note,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
