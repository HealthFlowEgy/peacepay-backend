<?php
/**
 * PeacePay Cash-out Service - Bug Fixes
 * 
 * Fixes:
 * - BUG-005: Cash-out fee not deducted at request time
 */

namespace App\Services;

use App\Models\Cashout;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\PlatformProfit;
use Illuminate\Support\Facades\DB;

class CashoutService
{
    const CASHOUT_FEE_PERCENTAGE = 0.015; // 1.5%
    const MIN_CASHOUT = 10; // 10 EGP

    /**
     * Request cash-out
     * 
     * BUG-005 FIX: Deduct amount + fee at request time
     */
    public function requestCashout(
        string $userId, 
        float $amount, 
        string $method,
        array $bankDetails = []
    ): Cashout {
        return DB::transaction(function () use ($userId, $amount, $method, $bankDetails) {
            // Validate minimum
            if ($amount < self::MIN_CASHOUT) {
                throw new \Exception("Minimum cash-out is " . self::MIN_CASHOUT . " EGP");
            }

            $wallet = Wallet::where('user_id', $userId)->firstOrFail();
            
            // Calculate fee
            $fee = $amount * self::CASHOUT_FEE_PERCENTAGE;
            $totalDeduction = $amount + $fee;

            // BUG-005 FIX: Check wallet has enough for amount + fee
            if ($wallet->balance < $totalDeduction) {
                throw new \Exception(
                    "Insufficient balance. Need {$totalDeduction} EGP (amount + fee), have {$wallet->balance} EGP"
                );
            }

            // BUG-005 FIX: Deduct FULL amount (amount + fee) NOW at request time
            $wallet->decrement('balance', $totalDeduction);

            // Create transaction record
            Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'cashout_hold',
                'amount' => -$totalDeduction,
                'fee' => $fee,
                'description' => "Cash-out request - {$method}",
                'status' => 'pending'
            ]);

            // Create cashout request
            $cashout = Cashout::create([
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'fee' => $fee,
                'total_deducted' => $totalDeduction, // BUG-005 FIX: Track total
                'method' => $method,
                'bank_details' => json_encode($bankDetails),
                'status' => 'pending',
                'requested_at' => now()
            ]);

            return $cashout;
        });
    }

    /**
     * Approve cash-out (Admin)
     * 
     * No additional deduction needed - already deducted at request
     */
    public function approveCashout(string $cashoutId, string $adminId): Cashout
    {
        return DB::transaction(function () use ($cashoutId, $adminId) {
            $cashout = Cashout::findOrFail($cashoutId);
            
            if ($cashout->status !== 'pending') {
                throw new \Exception("Cashout is not pending");
            }

            // Amount was already deducted at request time
            // Now just record platform profit from the fee
            $this->creditPlatformProfit($cashout->fee, $cashout->id, 'cashout_fee');

            // Update transaction status
            Transaction::where('wallet_id', $cashout->wallet_id)
                ->where('type', 'cashout_hold')
                ->where('status', 'pending')
                ->latest()
                ->first()
                ?->update(['status' => 'completed', 'type' => 'cashout']);

            // Update cashout status
            $cashout->update([
                'status' => 'approved',
                'approved_by' => $adminId,
                'approved_at' => now()
            ]);

            return $cashout;
        });
    }

    /**
     * Reject cash-out (Admin)
     * 
     * BUG-005 FIX: Refund full amount INCLUDING fee
     */
    public function rejectCashout(string $cashoutId, string $adminId, string $reason): Cashout
    {
        return DB::transaction(function () use ($cashoutId, $adminId, $reason) {
            $cashout = Cashout::findOrFail($cashoutId);
            
            if ($cashout->status !== 'pending') {
                throw new \Exception("Cashout is not pending");
            }

            $wallet = Wallet::findOrFail($cashout->wallet_id);

            // BUG-005 FIX: Refund FULL amount including fee
            $refundAmount = $cashout->total_deducted; // amount + fee
            $wallet->increment('balance', $refundAmount);

            // Create refund transaction
            Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'cashout_refund',
                'amount' => $refundAmount,
                'description' => "Cash-out rejected - {$reason}",
                'status' => 'completed'
            ]);

            // DO NOT credit platform profit on rejection
            // Fee was never earned since cashout didn't complete

            // Update original transaction
            Transaction::where('wallet_id', $cashout->wallet_id)
                ->where('type', 'cashout_hold')
                ->where('status', 'pending')
                ->latest()
                ->first()
                ?->update(['status' => 'cancelled']);

            // Update cashout status
            $cashout->update([
                'status' => 'rejected',
                'rejected_by' => $adminId,
                'rejected_at' => now(),
                'rejection_reason' => $reason
            ]);

            return $cashout;
        });
    }

    /**
     * Get cashout fee preview
     */
    public function calculateFee(float $amount): array
    {
        $fee = $amount * self::CASHOUT_FEE_PERCENTAGE;
        $totalDeduction = $amount + $fee;
        
        return [
            'amount' => $amount,
            'fee' => round($fee, 2),
            'fee_percentage' => self::CASHOUT_FEE_PERCENTAGE * 100,
            'total_deduction' => round($totalDeduction, 2),
            'user_receives' => $amount
        ];
    }

    private function creditPlatformProfit(float $amount, string $cashoutId, string $feeType): void
    {
        PlatformProfit::create([
            'cashout_id' => $cashoutId,
            'amount' => $amount,
            'fee_type' => $feeType
        ]);
    }
}
