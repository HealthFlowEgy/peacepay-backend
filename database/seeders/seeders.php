<?php

/**
 * PeacePay Database Seeders
 * 
 * Seeders for development and testing.
 * In production, split each into separate files in database/seeders/
 */

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\PeaceLink;
use App\Models\Dispute;
use App\Models\CashoutRequest;
use App\Models\KycRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

// ============================================================================
// DatabaseSeeder (Main)
// ============================================================================

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            WalletSeeder::class,
            TransactionSeeder::class,
            PeaceLinkSeeder::class,
            DisputeSeeder::class,
            CashoutSeeder::class,
            KycSeeder::class,
        ]);
    }
}


// ============================================================================
// UserSeeder
// ============================================================================

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'مدير النظام',
            'phone' => '01000000001',
            'email' => 'admin@peacepay.com',
            'password' => Hash::make('password123'),
            'kyc_level' => 'gold',
            'is_admin' => true,
            'is_active' => true,
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);

        // Test users with different KYC levels
        $testUsers = [
            [
                'name' => 'أحمد محمد',
                'phone' => '01012345678',
                'email' => 'ahmed@test.com',
                'kyc_level' => 'gold',
            ],
            [
                'name' => 'محمد علي',
                'phone' => '01112345678',
                'email' => 'mohamed@test.com',
                'kyc_level' => 'silver',
            ],
            [
                'name' => 'فاطمة حسن',
                'phone' => '01212345678',
                'email' => 'fatma@test.com',
                'kyc_level' => 'basic',
            ],
            [
                'name' => 'علي إبراهيم',
                'phone' => '01512345678',
                'email' => 'ali@test.com',
                'kyc_level' => 'silver',
            ],
            [
                'name' => 'سارة أحمد',
                'phone' => '01023456789',
                'email' => 'sara@test.com',
                'kyc_level' => 'gold',
            ],
        ];

        foreach ($testUsers as $userData) {
            User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $userData['name'],
                'phone' => $userData['phone'],
                'email' => $userData['email'],
                'password' => Hash::make('password123'),
                'kyc_level' => $userData['kyc_level'],
                'is_active' => true,
                'phone_verified_at' => now(),
            ]);
        }

        // DSP users (Delivery Service Providers)
        $dspUsers = [
            ['name' => 'شركة التوصيل السريع', 'phone' => '01099000001'],
            ['name' => 'خدمات التوصيل المتحدة', 'phone' => '01099000002'],
            ['name' => 'توصيل إكسبريس', 'phone' => '01099000003'],
        ];

        foreach ($dspUsers as $dsp) {
            User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $dsp['name'],
                'phone' => $dsp['phone'],
                'password' => Hash::make('password123'),
                'kyc_level' => 'gold',
                'is_dsp' => true,
                'is_active' => true,
                'phone_verified_at' => now(),
            ]);
        }

        // Additional random users for testing pagination
        for ($i = 1; $i <= 20; $i++) {
            $phonePrefix = ['010', '011', '012', '015'][array_rand(['010', '011', '012', '015'])];
            User::create([
                'uuid' => (string) Str::uuid(),
                'name' => "مستخدم تجريبي {$i}",
                'phone' => $phonePrefix . str_pad($i, 8, '0', STR_PAD_LEFT),
                'password' => Hash::make('password123'),
                'kyc_level' => ['basic', 'silver', 'gold'][array_rand(['basic', 'silver', 'gold'])],
                'is_active' => true,
                'phone_verified_at' => now(),
            ]);
        }
    }
}


// ============================================================================
// WalletSeeder
// ============================================================================

class WalletSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            // Determine balance based on KYC level
            $baseBalance = match($user->kyc_level) {
                'gold' => rand(5000, 50000),
                'silver' => rand(1000, 10000),
                'basic' => rand(100, 2000),
            };

            Wallet::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'balance' => $baseBalance,
                'hold_balance' => 0,
                'pending_cashout' => 0,
                'currency' => 'EGP',
                'is_active' => true,
            ]);
        }
    }
}


// ============================================================================
// TransactionSeeder
// ============================================================================

