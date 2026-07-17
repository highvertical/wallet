<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature\Http;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;

final class SelfServiceWalletApiTest extends TestCase
{
    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson('/wallet/deposit', ['amount' => '10.00'])->assertStatus(401);
    }

    public function test_deposit_requires_the_deposit_permission(): void
    {
        $user = TestUser::create(['name' => 'NoPerms']);

        $this->actingAs($user, 'web')
            ->postJson('/wallet/deposit', ['amount' => '10.00'])
            ->assertStatus(403);
    }

    public function test_deposit_via_http_credits_the_wallet(): void
    {
        $user = $this->createUserWithRole('wallet-user');

        $response = $this->actingAs($user, 'web')
            ->postJson('/wallet/deposit', ['amount' => '150.00']);

        $response->assertStatus(201);
        $this->assertSame(15000, $user->wallet()->fresh()->balance);
    }

    public function test_deposit_validates_amount_format(): void
    {
        $user = $this->createUserWithRole('wallet-user');

        $this->actingAs($user, 'web')
            ->postJson('/wallet/deposit', ['amount' => 'not-a-number'])
            ->assertStatus(422);
    }

    public function test_withdraw_via_http_debits_the_wallet(): void
    {
        $user = $this->createUserWithRole('wallet-user');
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('500.00', 'NGN')));

        $response = $this->actingAs($user, 'web')
            ->postJson('/wallet/withdraw', ['amount' => '100.00']);

        $response->assertStatus(200);
        $this->assertSame(40000, $user->wallet()->fresh()->balance);
    }

    public function test_transfer_via_http_moves_funds(): void
    {
        $sender = $this->createUserWithRole('wallet-user');
        $recipient = TestUser::create(['name' => 'Recipient']);
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('500.00', 'NGN')));
        $this->app->make(\Highvertical\Wallet\Domain\Contracts\WalletRepository::class)->findOrCreate(
            $recipient->getMorphClass(),
            $recipient->getKey(),
            'default',
            'NGN'
        );

        // recipient_type omitted: TransactionController::transfer() defaults it
        // to the authenticated user's own class, which is fine here since both
        // sender and recipient are the same TestUser model.
        $response = $this->actingAs($sender, 'web')->postJson('/wallet/transfer', [
            'recipient_id' => $recipient->getKey(),
            'amount' => '100.00',
        ]);

        $response->assertStatus(200);
        $this->assertSame(40000, $sender->wallet()->fresh()->balance);
        $this->assertSame(10000, $recipient->wallet()->fresh()->balance);
    }

    public function test_index_lists_only_the_authenticated_users_wallets(): void
    {
        $user = $this->createUserWithRole('wallet-user');
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('10.00', 'NGN')));

        $response = $this->actingAs($user, 'web')->getJson('/wallet');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_transaction_show_is_scoped_to_the_owning_holder(): void
    {
        $owner = $this->createUserWithRole('wallet-user');
        $stranger = $this->createUserWithRole('wallet-user');
        $transaction = $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $owner, amount: Money::fromDecimal('10.00', 'NGN')));

        $this->actingAs($owner, 'web')
            ->getJson("/wallet/transactions/{$transaction->getKey()}")
            ->assertStatus(200);

        $this->actingAs($stranger, 'web')
            ->getJson("/wallet/transactions/{$transaction->getKey()}")
            ->assertStatus(403);
    }
}
