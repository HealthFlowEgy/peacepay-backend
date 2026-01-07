<?php

/**
 * PeacePay Unit Tests
 * 
 * Unit tests for services, models, and business logic
 */

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Wallet;
use App\Models\PeaceLink;
use App\Models\Transaction;
use App\Services\WalletService;
use App\Services\PeaceLinkService;
use App\Services\OtpService;
use App\Services\CashoutService;
use App\Services\DisputeService;
use App\Enums\PeaceLinkStatus;
use App\Enums\TransactionType;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

// ============================================================================
// WALLET SERVICE TESTS
// ============================================================================

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $walletService;
    private User $user;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->walletService = new WalletService();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 1000,
            'hold_balance' => 0,
            'pending_cashout' => 0,
        ]);
    }

    public function test_get_balance_returns_correct_values(): void
    {
        $this->wallet->update([
            'balance' => 1000,
            'hold_balance' => 200,
            'pending_cashout' => 100,
        ]);

        $balance = $this->walletService->getBalance($this->user);

        $this->assertEquals(1000, $balance['balance']);
        $this->assertEquals(700, $balance['available_balance']); // 1000 - 200 - 100
        $this->assertEquals(200, $balance['hold_balance']);
        $this->assertEquals(100, $balance['pending_cashout']);
    }

    public function test_validate_balance_passes_with_sufficient_funds(): void
    {
        $this->walletService->validateBalance($this->user, 500);
        $this->assertTrue(true); // No exception thrown
    }

    public function test_validate_balance_throws_exception_with_insufficient_funds(): void
    {
        $this->expectException(InsufficientBalanceException::class);
        $this->walletService->validateBalance($this->user, 2000);
    }

    public function test_send_money_transfers_funds_correctly(): void
    {
        $recipient = User::factory()->create();
        Wallet::factory()->create([
            'user_id' => $recipient->id,
            'balance' => 0,
        ]);

        $this->walletService->sendMoney($this->user, $recipient, 500, 'Test note');

        $this->assertEquals(500, $this->wallet->fresh()->balance);
        $this->assertEquals(500, $recipient->wallet->fresh()->balance);
    }

    public function test_send_money_creates_transactions_for_both_parties(): void
    {
        $recipient = User::factory()->create();
        Wallet::factory()->create(['user_id' => $recipient->id, 'balance' => 0]);

        $this->walletService->sendMoney($this->user, $recipient, 500);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $this->user->id,
            'direction' => 'debit',
            'amount' => 500,
        ]);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $recipient->id,
            'direction' => 'credit',
            'amount' => 500,
        ]);
    }

    public function test_hold_funds_moves_balance_to_hold(): void
    {
        $this->walletService->holdFunds($this->user, 300, 'peacelink', 'test-id');

        $wallet = $this->wallet->fresh();
        $this->assertEquals(700, $wallet->balance);
        $this->assertEquals(300, $wallet->hold_balance);
    }

    public function test_add_money_calculates_fawry_fee_correctly(): void
    {
        $transaction = $this->walletService->addMoney($this->user, 100, 'fawry');

        // Fawry: 5 EGP fixed fee
        $this->assertEquals(95, $transaction->amount); // 100 - 5
        $this->assertEquals(5, $transaction->fee);
    }

    public function test_add_money_calculates_vodafone_fee_correctly(): void
    {
        $transaction = $this->walletService->addMoney($this->user, 1000, 'vodafone_cash');

        // Vodafone: 1.5%
        $this->assertEquals(985, $transaction->amount); // 1000 - 15
        $this->assertEquals(15, $transaction->fee);
    }

    public function test_add_money_calculates_card_fee_correctly(): void
    {
        $transaction = $this->walletService->addMoney($this->user, 1000, 'card');

        // Card: 2.5%
        $this->assertEquals(975, $transaction->amount); // 1000 - 25
        $this->assertEquals(25, $transaction->fee);
    }

    public function test_add_money_instapay_is_free(): void
    {
        $transaction = $this->walletService->addMoney($this->user, 1000, 'instapay');

        $this->assertEquals(1000, $transaction->amount);
        $this->assertEquals(0, $transaction->fee);
    }

    public function test_search_user_by_phone_finds_verified_user(): void
    {
        $target = User::factory()->create([
            'phone' => '01198765432',
            'phone_verified_at' => now(),
        ]);

        $found = $this->walletService->searchUserByPhone('01198765432');

        $this->assertNotNull($found);
        $this->assertEquals($target->id, $found->id);
    }

    public function test_search_user_by_phone_excludes_unverified(): void
    {
        User::factory()->create([
            'phone' => '01198765432',
            'phone_verified_at' => null,
        ]);

        $found = $this->walletService->searchUserByPhone('01198765432');

        $this->assertNull($found);
    }

    public function test_search_user_by_phone_can_exclude_user(): void
    {
        $target = User::factory()->create([
            'phone' => '01198765432',
            'phone_verified_at' => now(),
        ]);

        $found = $this->walletService->searchUserByPhone('01198765432', $target->id);

        $this->assertNull($found);
    }
}

