<?php
/**
 * PeacePay Admin Release Service - Bug Fixes
 * 
 * Fixes:
 * - BUG-002: DSP not paid on admin release to merchant
 * - BUG-006: Incorrect merchant fee on release to buyer
 */

namespace App\Services\Admin;

use App\Models\PeaceLink;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\PlatformProfit;
use App\Models\AdminAuditLog;
use Illuminate\Support\Facades\DB;

class AdminReleaseService
{
    const MERCHANT_FEE_PERCENTAGE = 0.01;
    const MERCHANT_FIXED_FEE = 3;
    const DSP_FEE_PERCENTAGE = 0.005;

    /**
     * Release funds to merchant (dispute resolution)
     * 
     * BUG-002 FIX: Ensure DSP is paid when assigned
     */
    public function releaseToMerchant(PeaceLink $peacelink, string $adminId, string $notes = null): array
    {
        return DB::transaction(function () use ($peacelink, $adminId, $notes) {
            $result = [
                'merchant_payout' => 0,
                'dsp_payout' => 0,
                'platform_profit' => 0,
                'breakdown' => []
            ];

            $dspAssigned = !empty($peacelink->dsp_wallet);

            if ($dspAssigned) {
                // BUG-002 FIX: DSP MUST be paid even on admin release
                $dspPayout = $peacelink->delivery_fee * (1 - self::DSP_FEE_PERCENTAGE);
                $dspFee = $peacelink->delivery_fee * self::DSP_FEE_PERCENTAGE;

                // Pay DSP
                $this->creditWallet($peacelink->dsp_wallet, $dspPayout,
                    'dsp_payout', "Admin released - PeaceLink #{$peacelink->id}");

                // Platform earns DSP fee
                $this->creditPlatformProfit($dspFee, $peacelink->id, 'dsp_fee');

                $result['dsp_payout'] = $dspPayout;
                $result['platform_profit'] += $dspFee;
                $result['breakdown']['dsp_fee'] = $dspFee;
            }

            // Calculate merchant payout (item only, delivery fee went to DSP)
            $merchantFee = ($peacelink->item_price * self::MERCHANT_FEE_PERCENTAGE) + self::MERCHANT_FIXED_FEE;
            $merchantPayout = $peacelink->item_price - $merchantFee;

            // Pay merchant
            $this->creditWallet($peacelink->merchant_wallet, $merchantPayout,
                'merchant_payout', "Admin released - PeaceLink #{$peacelink->id}");

            // Platform earns merchant fee
            $this->creditPlatformProfit($merchantFee, $peacelink->id, 'merchant_fee');

            $result['merchant_payout'] = $merchantPayout;
            $result['platform_profit'] += $merchantFee;
            $result['breakdown']['merchant_fee'] = $merchantFee;

            // Update PeaceLink
            $peacelink->update([
                'status' => 'admin_released_to_merchant',
                'resolved_at' => now(),
                'resolved_by' => $adminId,
                'resolution_notes' => $notes
            ]);

            // Audit log
            AdminAuditLog::create([
                'admin_id' => $adminId,
                'action' => 'release_to_merchant',
                'peacelink_id' => $peacelink->id,
                'details' => json_encode($result),
                'created_at' => now()
            ]);

            return $result;
        });
    }

    /**
     * Release funds to buyer (dispute resolution)
     * 
     * BUG-006 FIX: Don't charge merchant fee when merchant gets nothing
     */
    public function releaseToBuyer(PeaceLink $peacelink, string $adminId, string $notes = null): array
    {
        return DB::transaction(function () use ($peacelink, $adminId, $notes) {
            $result = [
                'buyer_refund' => 0,
                'dsp_payout' => 0,
                'merchant_payout' => 0,
                'platform_profit' => 0,
                'breakdown' => []
            ];

            // Full refund to buyer
            $buyerRefund = $peacelink->item_price + $peacelink->delivery_fee;
            $this->creditWallet($peacelink->buyer_wallet, $buyerRefund,
                'admin_refund', "Admin refund - PeaceLink #{$peacelink->id}");

            $result['buyer_refund'] = $buyerRefund;

            // If DSP was assigned, they still get paid
            if (!empty($peacelink->dsp_wallet)) {
                $dspPayout = $peacelink->delivery_fee * (1 - self::DSP_FEE_PERCENTAGE);
                $dspFee = $peacelink->delivery_fee * self::DSP_FEE_PERCENTAGE;

                // DSP gets paid from escrow
                $this->creditWallet($peacelink->dsp_wallet, $dspPayout,
                    'dsp_payout', "Admin released - PeaceLink #{$peacelink->id}");

                // Platform earns DSP fee ONLY (not merchant fee!)
                $this->creditPlatformProfit($dspFee, $peacelink->id, 'dsp_fee');

                $result['dsp_payout'] = $dspPayout;
                $result['platform_profit'] = $dspFee;
                $result['breakdown']['dsp_fee'] = $dspFee;
            }

            // BUG-006 FIX: Merchant gets NOTHING, so NO merchant fee charged
            // DO NOT add: $this->creditPlatformProfit(merchantFee, ...)
            $result['breakdown']['merchant_fee'] = 0;

            // Update PeaceLink
            $peacelink->update([
                'status' => 'admin_refunded_to_buyer',
                'resolved_at' => now(),
                'resolved_by' => $adminId,
                'resolution_notes' => $notes
            ]);

            // Audit log
            AdminAuditLog::create([
                'admin_id' => $adminId,
                'action' => 'release_to_buyer',
                'peacelink_id' => $peacelink->id,
                'details' => json_encode($result),
                'created_at' => now()
            ]);

            return $result;
        });
    }

    /**
     * Partial refund to buyer (custom amount)
     */
    public function partialRefund(
        PeaceLink $peacelink, 
        string $adminId, 
        float $refundAmount,
        string $notes = null
    ): array {
        return DB::transaction(function () use ($peacelink, $adminId, $refundAmount, $notes) {
            // Validate refund amount
            $maxRefund = $peacelink->item_price + $peacelink->delivery_fee;
            if ($refundAmount > $maxRefund) {
                throw new \Exception("Refund amount cannot exceed {$maxRefund}");
            }

            $result = [
                'buyer_refund' => $refundAmount,
                'merchant_deduction' => $refundAmount,
                'dsp_payout' => 0,
                'platform_profit' => 0
            ];

            // Refund buyer
            $this->creditWallet($peacelink->buyer_wallet, $refundAmount,
                'admin_partial_refund', "Partial refund - PeaceLink #{$peacelink->id}");

            // Deduct from merchant (if they already received payout)
            if ($peacelink->merchant_paid_at) {
                $this->debitWallet($peacelink->merchant_wallet, $refundAmount,
                    'admin_deduction', "Partial refund deduction - PeaceLink #{$peacelink->id}");
            }

            // DSP and courier incentive remain unchanged
            // Platform profit unchanged

            $peacelink->update([
                'status' => 'admin_partial_refund',
                'resolved_at' => now(),
                'resolved_by' => $adminId,
                'resolution_notes' => $notes,
                'refund_amount' => $refundAmount
            ]);

            AdminAuditLog::create([
                'admin_id' => $adminId,
                'action' => 'partial_refund',
                'peacelink_id' => $peacelink->id,
                'details' => json_encode($result),
                'created_at' => now()
            ]);

            return $result;
        });
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
            'fee_type' => $feeType
        ]);
    }
}
