<?php
/**
 * PeacePay Cancellation Service - Bug Fixes
 * 
 * Fixes:
 * - BUG-001: Cancellation fee logic before DSP assignment
 * - BUG-003: PeacePay profit not updated on buyer cancel after DSP
 * - Scenario 5: Merchant cancel after DSP assigned
 */

namespace App\Services;

use App\Models\PeaceLink;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\PlatformProfit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancellationService
{
    const MERCHANT_FEE_PERCENTAGE = 0.01; // 1%
    const MERCHANT_FIXED_FEE = 3; // 3 EGP
    const DSP_FEE_PERCENTAGE = 0.005; // 0.5%

    /**
     * Process buyer cancellation
     * 
     * BUG-001 FIX: Skip fees if no DSP assigned
     * BUG-003 FIX: Update profit ledger on DSP payout
     */
    public function processBuyerCancellation(PeaceLink $peacelink, string $reason = null): array
    {
        return DB::transaction(function () use ($peacelink, $reason) {
            $result = [
                'buyer_refund' => 0,
                'dsp_payout' => 0,
                'platform_profit' => 0,
                'status' => 'cancelled_by_buyer'
            ];

            // Check if DSP is assigned
            $dspAssigned = !empty($peacelink->dsp_wallet) && 
                           in_array($peacelink->status, ['dsp_assigned', 'in_transit']);

            if ($dspAssigned) {
                // Scenario: Buyer cancels AFTER DSP assigned
                // - Refund item amount only to buyer
                // - DSP gets delivery fee
                // - Merchant gets nothing
                
                $buyerRefund = $peacelink->item_price;
                $dspPayout = $peacelink->delivery_fee * (1 - self::DSP_FEE_PERCENTAGE);
                $dspFee = $peacelink->delivery_fee * self::DSP_FEE_PERCENTAGE;

                // Refund buyer (item only, NOT delivery fee)
                $this->creditWallet($peacelink->buyer_wallet, $buyerRefund, 
                    'peacelink_refund', "Refund for PeaceLink #{$peacelink->id} (item only)");

                // Pay DSP
                $this->creditWallet($peacelink->dsp_wallet, $dspPayout,
                    'dsp_payout', "Delivery fee for PeaceLink #{$peacelink->id}");

                // BUG-003 FIX: Update platform profit ledger
                $this->creditPlatformProfit($dspFee, $peacelink->id, 'dsp_fee');

                $result['buyer_refund'] = $buyerRefund;
                $result['dsp_payout'] = $dspPayout;
                $result['platform_profit'] = $dspFee;
                $result['status'] = 'cancelled_by_buyer_after_dsp';

            } else {
                // BUG-001 FIX: Buyer cancels BEFORE DSP assigned
                // - Full refund (item + delivery) to buyer
                // - NO fees charged
                // - NO profit recorded

                $buyerRefund = $peacelink->item_price + $peacelink->delivery_fee;

                // Full refund to buyer
                $this->creditWallet($peacelink->buyer_wallet, $buyerRefund,
                    'peacelink_refund', "Full refund for PeaceLink #{$peacelink->id}");

                $result['buyer_refund'] = $buyerRefund;
                $result['dsp_payout'] = 0;
                $result['platform_profit'] = 0;
                $result['status'] = 'cancelled_by_buyer_before_dsp';

                Log::info("PeaceLink #{$peacelink->id}: No DSP assigned. Full refund issued. No profit taken.");
            }

            // Update PeaceLink status
            $peacelink->update([
                'status' => $result['status'],
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by' => 'buyer'
            ]);

            return $result;
        });
    }

    /**
     * Process merchant cancellation
     * 
     * Scenario 5 FIX: Merchant CAN cancel after DSP assigned
     */
    public function processMerchantCancellation(PeaceLink $peacelink, string $reason = null): array
    {
        return DB::transaction(function () use ($peacelink, $reason) {
            $result = [
                'buyer_refund' => 0,
                'dsp_payout' => 0,
                'merchant_deduction' => 0,
                'platform_profit' => 0,
                'status' => 'cancelled_by_merchant'
            ];

            $dspAssigned = !empty($peacelink->dsp_wallet) && 
                           in_array($peacelink->status, ['dsp_assigned', 'in_transit']);

            // Full refund to buyer in all merchant cancellation scenarios
            $buyerRefund = $peacelink->item_price + $peacelink->delivery_fee;
            $this->creditWallet($peacelink->buyer_wallet, $buyerRefund,
                'peacelink_refund', "Refund for PeaceLink #{$peacelink->id} (merchant cancelled)");

            $result['buyer_refund'] = $buyerRefund;

            if ($dspAssigned) {
                // Merchant cancels AFTER DSP assigned
                // - DSP still gets paid (from merchant wallet!)
                // - Merchant pays DSP fee from their wallet

                $dspPayout = $peacelink->delivery_fee * (1 - self::DSP_FEE_PERCENTAGE);
                $dspFee = $peacelink->delivery_fee * self::DSP_FEE_PERCENTAGE;

                // Deduct from merchant wallet and pay DSP
                $this->debitWallet($peacelink->merchant_wallet, $peacelink->delivery_fee,
                    'dsp_fee_payment', "DSP fee for cancelled PeaceLink #{$peacelink->id}");
                
                $this->creditWallet($peacelink->dsp_wallet, $dspPayout,
                    'dsp_payout', "Delivery fee for PeaceLink #{$peacelink->id}");

                // Platform earns DSP fee
                $this->creditPlatformProfit($dspFee, $peacelink->id, 'dsp_fee');

                $result['dsp_payout'] = $dspPayout;
                $result['merchant_deduction'] = $peacelink->delivery_fee;
                $result['platform_profit'] = $dspFee;
                $result['status'] = 'cancelled_by_merchant_after_dsp';
            } else {
                // Merchant cancels BEFORE DSP assigned - no DSP fees
                $result['status'] = 'cancelled_by_merchant_before_dsp';
            }

            // Handle advance payment refund if applicable
            if ($peacelink->advance_payment_amount > 0 && $peacelink->advance_paid_at) {
                // Advance was already paid to merchant, they keep it
                // But if merchant cancels, they should refund it
                $this->debitWallet($peacelink->merchant_wallet, $peacelink->advance_payment_amount,
                    'advance_refund', "Advance payment refund for PeaceLink #{$peacelink->id}");
                
                $this->creditWallet($peacelink->buyer_wallet, $peacelink->advance_payment_amount,
                    'advance_refund', "Advance payment refund for PeaceLink #{$peacelink->id}");
            }

            // Update PeaceLink status
            $peacelink->update([
                'status' => $result['status'],
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_by' => 'merchant'
            ]);

            return $result;
        });
    }

    /**
     * Check if user can cancel PeaceLink
     */
    public function canCancel(PeaceLink $peacelink, string $role, string $userId): bool
    {
        // Cannot cancel after OTP used
        if ($peacelink->otp_used_at) {
            return false;
        }

        $allowedStatuses = ['created', 'approved', 'dsp_assigned', 'in_transit'];

        if ($role === 'buyer') {
            // Buyer can cancel before delivery
            return $peacelink->buyer_id === $userId && 
                   in_array($peacelink->status, $allowedStatuses);
        }

        if ($role === 'merchant') {
            // FIX: Merchant CAN cancel after DSP assigned (before OTP)
            return $peacelink->merchant_id === $userId && 
                   in_array($peacelink->status, $allowedStatuses);
        }

        return false;
    }

    private function creditWallet(string $walletNumber, float $amount, string $type, string $description): void
    {
        $wallet = Wallet::where('number', $walletNumber)->firstOrFail();
        $wallet->increment('balance', $amount);
        
        Transaction::create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'status' => 'completed'
        ]);
    }

    private function debitWallet(string $walletNumber, float $amount, string $type, string $description): void
    {
        $wallet = Wallet::where('number', $walletNumber)->firstOrFail();
        
        if ($wallet->balance < $amount) {
            throw new \Exception("Insufficient balance in wallet {$walletNumber}");
        }
        
        $wallet->decrement('balance', $amount);
        
        Transaction::create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount' => -$amount,
            'description' => $description,
            'status' => 'completed'
        ]);
    }

    private function creditPlatformProfit(float $amount, string $peacelinkId, string $feeType): void
    {
        PlatformProfit::create([
            'peacelink_id' => $peacelinkId,
            'amount' => $amount,
            'fee_type' => $feeType,
            'created_at' => now()
        ]);

        // Also update the main platform wallet
        $platformWallet = Wallet::where('type', 'platform')->first();
        if ($platformWallet) {
            $platformWallet->increment('balance', $amount);
        }
    }
}