class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::whereNotNull('phone_verified_at')->get();

        foreach ($users as $user) {
            $wallet = $user->wallet;
            if (!$wallet) continue;

            $currentBalance = 0;

            // Generate add_money transactions (initial deposits)
            $addMoneyCount = rand(2, 5);
            for ($i = 0; $i < $addMoneyCount; $i++) {
                $amount = rand(100, 5000);
                $method = ['fawry', 'vodafone_cash', 'card', 'instapay'][array_rand(['fawry', 'vodafone_cash', 'card', 'instapay'])];
                $fee = $this->calculateFee($amount, $method);
                $netAmount = $amount - $fee;
                
                $balanceBefore = $currentBalance;
                $currentBalance += $netAmount;

                Transaction::create([
                    'uuid' => (string) Str::uuid(),
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'reference' => 'TXN-' . strtoupper(Str::random(8)),
                    'type' => 'add_money',
                    'direction' => 'credit',
                    'amount' => $netAmount,
                    'fee' => $fee,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $currentBalance,
                    'status' => 'completed',
                    'payment_method' => $method,
                    'payment_reference' => 'PAY-' . strtoupper(Str::random(10)),
                    'description' => 'إضافة رصيد عبر ' . $this->getMethodLabel($method),
                    'created_at' => Carbon::now()->subDays(rand(1, 30)),
                ]);
            }

            // Generate send/receive transactions
            $sendCount = rand(1, 3);
            for ($i = 0; $i < $sendCount; $i++) {
                $recipient = $users->where('id', '!=', $user->id)->random();
                $amount = rand(50, min(500, $currentBalance - 10));
                
                if ($amount <= 0) continue;

                $balanceBefore = $currentBalance;
                $currentBalance -= $amount;

                // Sender transaction
                Transaction::create([
                    'uuid' => (string) Str::uuid(),
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'reference' => 'TXN-' . strtoupper(Str::random(8)),
                    'type' => 'send',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'fee' => 0,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $currentBalance,
                    'status' => 'completed',
                    'counterparty_id' => $recipient->id,
                    'description' => 'تحويل إلى ' . $recipient->name,
                    'created_at' => Carbon::now()->subDays(rand(1, 20)),
                ]);

                // Recipient transaction
                $recipientWallet = $recipient->wallet;
                if ($recipientWallet) {
                    Transaction::create([
                        'uuid' => (string) Str::uuid(),
                        'wallet_id' => $recipientWallet->id,
                        'user_id' => $recipient->id,
                        'reference' => 'TXN-' . strtoupper(Str::random(8)),
                        'type' => 'receive',
                        'direction' => 'credit',
                        'amount' => $amount,
                        'fee' => 0,
                        'balance_before' => $recipientWallet->balance,
                        'balance_after' => $recipientWallet->balance + $amount,
                        'status' => 'completed',
                        'counterparty_id' => $user->id,
                        'description' => 'تحويل من ' . $user->name,
                        'created_at' => Carbon::now()->subDays(rand(1, 20)),
                    ]);
                }
            }
        }
    }

    private function calculateFee(float $amount, string $method): float
    {
        return match($method) {
            'fawry' => 5.00,
            'vodafone_cash' => $amount * 0.015,
            'card' => $amount * 0.025,
            'instapay' => 0.00,
            default => 0.00,
        };
    }

    private function getMethodLabel(string $method): string
    {
        return match($method) {
            'fawry' => 'فوري',
            'vodafone_cash' => 'فودافون كاش',
            'card' => 'بطاقة ائتمان',
            'instapay' => 'إنستاباي',
            default => $method,
        };
    }
}


// ============================================================================
// PeaceLinkSeeder
// ============================================================================

class PeaceLinkSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('is_dsp', false)
            ->whereNotNull('phone_verified_at')
            ->get();
        
        $dsps = User::where('is_dsp', true)->get();

        $products = [
            ['name' => 'آيفون 15 برو ماكس', 'price' => 65000],
            ['name' => 'لابتوب ديل XPS 15', 'price' => 45000],
            ['name' => 'ساعة أبل الترا', 'price' => 35000],
            ['name' => 'سماعات سوني WH-1000XM5', 'price' => 12000],
            ['name' => 'كاميرا كانون R5', 'price' => 85000],
            ['name' => 'جهاز بلايستيشن 5', 'price' => 25000],
            ['name' => 'تابلت سامسونج S9', 'price' => 28000],
            ['name' => 'شاشة LG OLED 55 بوصة', 'price' => 42000],
        ];

        $cities = ['القاهرة', 'الجيزة', 'الإسكندرية', 'المنصورة', 'طنطا', 'أسيوط', 'الأقصر'];

        // Create various PeaceLinks in different statuses
        $statuses = ['pending', 'funded', 'dsp_assigned', 'in_transit', 'delivered', 'released', 'cancelled'];

        for ($i = 0; $i < 30; $i++) {
            $buyer = $users->random();
            $merchant = $users->where('id', '!=', $buyer->id)->random();
            $product = $products[array_rand($products)];
            $status = $statuses[array_rand($statuses)];
            
            $itemAmount = $product['price'] * (rand(80, 100) / 100); // Some variation
            $deliveryFee = rand(50, 200);
            $platformFee = ($itemAmount * 0.005) + 2; // 0.5% + 2 EGP
            $totalAmount = $itemAmount + $platformFee + $deliveryFee;

            $createdAt = Carbon::now()->subDays(rand(1, 60));
            
            $peaceLinkData = [
                'uuid' => (string) Str::uuid(),
                'reference' => 'PL-' . strtoupper(Str::random(8)),
                'buyer_id' => $buyer->id,
                'merchant_id' => $merchant->id,
                'status' => $status,
                'product_description' => $product['name'],
                'item_amount' => $itemAmount,
                'quantity' => 1,
                'platform_fee' => $platformFee,
                'delivery_fee' => $deliveryFee,
                'delivery_fee_payer' => ['buyer', 'merchant'][array_rand(['buyer', 'merchant'])],
                'total_amount' => $totalAmount,
                'delivery_address' => 'شارع ' . rand(1, 100) . '، المنطقة ' . chr(rand(65, 90)),
                'delivery_city' => $cities[array_rand($cities)],
                'delivery_phone' => $buyer->phone,
                'delivery_notes' => rand(0, 1) ? 'يرجى الاتصال قبل التوصيل' : null,
                'dsp_type' => 'external',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            // Set timestamps based on status progression
            if (in_array($status, ['funded', 'dsp_assigned', 'in_transit', 'delivered', 'released'])) {
                $peaceLinkData['accepted_at'] = $createdAt->copy()->addHours(rand(1, 24));
            }

            if (in_array($status, ['dsp_assigned', 'in_transit', 'delivered', 'released'])) {
                $dsp = $dsps->random();
                $peaceLinkData['dsp_id'] = $dsp->id;
                $peaceLinkData['dsp_type'] = 'internal';
            }

            if (in_array($status, ['in_transit', 'delivered', 'released'])) {
                $peaceLinkData['shipped_at'] = $createdAt->copy()->addDays(rand(1, 3));
                $peaceLinkData['tracking_code'] = 'TRK-' . strtoupper(Str::random(10));
            }

            if (in_array($status, ['delivered', 'released'])) {
                $peaceLinkData['delivered_at'] = $createdAt->copy()->addDays(rand(3, 7));
            }

            if ($status === 'released') {
                $peaceLinkData['released_at'] = $createdAt->copy()->addDays(rand(3, 7));
            }

            if ($status === 'cancelled') {
                $peaceLinkData['cancelled_at'] = $createdAt->copy()->addDays(rand(0, 2));
                $peaceLinkData['cancelled_by'] = ['buyer', 'merchant'][array_rand(['buyer', 'merchant'])];
                $peaceLinkData['cancellation_reason'] = 'تغير رأي المشتري';
            }

            PeaceLink::create($peaceLinkData);
        }
    }
}


// ============================================================================
// DisputeSeeder
// ============================================================================

