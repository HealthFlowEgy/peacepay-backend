<?php

/**
 * PeacePay Feature Tests
 * 
 * Comprehensive feature tests for API endpoints
 */

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Wallet;
use App\Models\PeaceLink;
use App\Models\Transaction;
use App\Models\Dispute;
use App\Enums\PeaceLinkStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;

// ============================================================================
// AUTH TESTS
// ============================================================================

class AuthTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'أحمد محمد',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['user', 'requires_otp'],
            ]);

        $this->assertDatabaseHas('users', [
            'phone' => '01012345678',
            'name' => 'أحمد محمد',
        ]);
    }

    public function test_registration_validates_egyptian_phone(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'phone' => '1234567890', // Invalid
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_registration_prevents_duplicate_phone(): void
    {
        User::factory()->create(['phone' => '01012345678']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'phone' => '01012345678',
            'password' => bcrypt('password123'),
            'phone_verified_at' => now(),
        ]);
        Wallet::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '01012345678',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['user', 'token'],
            ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'phone' => '01012345678',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '01012345678',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(200);
    }

    public function test_otp_verification_works(): void
    {
        // This would require mocking the OTP service
        $this->markTestIncomplete('Requires OTP mocking');
    }
}

// ============================================================================
// WALLET TESTS
// ============================================================================

class WalletTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create([
            'phone_verified_at' => now(),
            'kyc_level' => 'basic',
        ]);
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 1000,
            'hold_balance' => 0,
            'pending_cashout' => 0,
        ]);
        
        Sanctum::actingAs($this->user);
    }

    public function test_user_can_get_wallet_balance(): void
    {
        $response = $this->getJson('/api/v1/wallet');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'wallet' => [
                        'balance',
                        'available_balance',
                        'hold_balance',
                    ],
                ],
            ])
            ->assertJsonPath('data.wallet.balance', 1000);
    }

    public function test_user_can_get_wallet_details(): void
    {
        $response = $this->getJson('/api/v1/wallet/details');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'wallet',
                    'kyc',
                    'limits',
                ],
            ]);
    }

    public function test_add_money_creates_transaction(): void
    {
        // Mock payment gateway
        $response = $this->postJson('/api/v1/wallet/add-money', [
            'amount' => 100,
            'payment_method' => 'fawry',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['transaction', 'payment_reference'],
            ]);
    }

    public function test_add_money_validates_minimum_amount(): void
    {
        $response = $this->postJson('/api/v1/wallet/add-money', [
            'amount' => 5, // Below minimum
            'payment_method' => 'fawry',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_send_money_transfers_funds(): void
    {
        $recipient = User::factory()->create([
            'phone' => '01198765432',
            'phone_verified_at' => now(),
        ]);
        Wallet::factory()->create([
            'user_id' => $recipient->id,
            'balance' => 0,
        ]);

        $response = $this->postJson('/api/v1/wallet/send', [
            'recipient_phone' => '01198765432',
            'amount' => 100,
            'note' => 'Test transfer',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Verify balances
        $this->assertEquals(900, $this->wallet->fresh()->balance);
        $this->assertEquals(100, $recipient->wallet->fresh()->balance);
    }

    public function test_send_money_fails_with_insufficient_balance(): void
    {
        $recipient = User::factory()->create([
            'phone' => '01198765432',
            'phone_verified_at' => now(),
        ]);
        Wallet::factory()->create(['user_id' => $recipient->id]);

        $response = $this->postJson('/api/v1/wallet/send', [
            'recipient_phone' => '01198765432',
            'amount' => 5000, // More than balance
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_send_money_to_self(): void
    {
        $response = $this->postJson('/api/v1/wallet/send', [
            'recipient_phone' => $this->user->phone,
            'amount' => 100,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_phone']);
    }

    public function test_user_can_search_recipient(): void
    {
        $recipient = User::factory()->create([
            'phone' => '01198765432',
            'name' => 'محمد علي',
            'phone_verified_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/wallet/search-user?phone=01198765432');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.name', 'محمد علي');
    }

    public function test_search_returns_not_found_for_invalid_phone(): void
    {
        $response = $this->getJson('/api/v1/wallet/search-user?phone=01199999999');

        $response->assertStatus(404);
    }
}

// ============================================================================
// CASHOUT TESTS
// ============================================================================

class CashoutTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create([
            'phone_verified_at' => now(),
            'kyc_level' => 'silver',
        ]);
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 5000,
        ]);
        
        Sanctum::actingAs($this->user);
    }

    public function test_user_can_request_bank_cashout(): void
    {
        $response = $this->postJson('/api/v1/cashout', [
            'amount' => 1000,
            'method' => 'bank',
            'bank_name' => 'البنك الأهلي المصري',
            'account_number' => '1234567890123456',
            'account_holder_name' => 'أحمد محمد',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'cashout' => [
                        'id',
                        'amount',
                        'fee',
                        'net_amount',
                        'status',
                    ],
                ],
            ]);

        // Verify fee calculation (1.5%)
        $response->assertJsonPath('data.cashout.fee', 15);
        $response->assertJsonPath('data.cashout.net_amount', 985);
    }

    public function test_user_can_request_wallet_cashout(): void
    {
        $response = $this->postJson('/api/v1/cashout', [
            'amount' => 500,
            'method' => 'wallet',
            'wallet_phone' => '01012345678',
            'wallet_provider' => 'vodafone_cash',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_cashout_validates_minimum_amount(): void
    {
        $response = $this->postJson('/api/v1/cashout', [
            'amount' => 20, // Below 50 EGP minimum
            'method' => 'bank',
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'account_holder_name' => 'Test',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_cashout_fails_with_insufficient_balance(): void
    {
        $response = $this->postJson('/api/v1/cashout', [
            'amount' => 10000, // More than balance
            'method' => 'bank',
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'account_holder_name' => 'Test',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_get_cashout_history(): void
    {
        // Create some cashout records
        $this->user->cashouts()->createMany([
            ['amount' => 500, 'fee' => 7.5, 'net_amount' => 492.5, 'method' => 'bank', 'status' => 'completed'],
            ['amount' => 300, 'fee' => 4.5, 'net_amount' => 295.5, 'method' => 'wallet', 'status' => 'pending'],
        ]);

        $response = $this->getJson('/api/v1/cashout/history');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.cashouts');
    }
}

// ============================================================================
// PEACELINK TESTS
// ============================================================================

class PeaceLinkTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $buyer;
    private User $merchant;
    private Wallet $buyerWallet;
    private Wallet $merchantWallet;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->buyer = User::factory()->create([
            'phone' => '01012345678',
            'phone_verified_at' => now(),
        ]);
        $this->buyerWallet = Wallet::factory()->create([
            'user_id' => $this->buyer->id,
            'balance' => 5000,
        ]);

        $this->merchant = User::factory()->create([
            'phone' => '01198765432',
            'phone_verified_at' => now(),
        ]);
        $this->merchantWallet = Wallet::factory()->create([
            'user_id' => $this->merchant->id,
            'balance' => 0,
        ]);
    }

    public function test_buyer_can_create_peacelink(): void
    {
        Sanctum::actingAs($this->buyer);

        $response = $this->postJson('/api/v1/peacelinks', [
            'merchant_phone' => '01198765432',
            'product_description' => 'iPhone 15 Pro Max - جديد بالكرتونة',
            'item_amount' => 1000,
            'delivery_fee' => 50,
            'buyer_pays_delivery' => true,
            'delivery_address' => 'شارع التحرير، وسط البلد، القاهرة',
            'use_internal_dsp' => false,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'peacelink' => [
                        'id',
                        'reference',
                        'status',
                        'product',
                        'fees',
                        'buyer',
                        'merchant',
                    ],
                    'fees',
                ],
            ]);

        // Verify buyer balance reduced
        $this->assertLessThan(5000, $this->buyerWallet->fresh()->balance);
    }

    public function test_peacelink_calculates_correct_fees(): void
    {
        Sanctum::actingAs($this->buyer);

        $response = $this->postJson('/api/v1/peacelinks/calculate-fees', [
            'item_amount' => 1000,
            'delivery_fee' => 50,
            'buyer_pays_delivery' => true,
        ]);

        $response->assertStatus(200);

        // Platform fee: 0.5% + 2 = 7 EGP
        $response->assertJsonPath('data.platform_fee', 7);
        // Total buyer pays: 1000 + 7 + 50 = 1057
        $response->assertJsonPath('data.total_buyer_pays', 1057);
        // Merchant receives: 1000
        $response->assertJsonPath('data.merchant_receives', 1000);
    }

    public function test_cannot_create_peacelink_with_self(): void
    {
        Sanctum::actingAs($this->buyer);

        $response = $this->postJson('/api/v1/peacelinks', [
            'merchant_phone' => $this->buyer->phone,
            'product_description' => 'Test product description',
            'item_amount' => 100,
            'delivery_address' => 'Test address here',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['merchant_phone']);
    }

    public function test_cannot_create_peacelink_with_insufficient_balance(): void
    {
        $this->buyerWallet->update(['balance' => 50]); // Low balance
        Sanctum::actingAs($this->buyer);

        $response = $this->postJson('/api/v1/peacelinks', [
            'merchant_phone' => '01198765432',
            'product_description' => 'Test product description',
            'item_amount' => 1000,
            'delivery_address' => 'Test address here',
        ]);

        $response->assertStatus(422);
    }

    public function test_merchant_can_accept_peacelink(): void
    {
        // Create PeaceLink
        $peaceLink = PeaceLink::factory()->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
            'status' => PeaceLinkStatus::FUNDED,
        ]);

        Sanctum::actingAs($this->merchant);

        $response = $this->postJson("/api/v1/peacelinks/{$peaceLink->id}/accept");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_buyer_cannot_accept_peacelink(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
            'status' => PeaceLinkStatus::FUNDED,
        ]);

        Sanctum::actingAs($this->buyer);

        $response = $this->postJson("/api/v1/peacelinks/{$peaceLink->id}/accept");

        $response->assertStatus(403);
    }

    public function test_buyer_can_confirm_delivery_with_otp(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
            'status' => PeaceLinkStatus::IN_TRANSIT,
            'delivery_otp' => bcrypt('123456'),
            'delivery_otp_expires_at' => now()->addMinutes(5),
        ]);

        Sanctum::actingAs($this->buyer);

        $response = $this->postJson("/api/v1/peacelinks/{$peaceLink->id}/confirm-delivery", [
            'otp' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_delivery_confirmation_fails_with_wrong_otp(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
            'status' => PeaceLinkStatus::IN_TRANSIT,
            'delivery_otp' => bcrypt('123456'),
            'delivery_otp_expires_at' => now()->addMinutes(5),
            'delivery_otp_attempts' => 0,
        ]);

        Sanctum::actingAs($this->buyer);

        $response = $this->postJson("/api/v1/peacelinks/{$peaceLink->id}/confirm-delivery", [
            'otp' => '999999',
        ]);

        $response->assertStatus(422);
    }

    public function test_peacelink_can_be_cancelled_before_transit(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
            'status' => PeaceLinkStatus::FUNDED,
            'item_amount' => 500,
            'platform_fee' => 4.5,
        ]);

        // Hold funds in buyer wallet
        $this->buyerWallet->update([
            'balance' => 4495.5,
            'hold_balance' => 504.5,
        ]);

        Sanctum::actingAs($this->buyer);

        $response = $this->postJson("/api/v1/peacelinks/{$peaceLink->id}/cancel", [
            'reason' => 'لم أعد أرغب في هذا المنتج',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Verify funds refunded
        $this->assertEquals(5000, $this->buyerWallet->fresh()->balance);
        $this->assertEquals(0, $this->buyerWallet->fresh()->hold_balance);
    }

    public function test_peacelink_cannot_be_cancelled_after_transit(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
            'status' => PeaceLinkStatus::IN_TRANSIT,
        ]);

        Sanctum::actingAs($this->buyer);

        $response = $this->postJson("/api/v1/peacelinks/{$peaceLink->id}/cancel", [
            'reason' => 'Test reason for cancellation',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_get_peacelink_list(): void
    {
        PeaceLink::factory()->count(3)->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
        ]);

        Sanctum::actingAs($this->buyer);

        $response = $this->getJson('/api/v1/peacelinks');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data.data');
    }

    public function test_user_can_filter_peacelinks_by_role(): void
    {
        PeaceLink::factory()->count(2)->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
        ]);
        PeaceLink::factory()->count(3)->create([
            'buyer_id' => $this->merchant->id,
            'merchant_id' => $this->buyer->id,
        ]);

        Sanctum::actingAs($this->buyer);

        $response = $this->getJson('/api/v1/peacelinks?role=buyer');
        $response->assertJsonCount(2, 'data.data');

        $response = $this->getJson('/api/v1/peacelinks?role=merchant');
        $response->assertJsonCount(3, 'data.data');
    }

    public function test_user_can_get_peacelink_timeline(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
            'status' => PeaceLinkStatus::IN_TRANSIT,
            'funded_at' => now()->subHours(2),
            'shipped_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($this->buyer);

        $response = $this->getJson("/api/v1/peacelinks/{$peaceLink->id}/timeline");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['timeline'],
            ]);
    }
}

// ============================================================================
// DISPUTE TESTS
// ============================================================================

class DisputeTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $buyer;
    private User $merchant;
    private PeaceLink $peaceLink;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->buyer = User::factory()->create(['phone_verified_at' => now()]);
        $this->merchant = User::factory()->create(['phone_verified_at' => now()]);
        
        Wallet::factory()->create(['user_id' => $this->buyer->id, 'balance' => 5000]);
        Wallet::factory()->create(['user_id' => $this->merchant->id, 'balance' => 0]);

        $this->peaceLink = PeaceLink::factory()->create([
            'buyer_id' => $this->buyer->id,
            'merchant_id' => $this->merchant->id,
            'status' => PeaceLinkStatus::IN_TRANSIT,
        ]);
    }

    public function test_buyer_can_open_dispute(): void
    {
        Sanctum::actingAs($this->buyer);

        $response = $this->postJson('/api/v1/disputes', [
            'peacelink_id' => $this->peaceLink->id,
            'reason' => 'not_received',
            'description' => 'لم أستلم المنتج رغم مرور الوقت المحدد للتوصيل',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'dispute' => [
                        'id',
                        'status',
                        'reason',
                        'description',
                    ],
                ],
            ]);

        // Verify PeaceLink status changed
        $this->assertEquals(PeaceLinkStatus::DISPUTED, $this->peaceLink->fresh()->status);
    }

    public function test_cannot_open_dispute_on_completed_peacelink(): void
    {
        $this->peaceLink->update(['status' => PeaceLinkStatus::RELEASED]);
        Sanctum::actingAs($this->buyer);

        $response = $this->postJson('/api/v1/disputes', [
            'peacelink_id' => $this->peaceLink->id,
            'reason' => 'damaged',
            'description' => 'Test description for the dispute',
        ]);

        $response->assertStatus(422);
    }

    public function test_merchant_can_respond_to_dispute(): void
    {
        $dispute = Dispute::factory()->create([
            'peacelink_id' => $this->peaceLink->id,
            'initiator_id' => $this->buyer->id,
            'respondent_id' => $this->merchant->id,
            'status' => 'pending_response',
        ]);

        Sanctum::actingAs($this->merchant);

        $response = $this->postJson("/api/v1/disputes/{$dispute->id}/respond", [
            'response' => 'المنتج تم شحنه في الموعد المحدد وهذه صورة إيصال الشحن',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_non_party_cannot_open_dispute(): void
    {
        $otherUser = User::factory()->create(['phone_verified_at' => now()]);
        Sanctum::actingAs($otherUser);

        $response = $this->postJson('/api/v1/disputes', [
            'peacelink_id' => $this->peaceLink->id,
            'reason' => 'other',
            'description' => 'Test description for the dispute',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_get_dispute_details(): void
    {
        $dispute = Dispute::factory()->create([
            'peacelink_id' => $this->peaceLink->id,
            'initiator_id' => $this->buyer->id,
            'respondent_id' => $this->merchant->id,
        ]);

        Sanctum::actingAs($this->buyer);

        $response = $this->getJson("/api/v1/disputes/{$dispute->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => ['dispute'],
            ]);
    }
}

// ============================================================================
// TRANSACTION TESTS
// ============================================================================

class TransactionTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create(['phone_verified_at' => now()]);
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 5000,
        ]);
        
        Sanctum::actingAs($this->user);
    }

    public function test_user_can_get_transaction_history(): void
    {
        Transaction::factory()->count(5)->create([
            'wallet_id' => $this->wallet->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson('/api/v1/transactions');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data.data');
    }

    public function test_user_can_filter_transactions_by_type(): void
    {
        Transaction::factory()->count(3)->create([
            'wallet_id' => $this->wallet->id,
            'user_id' => $this->user->id,
            'direction' => 'credit',
        ]);
        Transaction::factory()->count(2)->create([
            'wallet_id' => $this->wallet->id,
            'user_id' => $this->user->id,
            'direction' => 'debit',
        ]);

        $response = $this->getJson('/api/v1/transactions?type=credit');
        $response->assertJsonCount(3, 'data.data');

        $response = $this->getJson('/api/v1/transactions?type=debit');
        $response->assertJsonCount(2, 'data.data');
    }

    public function test_user_can_filter_transactions_by_date(): void
    {
        Transaction::factory()->create([
            'wallet_id' => $this->wallet->id,
            'user_id' => $this->user->id,
            'created_at' => now()->subDays(5),
        ]);
        Transaction::factory()->create([
            'wallet_id' => $this->wallet->id,
            'user_id' => $this->user->id,
            'created_at' => now()->subDay(),
        ]);

        $startDate = now()->subDays(3)->format('Y-m-d');
        $endDate = now()->format('Y-m-d');

        $response = $this->getJson("/api/v1/transactions?start_date={$startDate}&end_date={$endDate}");

        $response->assertJsonCount(1, 'data.data');
    }

    public function test_user_can_get_single_transaction(): void
    {
        $transaction = Transaction::factory()->create([
            'wallet_id' => $this->wallet->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/transactions/{$transaction->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'transaction' => [
                        'id',
                        'type',
                        'amount',
                        'status',
                    ],
                ],
            ]);
    }
}

// ============================================================================
// KYC TESTS
// ============================================================================

class KycTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create([
            'phone_verified_at' => now(),
            'kyc_level' => 'basic',
        ]);
        Wallet::factory()->create(['user_id' => $this->user->id]);
        
        Sanctum::actingAs($this->user);
    }

    public function test_user_can_get_kyc_status(): void
    {
        $response = $this->getJson('/api/v1/kyc/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.current_level', 'basic')
            ->assertJsonPath('data.can_upgrade', true);
    }

    public function test_user_cannot_downgrade_kyc(): void
    {
        $this->user->update(['kyc_level' => 'silver']);

        $response = $this->postJson('/api/v1/kyc/upgrade', [
            'target_level' => 'basic',
            'national_id' => '29901011234567',
        ]);

        $response->assertStatus(422);
    }

    public function test_kyc_upgrade_validates_national_id(): void
    {
        $response = $this->postJson('/api/v1/kyc/upgrade', [
            'target_level' => 'silver',
            'national_id' => '12345', // Invalid - should be 14 digits
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['national_id']);
    }
}

// ============================================================================
// NOTIFICATION TESTS
// ============================================================================

class NotificationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create(['phone_verified_at' => now()]);
        Wallet::factory()->create(['user_id' => $this->user->id]);
        
        Sanctum::actingAs($this->user);
    }

    public function test_user_can_get_notifications(): void
    {
        $this->user->notifications()->createMany([
            ['type' => 'transfer', 'title' => 'Test 1', 'body' => 'Body 1'],
            ['type' => 'peacelink', 'title' => 'Test 2', 'body' => 'Body 2'],
        ]);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.notifications');
    }

    public function test_user_can_mark_notification_as_read(): void
    {
        $notification = $this->user->notifications()->create([
            'type' => 'transfer',
            'title' => 'Test',
            'body' => 'Body',
        ]);

        $response = $this->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_user_can_get_unread_count(): void
    {
        $this->user->notifications()->createMany([
            ['type' => 'transfer', 'title' => 'Test 1', 'body' => 'Body 1'],
            ['type' => 'peacelink', 'title' => 'Test 2', 'body' => 'Body 2', 'read_at' => now()],
        ]);

        $response = $this->getJson('/api/v1/notifications/unread-count');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 1);
    }
}