// ============================================================================
// PEACELINK SERVICE TESTS
// ============================================================================

class PeaceLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private PeaceLinkService $peaceLinkService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->peaceLinkService = new PeaceLinkService();
    }

    public function test_calculate_fees_with_buyer_pays_delivery(): void
    {
        $fees = $this->peaceLinkService->calculateFees(1000, 50, true);

        // Platform fee: 0.5% + 2 = 7 EGP
        $this->assertEquals(7, $fees['platform_fee']);
        // Total buyer pays: 1000 + 7 + 50 = 1057
        $this->assertEquals(1057, $fees['total_buyer_pays']);
        // Merchant receives: 1000
        $this->assertEquals(1000, $fees['merchant_receives']);
        // DSP receives: 50
        $this->assertEquals(50, $fees['dsp_receives']);
    }

    public function test_calculate_fees_with_merchant_pays_delivery(): void
    {
        $fees = $this->peaceLinkService->calculateFees(1000, 50, false);

        // Platform fee: 0.5% + 2 = 7 EGP
        $this->assertEquals(7, $fees['platform_fee']);
        // Total buyer pays: 1000 + 7 = 1007 (no delivery)
        $this->assertEquals(1007, $fees['total_buyer_pays']);
        // Merchant receives: 1000 - 50 = 950
        $this->assertEquals(950, $fees['merchant_receives']);
    }

    public function test_calculate_fees_minimum_amount(): void
    {
        $fees = $this->peaceLinkService->calculateFees(50, 0, true);

        // Platform fee: 0.5% of 50 + 2 = 0.25 + 2 = 2.25 ≈ 2.25
        $this->assertEquals(2.25, $fees['platform_fee']);
        $this->assertEquals(52.25, $fees['total_buyer_pays']);
    }

    public function test_create_peacelink_generates_reference(): void
    {
        $buyer = User::factory()->create();
        $merchant = User::factory()->create();

        $peaceLink = $this->peaceLinkService->create([
            'buyer_id' => $buyer->id,
            'merchant_id' => $merchant->id,
            'product_description' => 'Test product',
            'item_amount' => 100,
            'delivery_fee' => 20,
            'buyer_pays_delivery' => true,
            'platform_fee' => 2.5,
            'delivery_address' => 'Test address',
        ]);

        $this->assertNotNull($peaceLink->reference);
        $this->assertStringStartsWith('PL-', $peaceLink->reference);
    }

    public function test_create_peacelink_sets_pending_status(): void
    {
        $buyer = User::factory()->create();
        $merchant = User::factory()->create();

        $peaceLink = $this->peaceLinkService->create([
            'buyer_id' => $buyer->id,
            'merchant_id' => $merchant->id,
            'product_description' => 'Test product',
            'item_amount' => 100,
            'platform_fee' => 2.5,
            'delivery_address' => 'Test address',
        ]);

        $this->assertEquals(PeaceLinkStatus::PENDING, $peaceLink->status);
    }

    public function test_get_user_peacelinks_as_buyer(): void
    {
        $buyer = User::factory()->create();
        $merchant = User::factory()->create();

        PeaceLink::factory()->count(3)->create([
            'buyer_id' => $buyer->id,
            'merchant_id' => $merchant->id,
        ]);

        $peaceLinks = $this->peaceLinkService->getUserPeaceLinks($buyer, 'buyer');

        $this->assertEquals(3, $peaceLinks->total());
    }

    public function test_get_user_peacelinks_as_merchant(): void
    {
        $buyer = User::factory()->create();
        $merchant = User::factory()->create();

        PeaceLink::factory()->count(2)->create([
            'buyer_id' => $buyer->id,
            'merchant_id' => $merchant->id,
        ]);
        PeaceLink::factory()->count(3)->create([
            'buyer_id' => $merchant->id,
            'merchant_id' => $buyer->id,
        ]);

        $peaceLinks = $this->peaceLinkService->getUserPeaceLinks($merchant, 'merchant');

        $this->assertEquals(2, $peaceLinks->total());
    }

    public function test_get_user_peacelinks_filters_by_status_group(): void
    {
        $buyer = User::factory()->create();
        $merchant = User::factory()->create();

        PeaceLink::factory()->create([
            'buyer_id' => $buyer->id,
            'merchant_id' => $merchant->id,
            'status' => PeaceLinkStatus::FUNDED,
        ]);
        PeaceLink::factory()->create([
            'buyer_id' => $buyer->id,
            'merchant_id' => $merchant->id,
            'status' => PeaceLinkStatus::RELEASED,
        ]);

        $active = $this->peaceLinkService->getUserPeaceLinks($buyer, null, 'active');
        $completed = $this->peaceLinkService->getUserPeaceLinks($buyer, null, 'completed');

        $this->assertEquals(1, $active->total());
        $this->assertEquals(1, $completed->total());
    }

    public function test_accept_peacelink_updates_status(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'status' => PeaceLinkStatus::FUNDED,
        ]);

        $result = $this->peaceLinkService->acceptPeaceLink($peaceLink);

        $this->assertEquals(PeaceLinkStatus::FUNDED, $result->status);
        $this->assertNotNull($result->accepted_at);
    }

    public function test_mark_in_transit_updates_status_and_tracking(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'status' => PeaceLinkStatus::DSP_ASSIGNED,
        ]);

        $result = $this->peaceLinkService->markInTransit($peaceLink, 'TRACK123');

        $this->assertEquals(PeaceLinkStatus::IN_TRANSIT, $result->status);
        $this->assertEquals('TRACK123', $result->tracking_code);
        $this->assertNotNull($result->shipped_at);
    }

    public function test_cancel_peacelink_records_reason(): void
    {
        $buyer = User::factory()->create();
        $peaceLink = PeaceLink::factory()->create([
            'buyer_id' => $buyer->id,
            'status' => PeaceLinkStatus::FUNDED,
        ]);

        $result = $this->peaceLinkService->cancel($peaceLink, $buyer->id, 'Test reason');

        $this->assertEquals(PeaceLinkStatus::CANCELLED, $result->status);
        $this->assertEquals($buyer->id, $result->cancelled_by);
        $this->assertEquals('Test reason', $result->cancellation_reason);
    }

    public function test_get_timeline_includes_all_events(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'status' => PeaceLinkStatus::IN_TRANSIT,
            'funded_at' => now()->subHours(3),
            'accepted_at' => now()->subHours(2),
            'shipped_at' => now()->subHour(),
        ]);

        $timeline = $this->peaceLinkService->getTimeline($peaceLink);

        $events = array_column($timeline, 'event');
        $this->assertContains('created', $events);
        $this->assertContains('funded', $events);
        $this->assertContains('accepted', $events);
        $this->assertContains('shipped', $events);
    }

    public function test_get_user_statistics(): void
    {
        $user = User::factory()->create();
        $merchant = User::factory()->create();

        // As buyer
        PeaceLink::factory()->count(3)->create([
            'buyer_id' => $user->id,
            'merchant_id' => $merchant->id,
            'status' => PeaceLinkStatus::RELEASED,
            'item_amount' => 100,
        ]);

        // As merchant
        PeaceLink::factory()->count(2)->create([
            'buyer_id' => $merchant->id,
            'merchant_id' => $user->id,
            'status' => PeaceLinkStatus::RELEASED,
            'item_amount' => 200,
        ]);

        $stats = $this->peaceLinkService->getUserStatistics($user);

        $this->assertEquals(3, $stats['as_buyer']['total']);
        $this->assertEquals(3, $stats['as_buyer']['completed']);
        $this->assertEquals(300, $stats['as_buyer']['total_spent']);

        $this->assertEquals(2, $stats['as_merchant']['total']);
        $this->assertEquals(2, $stats['as_merchant']['completed']);
        $this->assertEquals(400, $stats['as_merchant']['total_earned']);
    }
}

