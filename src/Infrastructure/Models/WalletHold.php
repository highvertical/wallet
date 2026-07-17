<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Infrastructure\Models;

use Highvertical\Wallet\Domain\Enums\HoldStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
