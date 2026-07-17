<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature;

use Highvertical\Wallet\Domain\Contracts\WalletRepository;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;

final class EloquentWalletRepositoryTest extends TestCase
{
    public function test_find_all_for_holder_returns_an_empty_array_when_the_holder_has_no_wallet(): void
    {
        $holder = TestUser::create(['name' => 'Alice']);
        $repository = $this->app->make(WalletRepository::class);

        $wallets = $repository->findAllForHolder($holder->getMorphClass(), $holder->getKey(), 'default');

        $this->assertSame([], $wallets);
    }

    public function test_find_all_for_holder_returns_the_single_wallet_when_only_one_currency_exists(): void
    {
        $holder = TestUser::create(['name' => 'Alice']);
        $repository = $this->app->make(WalletRepository::class);
        $repository->findOrCreate($holder->getMorphClass(), $holder->getKey(), 'default', 'NGN');

        $wallets = $repository->findAllForHolder($holder->getMorphClass(), $holder->getKey(), 'default');

        $this->assertCount(1, $wallets);
        $this->assertSame('NGN', $wallets[0]->currency);
    }

    public function test_find_all_for_holder_returns_every_currency_variant(): void
    {
        $holder = TestUser::create(['name' => 'Alice']);
        $repository = $this->app->make(WalletRepository::class);
        $repository->findOrCreate($holder->getMorphClass(), $holder->getKey(), 'default', 'NGN');
        $repository->findOrCreate($holder->getMorphClass(), $holder->getKey(), 'default', 'USD');

        $wallets = $repository->findAllForHolder($holder->getMorphClass(), $holder->getKey(), 'default');

        $this->assertCount(2, $wallets);
        $this->assertSame(['NGN', 'USD'], collect($wallets)->pluck('currency')->sort()->values()->all());
    }

    public function test_find_all_for_holder_does_not_return_wallets_under_a_different_name(): void
    {
        $holder = TestUser::create(['name' => 'Alice']);
        $repository = $this->app->make(WalletRepository::class);
        $repository->findOrCreate($holder->getMorphClass(), $holder->getKey(), 'savings', 'NGN');

        $wallets = $repository->findAllForHolder($holder->getMorphClass(), $holder->getKey(), 'default');

        $this->assertSame([], $wallets);
    }

    public function test_find_all_for_holder_does_not_return_another_holders_wallets(): void
    {
        $holder = TestUser::create(['name' => 'Alice']);
        $otherHolder = TestUser::create(['name' => 'Bob']);
        $repository = $this->app->make(WalletRepository::class);
        $repository->findOrCreate($otherHolder->getMorphClass(), $otherHolder->getKey(), 'default', 'NGN');

        $wallets = $repository->findAllForHolder($holder->getMorphClass(), $holder->getKey(), 'default');

        $this->assertSame([], $wallets);
    }
}