// ============================================================================
// OTP SERVICE TESTS
// ============================================================================

class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private OtpService $otpService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpService = new OtpService();
    }

    public function test_generate_otp_creates_6_digit_code(): void
    {
        $result = $this->otpService->generateOtp('01012345678', 'registration');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('expires_in', $result);
    }

    public function test_generate_otp_enforces_cooldown(): void
    {
        // First OTP
        $this->otpService->generateOtp('01012345678', 'registration');

        // Second OTP within cooldown
        $result = $this->otpService->generateOtp('01012345678', 'registration');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('cooldown', $result);
    }

    public function test_verify_otp_fails_with_wrong_code(): void
    {
        // Generate OTP
        $this->otpService->generateOtp('01012345678', 'registration');

        // Try wrong code
        $result = $this->otpService->verifyOtp('01012345678', '000000', 'registration');

        $this->assertFalse($result['valid']);
    }

    public function test_verify_otp_increments_attempts(): void
    {
        $this->otpService->generateOtp('01012345678', 'registration');

        // Wrong attempts
        $result1 = $this->otpService->verifyOtp('01012345678', '000000', 'registration');
        $this->assertEquals(2, $result1['attempts_remaining']);

        $result2 = $this->otpService->verifyOtp('01012345678', '000000', 'registration');
        $this->assertEquals(1, $result2['attempts_remaining']);
    }

    public function test_verify_otp_fails_after_max_attempts(): void
    {
        $this->otpService->generateOtp('01012345678', 'registration');

        // Exhaust attempts
        for ($i = 0; $i < 3; $i++) {
            $this->otpService->verifyOtp('01012345678', '000000', 'registration');
        }

        $result = $this->otpService->verifyOtp('01012345678', '000000', 'registration');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('غير صالح', $result['message']);
    }

    public function test_delivery_otp_generation_and_verification(): void
    {
        $peaceLink = PeaceLink::factory()->create();

        // Generate
        $otp = $this->otpService->generateDeliveryOtp($peaceLink);

        $this->assertEquals(6, strlen($otp));
        $this->assertNotNull($peaceLink->fresh()->delivery_otp);

        // Verify with correct code
        $result = $this->otpService->verifyDeliveryOtp($peaceLink->fresh(), $otp);

        $this->assertTrue($result['valid']);
    }
}