class DisputeSeeder extends Seeder
{
    public function run(): void
    {
        // Get PeaceLinks that can have disputes (in_transit, delivered, or disputed)
        $peaceLinks = PeaceLink::whereIn('status', ['in_transit', 'delivered', 'released'])
            ->take(5)
            ->get();

        $reasons = ['not_received', 'wrong_item', 'damaged', 'other'];
        $resolutions = ['buyer', 'merchant', 'split'];

        foreach ($peaceLinks as $peaceLink) {
            $isBuyerOpening = rand(0, 1);
            $opener = $isBuyerOpening ? $peaceLink->buyer : $peaceLink->merchant;
            $respondent = $isBuyerOpening ? $peaceLink->merchant : $peaceLink->buyer;
            
            $status = ['pending', 'under_review', 'resolved'][array_rand(['pending', 'under_review', 'resolved'])];
            $createdAt = Carbon::parse($peaceLink->created_at)->addDays(rand(5, 10));

            $disputeData = [
                'uuid' => (string) Str::uuid(),
                'reference' => 'DSP-' . strtoupper(Str::random(8)),
                'peace_link_id' => $peaceLink->id,
                'opened_by' => $opener->id,
                'respondent_id' => $respondent->id,
                'reason' => $reasons[array_rand($reasons)],
                'description' => 'وصف المشكلة: ' . ($isBuyerOpening 
                    ? 'المنتج لم يصل بالحالة المتوقعة' 
                    : 'المشتري يدعي مشكلة غير صحيحة'),
                'status' => $status,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if ($status === 'under_review' || $status === 'resolved') {
                $disputeData['respondent_response'] = 'رد الطرف الآخر: أعتقد أن المشكلة ليست من جانبي';
                $disputeData['respondent_response_at'] = $createdAt->copy()->addDays(1);
            }

            if ($status === 'resolved') {
                $resolution = $resolutions[array_rand($resolutions)];
                $disputeData['resolution'] = $resolution;
                $disputeData['resolution_notes'] = 'تم حل النزاع لصالح ' . match($resolution) {
                    'buyer' => 'المشتري',
                    'merchant' => 'البائع',
                    'split' => 'الطرفين بالتساوي',
                };
                $disputeData['resolved_at'] = $createdAt->copy()->addDays(rand(3, 7));
                $disputeData['resolved_by'] = User::where('is_admin', true)->first()?->id;

                if ($resolution === 'buyer') {
                    $disputeData['refund_amount'] = $peaceLink->item_amount;
                } elseif ($resolution === 'split') {
                    $disputeData['refund_amount'] = $peaceLink->item_amount / 2;
                }
            }

            Dispute::create($disputeData);

            // Update PeaceLink status if disputed
            if ($status !== 'resolved') {
                $peaceLink->update(['status' => 'disputed']);
            }
        }
    }
}


// ============================================================================
// CashoutSeeder
// ============================================================================

class CashoutSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('kyc_level', '!=', 'basic')
            ->whereNotNull('phone_verified_at')
            ->take(10)
            ->get();

        $banks = [
            ['name' => 'البنك الأهلي المصري', 'code' => 'NBE'],
            ['name' => 'بنك مصر', 'code' => 'BM'],
            ['name' => 'البنك التجاري الدولي', 'code' => 'CIB'],
            ['name' => 'بنك القاهرة', 'code' => 'CAI'],
            ['name' => 'QNB الأهلي', 'code' => 'QNB'],
        ];

        foreach ($users as $user) {
            $wallet = $user->wallet;
            if (!$wallet || $wallet->balance < 100) continue;

            $cashoutCount = rand(1, 3);

            for ($i = 0; $i < $cashoutCount; $i++) {
                $amount = rand(100, min(1000, (int)$wallet->balance));
                $fee = $amount * 0.015; // 1.5%
                $netAmount = $amount - $fee;
                
                $method = ['bank', 'wallet'][array_rand(['bank', 'wallet'])];
                $status = ['pending', 'processing', 'completed', 'failed'][array_rand(['pending', 'processing', 'completed', 'failed'])];
                $createdAt = Carbon::now()->subDays(rand(1, 30));

                $cashoutData = [
                    'uuid' => (string) Str::uuid(),
                    'reference' => 'CO-' . strtoupper(Str::random(8)),
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                    'amount' => $amount,
                    'fee' => $fee,
                    'net_amount' => $netAmount,
                    'method' => $method,
                    'status' => $status,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                if ($method === 'bank') {
                    $bank = $banks[array_rand($banks)];
                    $cashoutData['bank_name'] = $bank['name'];
                    $cashoutData['bank_code'] = $bank['code'];
                    $cashoutData['account_number'] = str_pad(rand(1, 9999999999), 16, '0', STR_PAD_LEFT);
                    $cashoutData['account_holder_name'] = $user->name;
                } else {
                    $cashoutData['wallet_phone'] = $user->phone;
                    $cashoutData['wallet_provider'] = ['vodafone_cash', 'orange_cash', 'etisalat_cash'][array_rand(['vodafone_cash', 'orange_cash', 'etisalat_cash'])];
                }

                if ($status === 'processing') {
                    $cashoutData['processed_at'] = $createdAt->copy()->addHours(rand(1, 24));
                }

                if ($status === 'completed') {
                    $cashoutData['processed_at'] = $createdAt->copy()->addHours(rand(1, 12));
                    $cashoutData['completed_at'] = $createdAt->copy()->addDays(rand(1, 3));
                }

                if ($status === 'failed') {
                    $cashoutData['failure_reason'] = 'بيانات الحساب البنكي غير صحيحة';
                }

                CashoutRequest::create($cashoutData);
            }
        }
    }
}


