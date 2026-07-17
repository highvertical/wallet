<?php

declare(strict_types=1);

namespace Highvertical\Wallet\Tests\Feature\Http;

use Highvertical\Wallet\Application\WalletManager;
use Highvertical\Wallet\Domain\Data\DepositData;
use Highvertical\Wallet\Domain\ValueObjects\Money;
use Highvertical\Wallet\Infrastructure\Models\Wallet;
use Highvertical\Wallet\Tests\Support\TestUser;
use Highvertical\Wallet\Tests\TestCase;

final class AdminWalletApiTest extends TestCase
{
    private function depositedWallet(TestUser $holder, string $amount = '1000.00'): Wallet
    {
        $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $holder, amount: Money::fromDecimal($amount, 'NGN')));

        return $holder->wallet()->fresh();
    }

    public function test_wallet_user_cannot_freeze_a_wallet(): void
    {
        $owner = TestUser::create(['name' => 'Owner']);
        $wallet = $this->depositedWallet($owner);
        $user = $this->createUserWithRole('wallet-user');

        $this->actingAs($user, 'web')
            ->postJson("/v1/wallet/admin/wallets/{$wallet->getKey()}/freeze", ['reason' => 'fraud'])
            ->assertStatus(403);
    }

    public function test_admin_can_freeze_and_unfreeze_a_wallet(): void
    {
        $owner = TestUser::create(['name' => 'Owner']);
        $wallet = $this->depositedWallet($owner);
        $admin = $this->createUserWithRole('wallet-admin');

        $this->actingAs($admin, 'web')
            ->postJson("/v1/wallet/admin/wallets/{$wallet->getKey()}/freeze", ['reason' => 'fraud'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'frozen');

        $this->actingAs($admin, 'web')
            ->postJson("/v1/wallet/admin/wallets/{$wallet->getKey()}/unfreeze")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_admin_can_adjust_balance(): void
    {
        $owner = TestUser::create(['name' => 'Owner']);
        $wallet = $this->depositedWallet($owner, '100.00');
        $admin = $this->createUserWithRole('wallet-admin');

        $this->actingAs($admin, 'web')
            ->postJson("/v1/wallet/admin/wallets/{$wallet->getKey()}/adjust", [
                'amount' => '50.00',
                'reason' => 'goodwill credit',
            ])
            ->assertStatus(201);

        $this->assertSame(15000, $wallet->fresh()->balance);
    }

    public function test_admin_can_place_release_and_capture_a_hold(): void
    {
        $owner = TestUser::create(['name' => 'Owner']);
        $wallet = $this->depositedWallet($owner, '1000.00');
        $admin = $this->createUserWithRole('wallet-admin');

        $holdResponse = $this->actingAs($admin, 'web')
            ->postJson("/v1/wallet/admin/wallets/{$wallet->getKey()}/holds", [
                'amount' => '200.00',
                'reason' => 'reserved for order #1',
            ])
            ->assertStatus(201);

        $holdId = $holdResponse->json('data.id');

        $this->actingAs($admin, 'web')
            ->postJson("/v1/wallet/admin/holds/{$holdId}/capture", ['amount' => '150.00'])
            ->assertStatus(200);

        $this->assertSame(85000, $wallet->fresh()->balance);
    }

    public function test_admin_can_release_a_hold(): void
    {
        $owner = TestUser::create(['name' => 'Owner']);
        $wallet = $this->depositedWallet($owner, '1000.00');
        $admin = $this->createUserWithRole('wallet-admin');

        $holdResponse = $this->actingAs($admin, 'web')
            ->postJson("/v1/wallet/admin/wallets/{$wallet->getKey()}/holds", [
                'amount' => '200.00',
                'reason' => 'reserved for order #2',
            ]);

        $holdId = $holdResponse->json('data.id');

        $this->actingAs($admin, 'web')
            ->postJson("/v1/wallet/admin/holds/{$holdId}/release")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'released');
    }

    public function test_admin_can_reverse_a_transaction(): void
    {
        $owner = TestUser::create(['name' => 'Owner']);
        $transaction = $this->app->make(WalletManager::class)->deposit(new DepositData(holder: $owner, amount: Money::fromDecimal('300.00', 'NGN')))['transaction'];
        $admin = $this->createUserWithRole('wallet-admin');

        $this->actingAs($admin, 'web')
            ->postJson("/v1/wallet/admin/transactions/{$transaction->getKey()}/reverse", ['reason' => 'chargeback'])
            ->assertStatus(201);

        $this->assertSame(0, $owner->wallet()->fresh()->balance);
    }

    public function test_wallet_owner_can_view_their_own_wallet_via_admin_show_route(): void
    {
        $owner = $this->createUserWithRole('wallet-user');
        $wallet = $this->depositedWallet($owner);

        $this->actingAs($owner, 'web')
            ->getJson("/v1/wallet/admin/wallets/{$wallet->getKey()}")
            ->assertStatus(200);
    }

    public function test_stranger_without_view_all_cannot_view_someone_elses_wallet(): void
    {
        $owner = TestUser::create(['name' => 'Owner']);
        $wallet = $this->depositedWallet($owner);
        $stranger = $this->createUserWithRole('wallet-user');

        $this->actingAs($stranger, 'web')
            ->getJson("/v1/wallet/admin/wallets/{$wallet->getKey()}")
            ->assertStatus(403);
    }
}