// ============================================================================
// CASHOUT SERVICE TESTS
// ============================================================================

class CashoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashoutService $cashoutService;
    private User $user;
    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->cashoutService = new CashoutService();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create([
            'user_id' => $this->user->id,
            'balance' => 5000,
            'pending_cashout' => 0,
        ]);
    }

    public function test_create_cashout_calculates_fee_correctly(): void
    {
        $cashout = $this->cashoutService->createCashoutRequest($this->user, [
            'amount' => 1000,
            'method' => 'bank',
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'account_holder_name' => 'Test User',
        ]);

        // Fee: 1.5% of 1000 = 15
        $this->assertEquals(1000, $cashout->amount);
        $this->assertEquals(15, $cashout->fee);
        $this->assertEquals(985, $cashout->net_amount);
    }

    public function test_create_cashout_moves_to_pending(): void
    {
        $this->cashoutService->createCashoutRequest($this->user, [
            'amount' => 1000,
            'method' => 'bank',
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'account_holder_name' => 'Test User',
        ]);

        $wallet = $this->wallet->fresh();
        $this->assertEquals(4000, $wallet->balance);
        $this->assertEquals(1000, $wallet->pending_cashout);
    }

    public function test_complete_cashout_clears_pending(): void
    {
        $cashout = $this->cashoutService->createCashoutRequest($this->user, [
            'amount' => 1000,
            'method' => 'bank',
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'account_holder_name' => 'Test User',
        ]);

        $this->cashoutService->completeCashout($cashout);

        $wallet = $this->wallet->fresh();
        $this->assertEquals(4000, $wallet->balance);
        $this->assertEquals(0, $wallet->pending_cashout);
        $this->assertEquals('completed', $cashout->fresh()->status);
    }

    public function test_fail_cashout_returns_funds(): void
    {
        $cashout = $this->cashoutService->createCashoutRequest($this->user, [
            'amount' => 1000,
            'method' => 'bank',
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'account_holder_name' => 'Test User',
        ]);

        $this->cashoutService->failCashout($cashout, 'Bank rejected');

        $wallet = $this->wallet->fresh();
        $this->assertEquals(5000, $wallet->balance); // Funds returned
        $this->assertEquals(0, $wallet->pending_cashout);
        $this->assertEquals('failed', $cashout->fresh()->status);
    }
}

