<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class WalletTransfer extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'integer',
        'fee' => 'integer',
        'converted_amount' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transfer) {
            $transfer->uuid ??= (string) Str::uuid();
        });
    }

    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }

    public function debitTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'debit_transaction_id');
    }

    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'credit_transaction_id');
    }
}
