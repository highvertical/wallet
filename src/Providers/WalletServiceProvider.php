<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Providers;

use Highvertical\Wallet\Domain\Contracts\FeeCalculator;
use Highvertical\Wallet\Domain\Contracts\LimitPolicy;
use Highvertical\Wallet\Domain\Contracts\WalletRepository;
use Highvertical\Wallet\Domain\Exceptions\WalletException;
use Highvertical\Wallet\Events\LowBalanceDetected;
use Highvertical\Wallet\Events\TransactionReversed;
use Highvertical\Wallet\Events\WalletCredited;
use Highvertical\Wallet\Events\WalletDebited;
use Highvertical\Wallet\Events\WalletFrozen;
use Highvertical\Wallet\Events\WalletHoldCaptured;
use Highvertical\Wallet\Events\WalletHoldPlaced;
use Highvertical\Wallet\Events\WalletHoldReleased;
use Highvertical\Wallet\Events\WalletTransferred;
use Highvertical\Wallet\Events\WalletUnfrozen;
use Highvertical\Wallet\Infrastructure\FeeCalculators\ConfigDrivenFeeCalculator;
use Highvertical\Wallet\Infrastructure\LimitPolicies\RollingWindowLimitPolicy;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Infrastructure\Models\WalletTransaction;
use Highvertical\Wallet\Infrastructure\Repositories\EloquentWalletRepository;
use Highvertical\Wallet\Listeners\RecordAuditLog;
use Highvertical\Wallet\Listeners\SendTransactionNotification;
use Highvertical\Wallet\Policies\WalletPolicy;
use Highvertical\Wallet\Policies\WalletTransactionPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class WalletServiceProvider extends ServiceProvider
{
    /**
     * Every wallet domain event, each routed to both listeners below.
     * RecordAuditLog always runs; SendTransactionNotification is a no-op
     * unless wallet.notifications.enabled is turned on.
     */
    private const EVENTS = [
        WalletCredited::class,
        WalletDebited::class,
        WalletTransferred::class,
        WalletFrozen::class,
        WalletUnfrozen::class,
        LowBalanceDetected::class,
        WalletHoldPlaced::class,
        WalletHoldReleased::class,
        WalletHoldCaptured::class,
        TransactionReversed::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/wallet.php', 'wallet');

        $this->app->bind(WalletRepository::class, EloquentWalletRepository::class);
        $this->app->bind(FeeCalculator::class, ConfigDrivenFeeCalculator::class);
        $this->app->bind(LimitPolicy::class, RollingWindowLimitPolicy::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/wallet.php' => $this->app->configPath('wallet.php'),
        ], 'wallet-config');

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        $this->registerRateLimiters();
        $this->registerEventListeners();
        $this->registerPolicies();
        $this->registerExceptionRendering();
    }

    private function registerEventListeners(): void
    {
        foreach (self::EVENTS as $event) {
            Event::listen($event, RecordAuditLog::class);
            Event::listen($event, SendTransactionNotification::class);
        }
    }

    private function registerRateLimiters(): void
    {
        foreach ((array) config('wallet.rate_limits', []) as $name => $settings) {
            RateLimiter::for($name, function (Request $request) use ($settings) {
                $identifier = $request->user() ? $request->user()->getAuthIdentifier() : $request->ip();

                return Limit::perMinutes($settings['decay_minutes'], $settings['limit'])->by((string) $identifier);
            });
        }
    }

    private function registerPolicies(): void
    {
        Gate::policy(Wallet::class, WalletPolicy::class);
        Gate::policy(WalletTransaction::class, WalletTransactionPolicy::class);
    }

    /**
     * WalletException carries its own HTTP status code (see the abstract
     * class's docblock: Domain must not depend on Illuminate\Http\*, so
     * rendering is wired here instead). Guarded by an instanceof check
     * since ExceptionHandler the contract doesn't declare renderable() -
     * only Laravel's concrete Handler does, which every real app uses.
     */
    private function registerExceptionRendering(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (! $handler instanceof Handler) {
            return;
        }

        $handler->renderable(function (WalletException $exception, Request $request) {
            if ($request->expectsJson() || $request->is('wallet/*')) {
                return response()->json(['message' => $exception->getMessage()], $exception->statusCode());
            }
        });
    }
}