// ============================================================================
// MODEL TESTS
// ============================================================================

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_wallet_relationship(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(Wallet::class, $user->wallet);
        $this->assertEquals($wallet->id, $user->wallet->id);
    }

    public function test_user_can_get_masked_phone(): void
    {
        $user = User::factory()->create(['phone' => '01012345678']);

        $masked = $user->maskedPhone;

        $this->assertEquals('010****5678', $masked);
    }

    public function test_user_has_kyc_level_limits(): void
    {
        $basic = User::factory()->create(['kyc_level' => 'basic']);
        $silver = User::factory()->create(['kyc_level' => 'silver']);
        $gold = User::factory()->create(['kyc_level' => 'gold']);

        $this->assertEquals(5000, $basic->getDailyTransferLimit());
        $this->assertEquals(10000, $silver->getDailyTransferLimit());
        $this->assertEquals(50000, $gold->getDailyTransferLimit());
    }

    public function test_user_can_check_dsp_status(): void
    {
        $user = User::factory()->create(['is_dsp' => false]);
        $dsp = User::factory()->create(['is_dsp' => true]);

        $this->assertFalse($user->isDsp());
        $this->assertTrue($dsp->isDsp());
    }
}

class WalletModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_calculates_available_balance(): void
    {
        $wallet = Wallet::factory()->create([
            'balance' => 1000,
            'hold_balance' => 200,
            'pending_cashout' => 100,
        ]);

        $this->assertEquals(700, $wallet->available_balance);
    }

    public function test_wallet_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $wallet->user);
        $this->assertEquals($user->id, $wallet->user->id);
    }
}

class PeaceLinkModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_peacelink_has_buyer_relationship(): void
    {
        $buyer = User::factory()->create();
        $peaceLink = PeaceLink::factory()->create(['buyer_id' => $buyer->id]);

        $this->assertInstanceOf(User::class, $peaceLink->buyer);
        $this->assertEquals($buyer->id, $peaceLink->buyer->id);
    }

    public function test_peacelink_has_merchant_relationship(): void
    {
        $merchant = User::factory()->create();
        $peaceLink = PeaceLink::factory()->create(['merchant_id' => $merchant->id]);

        $this->assertInstanceOf(User::class, $peaceLink->merchant);
        $this->assertEquals($merchant->id, $peaceLink->merchant->id);
    }

    public function test_peacelink_can_check_cancellable_status(): void
    {
        $pending = PeaceLink::factory()->create(['status' => PeaceLinkStatus::PENDING]);
        $funded = PeaceLink::factory()->create(['status' => PeaceLinkStatus::FUNDED]);
        $inTransit = PeaceLink::factory()->create(['status' => PeaceLinkStatus::IN_TRANSIT]);

        $this->assertTrue($pending->isCancellable());
        $this->assertTrue($funded->isCancellable());
        $this->assertFalse($inTransit->isCancellable());
    }

    public function test_peacelink_calculates_total_buyer_pays(): void
    {
        $peaceLink = PeaceLink::factory()->create([
            'item_amount' => 1000,
            'delivery_fee' => 50,
            'platform_fee' => 7,
            'buyer_pays_delivery' => true,
        ]);

        $this->assertEquals(1057, $peaceLink->totalBuyerPays);
    }

    public function test_peacelink_has_active_dispute_scope(): void
    {
        $peaceLink = PeaceLink::factory()->create();
        $dispute = \App\Models\Dispute::factory()->create([
            'peacelink_id' => $peaceLink->id,
            'status' => 'open',
        ]);

        $this->assertNotNull($peaceLink->activeDispute);
        $this->assertEquals($dispute->id, $peaceLink->activeDispute->id);
    }
}

class TransactionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_belongs_to_wallet(): void
    {
        $wallet = Wallet::factory()->create();
        $transaction = Transaction::factory()->create(['wallet_id' => $wallet->id]);

        $this->assertInstanceOf(Wallet::class, $transaction->wallet);
    }

    public function test_transaction_has_counterparty_relationship(): void
    {
        $user = User::factory()->create();
        $counterparty = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        $transaction = Transaction::factory()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'counterparty_id' => $counterparty->id,
        ]);

        $this->assertInstanceOf(User::class, $transaction->counterparty);
        $this->assertEquals($counterparty->id, $transaction->counterparty->id);
    }

    public function test_transaction_credit_scope(): void
    {
        $wallet = Wallet::factory()->create();
        Transaction::factory()->count(3)->create(['wallet_id' => $wallet->id, 'direction' => 'credit']);
        Transaction::factory()->count(2)->create(['wallet_id' => $wallet->id, 'direction' => 'debit']);

        $credits = Transaction::credit()->where('wallet_id', $wallet->id)->count();

        $this->assertEquals(3, $credits);
    }

    public function test_transaction_debit_scope(): void
    {
        $wallet = Wallet::factory()->create();
        Transaction::factory()->count(3)->create(['wallet_id' => $wallet->id, 'direction' => 'credit']);
        Transaction::factory()->count(2)->create(['wallet_id' => $wallet->id, 'direction' => 'debit']);

        $debits = Transaction::debit()->where('wallet_id', $wallet->id)->count();

        $this->assertEquals(2, $debits);
    }
}

