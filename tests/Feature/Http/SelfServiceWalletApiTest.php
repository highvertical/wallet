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
        $this->postJson('/v1/wallet/deposit', ['amount' => '10.00'])->assertStatus(401);
    }

    public function test_deposit_requires_the_deposit_permission(): void
    {
        $user = TestUser::create(['name' => 'NoPerms']);

        $this->actingAs($user, 'web')
            ->postJson('/v1/wallet/deposit', ['amount' => '10.00'])
            ->assertStatus(403);
    }

    public function test_deposit_via_http_credits_the_wallet(): void
    {
        $user = $this->createUserWithRole('wallet-user');

        $response = $this->actingAs($user, 'web')
            ->postJson('/v1/wallet/deposit', ['amount' => '150.00']);

        $response->assertStatus(201);
        $this->assertSame(15000, $user->wallet()->fresh()->balance);
    }

    public function test_deposit_validates_amount_format(): void
    {
        $user = $this->createUserWithRole('wallet-user');

        $this->actingAs($user, 'web')
            ->postJson('/v1/wallet/deposit', ['amount' => 'not-a-number'])
            ->assertStatus(422);
    }

    public function test_withdraw_via_http_debits_the_wallet(): void
    {
        $user = $this->createUserWithRole('wallet-user');
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('500.00', 'NGN')));

        $response = $this->actingAs($user, 'web')
            ->postJson('/v1/wallet/withdraw', ['amount' => '100.00']);

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
        $response = $this->actingAs($sender, 'web')->postJson('/v1/wallet/transfer', [
            'recipient_id' => $recipient->getKey(),
            'amount' => '100.00',
        ]);

        $response->assertStatus(200);
        $this->assertSame(40000, $sender->wallet()->fresh()->balance);
        $this->assertSame(10000, $recipient->wallet()->fresh()->balance);
    }

    public function test_transfer_rejects_a_non_scalar_recipient_id(): void
    {
        $sender = $this->createUserWithRole('wallet-user');
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $sender, amount: Money::fromDecimal('500.00', 'NGN')));

        $response = $this->actingAs($sender, 'web')->postJson('/v1/wallet/transfer', [
            'recipient_id' => ['1', '2'],
            'amount' => '100.00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('recipient_id');
    }

    public function test_index_lists_only_the_authenticated_users_wallets(): void
    {
        $user = $this->createUserWithRole('wallet-user');
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('10.00', 'NGN')));

        $response = $this->actingAs($user, 'web')->getJson('/v1/wallet');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_transaction_show_is_scoped_to_the_owning_holder(): void
    {
        $owner = $this->createUserWithRole('wallet-user');
        $stranger = $this->createUserWithRole('wallet-user');
        $transaction = $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $owner, amount: Money::fromDecimal('10.00', 'NGN')))['transaction'];

        $this->actingAs($owner, 'web')
            ->getJson("/v1/wallet/transactions/{$transaction->getKey()}")
            ->assertStatus(200);

        $this->actingAs($stranger, 'web')
            ->getJson("/v1/wallet/transactions/{$transaction->getKey()}")
            ->assertStatus(403);
    }

    public function test_deposit_rejects_an_oversized_amount_string(): void
    {
        $user = $this->createUserWithRole('wallet-user');

        $this->actingAs($user, 'web')
            ->postJson('/v1/wallet/deposit', ['amount' => str_repeat('9', 40)])
            ->assertStatus(422);
    }

    public function test_deposit_rejects_a_meta_payload_over_the_byte_cap(): void
    {
        $user = $this->createUserWithRole('wallet-user');

        $this->actingAs($user, 'web')
            ->postJson('/v1/wallet/deposit', [
                'amount' => '10.00',
                'meta' => ['note' => str_repeat('a', 9000)],
            ])
            ->assertStatus(422);
    }

    public function test_deposit_rejects_a_meta_payload_with_too_many_keys(): void
    {
        $user = $this->createUserWithRole('wallet-user');

        $this->actingAs($user, 'web')
            ->postJson('/v1/wallet/deposit', [
                'amount' => '10.00',
                'meta' => array_fill_keys(range(1, 51), 'x'),
            ])
            ->assertStatus(422);
    }

    public function test_transactions_index_clamps_an_excessive_per_page_request(): void
    {
        $user = $this->createUserWithRole('wallet-user');
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $user, amount: Money::fromDecimal('10.00', 'NGN')));

        $response = $this->actingAs($user, 'web')->getJson('/v1/wallet/transactions?per_page=999999');

        $response->assertStatus(200);
        $this->assertSame(100, $response->json('meta.per_page'));
    }

    public function test_export_streams_a_csv_of_only_the_owning_holders_transactions(): void
    {
        $owner = $this->createUserWithRole('wallet-user');
        $stranger = $this->createUserWithRole('wallet-user');
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $owner, amount: Money::fromDecimal('150.00', 'NGN')));
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $stranger, amount: Money::fromDecimal('999.00', 'NGN')));

        $response = $this->actingAs($owner, 'web')->get('/v1/wallet/transactions-export');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertSame(
            ['uuid', 'type', 'category', 'amount', 'currency', 'balance_after', 'reference', 'status', 'created_at'],
            $rows[0]
        );
        $this->assertCount(2, $rows);
        $this->assertSame('150.00', $rows[1][3]);
    }
}
