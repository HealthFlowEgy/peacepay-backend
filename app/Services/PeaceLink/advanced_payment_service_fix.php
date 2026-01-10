<?php
/**
 * PeacePay Advanced Payment Service - Bug Fixes
 * 
 * Fixes:
 * - BUG-004: Double fixed fee in advanced payment flow
 */

namespace App\Services;

use App\Models\PeaceLink;
use App\Models\Wallet;
use App\Models\PlatformProfit;
use Illuminate\Support\Facades\DB;

class AdvancedPaymentService
{
    const MERCHANT_FEE_PERCENTAGE = 0.01; // 1%
    const MERCHANT_FIXED_FEE = 3; // 3 EGP - ONLY on final release!
    const DSP_FEE_PERCENTAGE = 0.005; // 0.5%

    /**
     * Process advance payment to merchant
     * 
     * BUG-004 FIX: NO fixed fee on advance - only percentage
     */
    public function processAdvancePayment(PeaceLink $peacelink): array
    {
        return DB::transaction(function () use ($peacelink) {
            $advanceAmount = $peacelink->item_price * ($peacelink->advance_payment_percentage / 100);
            
            // BUG-004 FIX: Only percentage fee, NO fixed fee on advance
            $advanceFee = $advanceAmount * self::MERCHANT_FEE_PERCENTAGE;
            $merchantAdvancePayout = $advanceAmount - $advanceFee;

            // Debit from escrow (already held from buyer)
            // Credit to merchant
            $this->creditWallet($peacelink->merchant_wallet, $merchantAdvancePayout,
                'advance_payout', "Advance payment for PeaceLink #{$peacelink->id}");

            // Record platform profit - ONLY percentage, not fixed fee
            $this->creditPlatformProfit($advanceFee, $peacelink->id, 'advance_fee');

            // Update PeaceLink
            $peacelink->update([
                'advance_paid_at' => now(),
                'advance_amount_paid' => $merchantAdvancePayout,
                'advance_fee_charged' => $advanceFee
            ]);

            return [
                'advance_amount' => $advanceAmount,
                'fee_charged' => $advanceFee,
                'merchant_received' => $merchantAdvancePayout,
                'fixed_fee_applied' => false // BUG-004 FIX
            ];
        });
    }

    /**
     * Process final release after OTP confirmation
     * 
     * BUG-004 FIX: Fixed fee ONLY on final release
     */
    public function processFinalRelease(PeaceLink $peacelink): array
    {
        return DB::transaction(function () use ($peacelink) {
            $result = [
                'merchant_payout' => 0,
                'dsp_payout' => 0,
                'platform_profit' => 0,
                'breakdown' => []
            ];

            // Calculate remaining amount
            $advancePercentage = $peacelink->advance_payment_percentage ?? 0;
            $remainingPercentage = 100 - $advancePercentage;
            $remainingItemAmount = $peacelink->item_price * ($remainingPercentage / 100);

            // BUG-004 FIX: Fixed fee applied ONLY HERE on final release
            $remainingFee = ($remainingItemAmount * self::MERCHANT_FEE_PERCENTAGE) + self::MERCHANT_FIXED_FEE;
            $merchantFinalPayout = $remainingItemAmount - $remainingFee;

            // Pay merchant remaining amount
            $this->creditWallet($peacelink->merchant_wallet, $merchantFinalPayout,
                'final_payout', "Final payment for PeaceLink #{$peacelink->id}");

            $result['merchant_payout'] = $merchantFinalPayout;
            $result['breakdown']['merchant_fee'] = $remainingFee;
            $result['breakdown']['fixed_fee_applied'] = true;

            // Pay DSP
            $dspPayout = $peacelink->delivery_fee * (1 - self::DSP_FEE_PERCENTAGE);
            $dspFee = $peacelink->delivery_fee * self::DSP_FEE_PERCENTAGE;

            $this->creditWallet($peacelink->dsp_wallet, $dspPayout,
                'dsp_payout', "Delivery fee for PeaceLink #{$peacelink->id}");

            $result['dsp_payout'] = $dspPayout;
            $result['breakdown']['dsp_fee'] = $dspFee;

            // Record platform profit
            $totalPlatformProfit = $remainingFee + $dspFee;
            $this->creditPlatformProfit($remainingFee, $peacelink->id, 'final_merchant_fee');
            $this->creditPlatformProfit($dspFee, $peacelink->id, 'dsp_fee');

            $result['platform_profit'] = $totalPlatformProfit;

            // Update PeaceLink
            $peacelink->update([
                'status' => 'completed',
                'completed_at' => now(),
                'final_amount_paid' => $merchantFinalPayout,
                'final_fee_charged' => $remainingFee
            ]);

            return $result;
        });
    }

    /**
     * Calculate fee preview for advanced payment
     * 
     * Returns breakdown showing that fixed fee is only on final
     */
    public function calculateFeePreview(float $itemPrice, float $deliveryFee, int $advancePercentage): array
    {
        $advanceAmount = $itemPrice * ($advancePercentage / 100);
        $remainingAmount = $itemPrice - $advanceAmount;

        // Advance: percentage only
        $advanceFee = $advanceAmount * self::MERCHANT_FEE_PERCENTAGE;
        $advanceNet = $advanceAmount - $advanceFee;

        // Final: percentage + fixed
        $finalFee = ($remainingAmount * self::MERCHANT_FEE_PERCENTAGE) + self::MERCHANT_FIXED_FEE;
        $finalNet = $remainingAmount - $finalFee;

        // DSP fee
        $dspFee = $deliveryFee * self::DSP_FEE_PERCENTAGE;
        $dspNet = $deliveryFee - $dspFee;

        // Total fees
        $totalFees = $advanceFee + $finalFee + $dspFee;
        $totalMerchantReceives = $advanceNet + $finalNet;

        return [
            'item_price' => $itemPrice,
            'delivery_fee' => $deliveryFee,
            'advance' => [
                'percentage' => $advancePercentage,
                'amount' => $advanceAmount,
                'fee' => $advanceFee,
                'net' => $advanceNet,
                'fixed_fee_included' => false
            ],
            'final' => [
                'percentage' => 100 - $advancePercentage,
                'amount' => $remainingAmount,
                'fee' => $finalFee,
                'net' => $finalNet,
                'fixed_fee_included' => true
            ],
            'dsp' => [
                'fee' => $dspFee,
                'net' => $dspNet
            ],
            'summary' => [
                'total_fees' => $totalFees,
                'merchant_receives' => $totalMerchantReceives,
                'dsp_receives' => $dspNet,
                'buyer_pays' => $itemPrice + $deliveryFee
            ]
        ];
    }

    private function creditWallet(string $walletNumber, float $amount, string $type, string $description): void
    {
        $wallet = Wallet::where('number', $walletNumber)->firstOrFail();
        $wallet->increment('balance', $amount);
    }

    private function creditPlatformProfit(float $amount, string $peacelinkId, string $feeType): void
    {
        PlatformProfit::create([
            'peacelink_id' => $peacelinkId,
            'amount' => $amount,
            'fee_type' => $feeType
        ]);
    }
}