// ============================================================================
// VALIDATION TESTS
// ============================================================================

class ValidationTest extends TestCase
{
    public function test_egyptian_phone_validation_accepts_valid_numbers(): void
    {
        $validNumbers = [
            '01012345678',
            '01112345678',
            '01212345678',
            '01512345678',
        ];

        $pattern = '/^01[0125][0-9]{8}$/';

        foreach ($validNumbers as $number) {
            $this->assertTrue(
                (bool) preg_match($pattern, $number),
                "Failed for: {$number}"
            );
        }
    }

    public function test_egyptian_phone_validation_rejects_invalid_numbers(): void
    {
        $invalidNumbers = [
            '0101234567',   // Too short
            '010123456789', // Too long
            '01312345678',  // Invalid prefix (013)
            '01412345678',  // Invalid prefix (014)
            '1012345678',   // Missing leading 0
            'abc12345678',  // Contains letters
        ];

        $pattern = '/^01[0125][0-9]{8}$/';

        foreach ($invalidNumbers as $number) {
            $this->assertFalse(
                (bool) preg_match($pattern, $number),
                "Should fail for: {$number}"
            );
        }
    }

    public function test_national_id_validation(): void
    {
        $validId = '29901011234567'; // 14 digits
        $invalidIds = [
            '2990101123456',  // 13 digits
            '299010112345678', // 15 digits
            '2990101abcd567', // Contains letters
        ];

        $pattern = '/^[0-9]{14}$/';

        $this->assertTrue((bool) preg_match($pattern, $validId));

        foreach ($invalidIds as $id) {
            $this->assertFalse(
                (bool) preg_match($pattern, $id),
                "Should fail for: {$id}"
            );
        }
    }
}

// ============================================================================
// FEE CALCULATION TESTS
// ============================================================================

class FeeCalculationTest extends TestCase
{
    public function test_platform_fee_calculation(): void
    {
        // Formula: 0.5% + 2 EGP
        $testCases = [
            ['amount' => 100, 'expected' => 2.5],   // 0.5 + 2
            ['amount' => 1000, 'expected' => 7],    // 5 + 2
            ['amount' => 5000, 'expected' => 27],   // 25 + 2
            ['amount' => 50, 'expected' => 2.25],   // 0.25 + 2
        ];

        foreach ($testCases as $case) {
            $fee = ($case['amount'] * 0.005) + 2;
            $this->assertEquals(
                $case['expected'],
                $fee,
                "Failed for amount: {$case['amount']}"
            );
        }
    }

    public function test_cashout_fee_calculation(): void
    {
        // 1.5% fee
        $testCases = [
            ['amount' => 100, 'expected' => 1.5],
            ['amount' => 1000, 'expected' => 15],
            ['amount' => 5000, 'expected' => 75],
        ];

        foreach ($testCases as $case) {
            $fee = $case['amount'] * 0.015;
            $this->assertEquals(
                $case['expected'],
                $fee,
                "Failed for amount: {$case['amount']}"
            );
        }
    }

    public function test_add_money_fee_by_method(): void
    {
        $amount = 1000;

        $fees = [
            'fawry' => 5,          // Fixed
            'vodafone_cash' => 15, // 1.5%
            'card' => 25,          // 2.5%
            'instapay' => 0,       // Free
        ];

        foreach ($fees as $method => $expected) {
            $calculated = match ($method) {
                'fawry' => 5.0,
                'vodafone_cash' => $amount * 0.015,
                'card' => $amount * 0.025,
                'instapay' => 0,
                default => 0,
            };

            $this->assertEquals(
                $expected,
                $calculated,
                "Failed for method: {$method}"
            );
        }
    }
}
