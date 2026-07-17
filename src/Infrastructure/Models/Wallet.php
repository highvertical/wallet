<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Infrastructure\Models;

use Highvertical\Wallet\Domain\Enums\WalletStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

final class Wallet extends Model
{
    use SoftDeletes;

    /**
     * balance, status, and the frozen_* columns are only ever written by
     * Action-layer code via direct attribute assignment - never from a
     * mass-assignment call built on request input.
     */
    protected $guarded = ['id', 'balance', 'status', 'frozen_reason', 'frozen_at', 'frozen_by'];

    /**
     * Mirrors the migration's column defaults so a freshly-instantiated,
     * not-yet-saved model already carries them - the saving-event validation
     * below runs on the in-memory object before INSERT applies DB defaults.
     */
    protected $attributes = [
        'balance' => 0,
        'min_balance' => 0,
        'status' => WalletStatus::ACTIVE,
        'low_balance_alert' => false,
    ];

    protected $casts = [
        'balance' => 'integer',
        'min_balance' => 'integer',
        'max_balance' => 'integer',
        'low_balance_alert' => 'boolean',
        'meta' => 'array',
        'frozen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $wallet) {
            if (! WalletStatus::isValid($wallet->status)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid wallet status.', $wallet->status));
            }
        });
    }

    public function holder(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(WalletHold::class);
    }
}