// ============================================================================
// KycSeeder
// ============================================================================

class KycSeeder extends Seeder
{
    public function run(): void
    {
        $basicUsers = User::where('kyc_level', 'basic')->take(5)->get();
        $silverUsers = User::where('kyc_level', 'silver')->take(3)->get();

        // Create pending upgrade requests for basic users
        foreach ($basicUsers as $user) {
            if (rand(0, 1)) {
                $createdAt = Carbon::now()->subDays(rand(1, 7));
                
                KycRequest::create([
                    'uuid' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'current_level' => 'basic',
                    'target_level' => 'silver',
                    'national_id' => str_pad(rand(1, 99999999999999), 14, '0', STR_PAD_LEFT),
                    'national_id_front_url' => 'kyc/national-ids/sample-front.jpg',
                    'national_id_back_url' => 'kyc/national-ids/sample-back.jpg',
                    'status' => ['pending', 'under_review'][array_rand(['pending', 'under_review'])],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }
        }

        // Create some approved/rejected requests for history
        foreach ($silverUsers as $user) {
            $createdAt = Carbon::now()->subDays(rand(30, 60));
            $reviewedAt = $createdAt->copy()->addDays(rand(1, 3));
            $isApproved = rand(0, 1);

            KycRequest::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'current_level' => 'basic',
                'target_level' => 'silver',
                'national_id' => str_pad(rand(1, 99999999999999), 14, '0', STR_PAD_LEFT),
                'national_id_front_url' => 'kyc/national-ids/sample-front.jpg',
                'national_id_back_url' => 'kyc/national-ids/sample-back.jpg',
                'status' => $isApproved ? 'approved' : 'rejected',
                'rejection_reason' => !$isApproved ? 'صورة البطاقة غير واضحة' : null,
                'reviewed_by' => User::where('is_admin', true)->first()?->id,
                'reviewed_at' => $reviewedAt,
                'created_at' => $createdAt,
                'updated_at' => $reviewedAt,
            ]);
        }

        // Create gold upgrade requests
        $goldCandidates = User::where('kyc_level', 'silver')->take(2)->get();
        foreach ($goldCandidates as $user) {
            $createdAt = Carbon::now()->subDays(rand(1, 5));

            KycRequest::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'current_level' => 'silver',
                'target_level' => 'gold',
                'national_id' => str_pad(rand(1, 99999999999999), 14, '0', STR_PAD_LEFT),
                'national_id_front_url' => 'kyc/national-ids/sample-front.jpg',
                'national_id_back_url' => 'kyc/national-ids/sample-back.jpg',
                'selfie_url' => 'kyc/selfies/sample-selfie.jpg',
                'address_proof_url' => 'kyc/address-proofs/sample-proof.jpg',
                'status' => 'pending',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}


// ============================================================================
// Factory Definitions (for use with php artisan tinker or tests)
// ============================================================================

/*
To use factories in tests, create these files in database/factories/:

UserFactory.php:
- Creates users with random Egyptian phone numbers
- Supports states: verified, admin, dsp, withWallet

WalletFactory.php:
- Creates wallets with random balances
- Supports states: funded, empty, withHold

PeaceLinkFactory.php:
- Creates PeaceLinks with all required relationships
- Supports states for each status

TransactionFactory.php:
- Creates transactions with proper balance tracking
- Supports types: addMoney, send, receive, peacelinkHold, etc.

Example factory usage in tests:

User::factory()
    ->verified()
    ->withWallet(5000)
    ->create();

PeaceLink::factory()
    ->funded()
    ->for(User::factory()->verified(), 'buyer')
    ->for(User::factory()->verified(), 'merchant')
    ->create();
*/
