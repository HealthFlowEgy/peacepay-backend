<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\Services;

use App\Modules\PeaceLink\Models\FeeConfiguration;
use App\Modules\PeaceLink\Enums\FeeType;

/**
 * Fee Calculator Service
 * 
 * Calculates all platform fees according to business rules:
 * 
 * MERCHANT FEES:
 * - Percentage: 0.5% of item amount
 * - Fixed: 2 EGP (charged ONCE on FINAL release only, NOT on advance)
 * 
 * DSP FEES:
 * - Percentage: 0.5% of delivery fee
 * 
 * ADVANCE PAYMENT FEES:
 * - Percentage: 0.5% only (NO fixed fee)
 * 
 * CASHOUT FEES:
 * - Percentage: 1.5% (deducted at REQUEST time, not approval)
 * 
 * CRITICAL: Fees are FROZEN at transaction creation time
 */
class FeeCalculatorService
{
    /**
     * Get current fee configuration snapshot
     */
    public function getCurrentFeeSnapshot(): array
    {
        $fees = FeeConfiguration::where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>', now());
            })
            ->get()
            ->keyBy('fee_type');

        return [
            'merchant_percentage' => $fees[FeeType::MERCHANT_PERCENTAGE->value]?->rate ?? 0.005,
            'merchant_fixed' => $fees[FeeType::MERCHANT_FIXED->value]?->fixed_amount ?? 2.00,
            'dsp_percentage' => $fees[FeeType::DSP_PERCENTAGE->value]?->rate ?? 0.005,
            'advance_percentage' => $fees[FeeType::ADVANCE_PERCENTAGE->value]?->rate ?? 0.005,
            'cashout_percentage' => $fees[FeeType::CASHOUT_PERCENTAGE->value]?->rate ?? 0.015,
            'frozen_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Calculate merchant fee
     * 
     * @param float $amount Item amount
     * @param array $feeSnapshot Frozen fee configuration
     * @param bool $includeFixedFee Whether to include 2 EGP fixed fee (only on final release)
     */
    public function calculateMerchantFee(
        float $amount, 
        array $feeSnapshot, 
        bool $includeFixedFee = false
    ): float {
        $percentageFee = $amount * ($feeSnapshot['merchant_percentage'] ?? 0.005);
        $fixedFee = $includeFixedFee ? ($feeSnapshot['merchant_fixed'] ?? 2.00) : 0;
        
        return round($percentageFee + $fixedFee, 2);
    }

    /**
     * Calculate advance payment fee
     * IMPORTANT: No fixed fee on advance, only percentage
     */
    public function calculateAdvanceFee(float $amount, array $feeSnapshot): float
    {
        $percentageFee = $amount * ($feeSnapshot['advance_percentage'] ?? 0.005);
        
        return round($percentageFee, 2);
    }

    /**
     * Calculate DSP fee
     */
    public function calculateDspFee(float $deliveryFee, array $feeSnapshot): float
    {
        $fee = $deliveryFee * ($feeSnapshot['dsp_percentage'] ?? 0.005);
        
        return round($fee, 2);
    }

    /**
     * Calculate cashout fee
     * IMPORTANT: Must be deducted at request time, not approval
     */
    public function calculateCashoutFee(float $amount, ?array $feeSnapshot = null): float
    {
        $rate = $feeSnapshot['cashout_percentage'] ?? 0.015;
        $fee = $amount * $rate;
        
        return round($fee, 2);
    }

    /**
     * Calculate total platform profit for a PeaceLink
     */
    public function calculateTotalPlatformProfit(
        float $itemAmount,
        float $deliveryFee,
        float $advanceAmount,
        array $feeSnapshot
    ): array {
        $advanceFee = $advanceAmount > 0 
            ? $this->calculateAdvanceFee($advanceAmount, $feeSnapshot) 
            : 0;
        
        $remainingItemAmount = $itemAmount - $advanceAmount;
        $merchantFinalFee = $remainingItemAmount > 0
            ? $this->calculateMerchantFee($remainingItemAmount, $feeSnapshot, true)
            : 0;
        
        $dspFee = $this->calculateDspFee($deliveryFee, $feeSnapshot);

        return [
            'advance_fee' => $advanceFee,
            'merchant_final_fee' => $merchantFinalFee,
            'dsp_fee' => $dspFee,
            'total_profit' => round($advanceFee + $merchantFinalFee + $dspFee, 2),
        ];
    }

    /**
     * Preview fee breakdown for UI
     */
    public function previewFeeBreakdown(
        float $itemAmount,
        float $deliveryFee,
        float $advancePercentage = 0,
        ?array $feeSnapshot = null
    ): array {
        $feeSnapshot = $feeSnapshot ?? $this->getCurrentFeeSnapshot();
        $advanceAmount = $itemAmount * ($advancePercentage / 100);
        
        $breakdown = $this->calculateTotalPlatformProfit(
            $itemAmount,
            $deliveryFee,
            $advanceAmount,
            $feeSnapshot
        );

        $merchantNet = $itemAmount - $breakdown['advance_fee'] - $breakdown['merchant_final_fee'];
        $dspNet = $deliveryFee - $breakdown['dsp_fee'];

        return [
            'item_amount' => $itemAmount,
            'delivery_fee' => $deliveryFee,
            'advance_amount' => $advanceAmount,
            'advance_fee' => $breakdown['advance_fee'],
            'merchant_final_fee' => $breakdown['merchant_final_fee'],
            'dsp_fee' => $breakdown['dsp_fee'],
            'total_platform_profit' => $breakdown['total_profit'],
            'merchant_net_payout' => round($merchantNet, 2),
            'dsp_net_payout' => round($dspNet, 2),
            'buyer_total' => $itemAmount + $deliveryFee,
        ];
    }
}


