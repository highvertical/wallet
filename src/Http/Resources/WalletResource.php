<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Http\Resources;

use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Infrastructure\Models\WalletHold;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Highvertical\Wallet\Infrastructure\Models\Wallet
 */
final class WalletResource extends JsonResource
{
    public function toArray($request): array
    {
        $heldMinorUnits = (int) WalletHold::query()
            ->where('wallet_id', $this->getKey())
            ->where('status', HoldStatus::ACTIVE)
            ->sum('amount');

        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'currency' => $this->currency,
            'balance' => Money::fromMinorUnits($this->balance, $this->currency)->toDecimal(),
            'held' => Money::fromMinorUnits($heldMinorUnits, $this->currency)->toDecimal(),
            'available_balance' => Money::fromMinorUnits($this->balance - $heldMinorUnits, $this->currency)->toDecimal(),
            'min_balance' => $this->min_balance !== null
                ? Money::fromMinorUnits($this->min_balance, $this->currency)->toDecimal()
                : null,
            'max_balance' => $this->max_balance !== null
                ? Money::fromMinorUnits($this->max_balance, $this->currency)->toDecimal()
                : null,
            'status' => $this->status,
            'low_balance_alert' => $this->low_balance_alert,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
