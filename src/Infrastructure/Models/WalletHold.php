<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Infrastructure\Models;

use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WalletHold extends Model
{
    protected $guarded = ['id', 'status', 'capture_transaction_id', 'released_at'];

    protected $casts = [
        'amount' => 'integer',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $hold) {
            $hold->uuid ??= (string) Str::uuid();
        });

        static::saving(function (self $hold) {
            if (! HoldStatus::isValid($hold->status)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid hold status.', $hold->status));
            }
        });
    }

    /**
     * ACTIVE holds whose TTL has passed but haven't been formally flipped to
     * EXPIRED yet (see wallet:expire-holds) still lock funds without this
     * filter - every available-balance calculation across the package uses
     * this scope instead of a bare status check.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', HoldStatus::ACTIVE)
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
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

    public function captureTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'capture_transaction_id');
    }
}
