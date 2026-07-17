<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Infrastructure\Models;

use Highvertical\Wallet\Domain\Enums\TransactionCategory;
use Highvertical\Wallet\Domain\Enums\TransactionStatus;
use Highvertical\Wallet\Domain\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WalletTransaction extends Model
{
    /**
     * balance_before/balance_after are computed inside the lock by Action
     * code, never mass-assigned; initiated_by/initiated_ip are captured
     * server-side from the authenticated request, never accepted as input.
     */
    protected $guarded = ['id', 'balance_before', 'balance_after', 'initiated_by', 'initiated_ip'];

    protected $casts = [
        'amount' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transaction) {
            $transaction->uuid ??= (string) Str::uuid();
        });

        static::saving(function (self $transaction) {
            if (! TransactionType::isValid($transaction->type)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid transaction type.', $transaction->type));
            }

            if (! TransactionCategory::isValid($transaction->category)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid transaction category.', $transaction->category));
            }

            if (! TransactionStatus::isValid($transaction->status)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid transaction status.', $transaction->status));
            }
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