// app/Modules/Wallet/Services/CashoutService.php

namespace App\Modules\Wallet\Services;

use App\Modules\Wallet\Models\Wallet;
use App\Modules\Wallet\Models\CashoutRequest;
use App\Modules\Wallet\Models\CashoutMethod;
use App\Modules\Wallet\Enums\CashoutStatus;
use App\Modules\Wallet\Events\CashoutRequested;
use App\Modules\Wallet\Events\CashoutApproved;
use App\Modules\Wallet\Events\CashoutRejected;
use App\Modules\PeaceLink\Services\FeeCalculatorService;
use Illuminate\Support\Facades\DB;

/**
 * Cashout Service
 * 
 * CRITICAL FIX: Fee is deducted at REQUEST time, not approval time
 * If admin rejects, fee must be REFUNDED to user wallet
 */
class CashoutService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly FeeCalculatorService $feeCalculator,
    ) {}

    /**
     * Request cashout
     * 
     * CRITICAL: Fee is deducted IMMEDIATELY at request time
     */
    public function requestCashout(
        string $userId,
        float $amount,
        string $cashoutMethodId
    ): CashoutRequest {
        $wallet = $this->walletService->getWalletByUserId($userId);
        $method = CashoutMethod::findOrFail($cashoutMethodId);

        // Calculate fee
        $fee = $this->feeCalculator->calculateCashoutFee($amount);
        $totalDeduction = $amount + $fee;

        // Validate balance (amount + fee)
        if ($wallet->available_balance < $totalDeduction) {
            throw new \DomainException(
                "Insufficient balance. Required: {$totalDeduction} EGP (including {$fee} EGP fee)"
            );
        }

        return DB::transaction(function () use ($wallet, $amount, $fee, $method, $userId) {
            // Deduct amount + fee IMMEDIATELY
            $this->walletService->debit(
                $wallet,
                $amount + $fee,
                'cashout_request',
                null,
                "Cashout request: {$amount} EGP + {$fee} EGP fee"
            );

            // Create request
            $request = CashoutRequest::create([
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'cashout_method_id' => $method->id,
                'requested_amount' => $amount,
                'fee_amount' => $fee,
                'net_amount' => $amount, // Amount user receives
                'status' => CashoutStatus::PENDING,
            ]);

            event(new CashoutRequested($request));

            return $request;
        });
    }

    /**
     * Admin approves cashout
     */
    public function approve(
        CashoutRequest $request,
        string $adminId,
        ?string $externalReference = null
    ): CashoutRequest {
        if ($request->status !== CashoutStatus::PENDING) {
            throw new \DomainException('Cashout request is not pending');
        }

        return DB::transaction(function () use ($request, $adminId, $externalReference) {
            $request->update([
                'status' => CashoutStatus::APPROVED,
                'processed_by' => $adminId,
                'processed_at' => now(),
                'external_reference' => $externalReference,
            ]);

            // Fee already deducted at request time
            // No additional wallet operations needed

            event(new CashoutApproved($request));

            return $request->fresh();
        });
    }

    /**
     * Admin rejects cashout
     * 
     * CRITICAL: Fee must be REFUNDED on rejection
     */
    public function reject(
        CashoutRequest $request,
        string $adminId,
        string $reason
    ): CashoutRequest {
        if ($request->status !== CashoutStatus::PENDING) {
            throw new \DomainException('Cashout request is not pending');
        }

        return DB::transaction(function () use ($request, $adminId, $reason) {
            $wallet = $request->wallet;
            
            // Refund FULL amount (requested amount + fee)
            $refundAmount = $request->requested_amount + $request->fee_amount;
            
            $this->walletService->credit(
                $wallet,
                $refundAmount,
                'cashout_refund',
                $request->id,
                "Cashout rejected: {$request->requested_amount} EGP + {$request->fee_amount} EGP fee refunded"
            );

            $request->update([
                'status' => CashoutStatus::REJECTED,
                'processed_by' => $adminId,
                'processed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            event(new CashoutRejected($request));

            return $request->fresh();
        });
    }

    /**
     * Get pending cashout requests for admin
     */
    public function getPendingRequests(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return CashoutRequest::with(['user', 'wallet', 'cashoutMethod'])
            ->where('status', CashoutStatus::PENDING)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Calculate cashout preview for UI
     */
    public function preview(float $amount): array
    {
        $fee = $this->feeCalculator->calculateCashoutFee($amount);
        
        return [
            'requested_amount' => $amount,
            'fee_amount' => $fee,
            'fee_percentage' => '1.5%',
            'net_amount' => $amount,
            'total_deduction' => $amount + $fee,
        ];
    }
}
