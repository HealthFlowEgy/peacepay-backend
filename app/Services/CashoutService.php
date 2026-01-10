<?php

namespace App\Services;

use App\Constants\EscrowConstants;
use App\Models\User;
use App\Models\UserWallet;
use App\Models\LedgerEntry;
use App\Models\FeeConfiguration;
use App\Models\PlatformWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

/**
 * Cashout Service
 * Handles cash-out request processing with proper fee handling
 * BUG FIX: Fee must be deducted at REQUEST time, not approval
 * Based on Re-Engineering Specification v2.0
 */
class CashoutService
{
    protected PeaceLinkService $peaceLinkService;

    public function __construct(PeaceLinkService $peaceLinkService)
    {
        $this->peaceLinkService = $peaceLinkService;
    }

    /**
     * Create a new cashout request
     * BUG FIX: Deduct fee immediately at request time
     */
    public function createRequest(User $user, UserWallet $wallet, float $amount): array
    {
        return DB::transaction(function () use ($user, $wallet, $amount) {
            // Calculate fee
            $feeCalc = $this->peaceLinkService->calculateCashoutFee($amount);
            
            // Total to deduct = requested amount + fee
            $totalDeduction = $amount + $feeCalc['fee'];

            // Check balance (must cover amount + fee)
            if ($wallet->balance < $totalDeduction) {
                throw new Exception('Insufficient balance. You need ' . $totalDeduction . ' ' . $wallet->currency->code . ' (including ' . $feeCalc['fee'] . ' fee)');
            }

            // BUG FIX: Deduct BOTH amount and fee immediately
            $wallet->balance -= $totalDeduction;
            $wallet->save();

            // Create ledger entry for the deduction
            LedgerEntry::create([
                'entry_id' => Str::uuid(),
                'debit_wallet_id' => $wallet->id,
                'amount' => $totalDeduction,
                'entry_type' => 'cashout_request',
                'description' => "Cash-out request: {$amount} + {$feeCalc['fee']} fee",
                'metadata' => [
                    'requested_amount' => $amount,
                    'fee_amount' => $feeCalc['fee'],
                    'net_amount' => $feeCalc['net_amount'],
                ],
            ]);

            // Update platform profit immediately with the fee
            $this->updatePlatformProfit($feeCalc['fee'], 'cashout_fee');

            return [
                'success' => true,
                'requested_amount' => $amount,
                'fee_amount' => $feeCalc['fee'],
                'net_amount' => $feeCalc['net_amount'],
                'total_deducted' => $totalDeduction,
                'fee_deducted_at_request' => true,
            ];
        });
    }

    /**
     * Approve a cashout request
     */
    public function approveRequest(int $requestId, User $admin): array
    {
        return DB::transaction(function () use ($requestId, $admin) {
            // Get the request from money_out_requests table (QRPay template)
            $request = DB::table('money_out_requests')
                ->where('id', $requestId)
                ->where('status', 1) // Pending
                ->first();

            if (!$request) {
                throw new Exception('Request not found or already processed');
            }

            // Update status to approved
            DB::table('money_out_requests')
                ->where('id', $requestId)
                ->update([
                    'status' => 2, // Approved
                    'updated_at' => now(),
                ]);

            // Create ledger entry
            LedgerEntry::create([
                'entry_id' => Str::uuid(),
                'amount' => $request->request_amount,
                'entry_type' => 'cashout_approved',
                'description' => "Cash-out approved by admin",
                'metadata' => [
                    'request_id' => $requestId,
                    'approved_by' => $admin->id,
                ],
            ]);

            return [
                'success' => true,
                'message' => 'Cash-out request approved',
            ];
        });
    }

    /**
     * Reject a cashout request
     * BUG FIX: Refund the fee to user wallet
     */
    public function rejectRequest(int $requestId, User $admin, string $reason): array
    {
        return DB::transaction(function () use ($requestId, $admin, $reason) {
            // Get the request
            $request = DB::table('money_out_requests')
                ->where('id', $requestId)
                ->where('status', 1) // Pending
                ->first();

            if (!$request) {
                throw new Exception('Request not found or already processed');
            }

            // Get user wallet
            $wallet = UserWallet::find($request->user_wallet_id);
            if (!$wallet) {
                throw new Exception('User wallet not found');
            }

            // Calculate total to refund (amount + fee)
            $feeAmount = $request->fee_amount ?? ($request->total_charge ?? 0);
            $totalRefund = $request->request_amount + $feeAmount;

            // BUG FIX: Refund both amount AND fee to user
            $wallet->balance += $totalRefund;
            $wallet->save();

            // Update request status
            DB::table('money_out_requests')
                ->where('id', $requestId)
                ->update([
                    'status' => 3, // Rejected
                    'rejection_reason' => $reason,
                    'fee_refunded' => true,
                    'fee_refunded_at' => now(),
                    'updated_at' => now(),
                ]);

            // Create ledger entry for refund
            LedgerEntry::create([
                'entry_id' => Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'amount' => $totalRefund,
                'entry_type' => 'cashout_refund',
                'description' => "Cash-out rejected - full refund including fee",
                'metadata' => [
                    'request_id' => $requestId,
                    'rejected_by' => $admin->id,
                    'reason' => $reason,
                    'amount_refunded' => $request->request_amount,
                    'fee_refunded' => $feeAmount,
                ],
            ]);

            // Deduct fee from platform profit (since we're refunding it)
            $this->deductPlatformProfit($feeAmount, 'cashout_fee_refund');

            return [
                'success' => true,
                'message' => 'Cash-out request rejected and funds refunded',
                'refunded_amount' => $request->request_amount,
                'refunded_fee' => $feeAmount,
                'total_refunded' => $totalRefund,
            ];
        });
    }

    /**
     * Update platform profit
     */
    protected function updatePlatformProfit(float $amount, string $type): void
    {
        $platformWallet = PlatformWallet::firstOrCreate(
            ['name' => 'peacepay_profit'],
            ['balance' => 0, 'currency' => 'EGP']
        );

        $platformWallet->balance += $amount;
        $platformWallet->save();

        LedgerEntry::create([
            'entry_id' => Str::uuid(),
            'platform_wallet_name' => 'peacepay_profit',
            'amount' => $amount,
            'entry_type' => 'platform_fee',
            'description' => "Platform fee ({$type}) - {$amount} EGP",
        ]);
    }

    /**
     * Deduct from platform profit (for refunds)
     */
    protected function deductPlatformProfit(float $amount, string $type): void
    {
        $platformWallet = PlatformWallet::where('name', 'peacepay_profit')->first();

        if ($platformWallet) {
            $platformWallet->balance -= $amount;
            $platformWallet->save();

            LedgerEntry::create([
                'entry_id' => Str::uuid(),
                'platform_wallet_name' => 'peacepay_profit',
                'amount' => -$amount,
                'entry_type' => 'platform_fee_refund',
                'description' => "Platform fee refund ({$type}) - {$amount} EGP",
            ]);
        }
    }
}
