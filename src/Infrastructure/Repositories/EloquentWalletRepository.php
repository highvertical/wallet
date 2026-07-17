<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Infrastructure\Repositories;

use Highvertical\Wallet\Domain\Contracts\WalletRepository;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

final class EloquentWalletRepository implements WalletRepository
{
    public function findOrCreate(string $holderType, int|string $holderId, string $walletName, string $currency): Model
    {
        $attributes = [
            'holder_type' => $holderType,
            'holder_id' => $holderId,
            'name' => $walletName,
            'currency' => strtoupper($currency),
        ];

        $wallet = Wallet::query()->where($attributes)->first();

        if ($wallet !== null) {
            return $wallet;
        }

        try {
            return Wallet::query()->create($attributes);
        } catch (QueryException $exception) {
            $wallet = Wallet::query()->where($attributes)->first();

            if ($wallet === null) {
                throw $exception;
            }

            return $wallet;
        }
    }

    public function find(string $holderType, int|string $holderId, string $walletName, string $currency): ?Model
    {
        return Wallet::query()->where([
            'holder_type' => $holderType,
            'holder_id' => $holderId,
            'name' => $walletName,
            'currency' => strtoupper($currency),
        ])->first();
    }

    public function lockForUpdate(int $walletId): Model
    {
        $wallet = Wallet::query()->lockForUpdate()->find($walletId);

        if ($wallet === null) {
            throw new ModelNotFoundException(sprintf('No wallet found with id "%d".', $walletId));
        }

        return $wallet;
    }
}
