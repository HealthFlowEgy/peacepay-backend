<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\Services;

/**
 * Fee Calculator Service
 * 
 * CRITICAL FEE RULES:
 * 
 * MERCHANT FEE:
 * - Rate: 0.5% of item amount
 * - Fixed: 2 EGP (charged ONLY on FINAL release, NOT on advance)
 * - Split payout: Advance = 0.5% only, Final = 0.5% + 2 EGP
 * 
 * DSP FEE:
 * - Rate: 0.5% of delivery fee
 * - No fixed fee
 * - ALWAYS paid if DSP assigned, even on cancellation
 * 
 * CASHOUT FEE:
 * - Rate: 1.5% of withdrawal amount
 * - MUST be deducted at REQUEST time, NOT on approval
 * - If admin rejects, fee is REFUNDED
 * 
 * ADVANCE FEE:
 * - Rate: 0.5% of advance amount
 * - NO fixed fee on advance
 */
class FeeCalculatorService
{
    /**
     * Get current fee configuration snapshot
     */
    public function getCurrentFeeSnapshot(): array
    {
        return [
            'merchant_percentage' => config('peacelink.fees.merchant_percentage', 0.005),
            'merchant_fixed' => config('peacelink.fees.merchant_fixed', 2.00),
            'dsp_percentage' => config('peacelink.fees.dsp_percentage', 0.005),
            'cashout_percentage' => config('peacelink.fees.cashout_percentage', 0.015),
            'advance_percentage' => config('peacelink.fees.advance_percentage', 0.005),
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Calculate merchant fee
     * 
     * @param float $amount Item amount
     * @param array $feeSnapshot Frozen fee config
     * @param bool $includeFixedFee True ONLY for final release
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
     * Calculate advance payment fee (NO fixed fee)
     */
    public function calculateAdvanceFee(float $amount, array $feeSnapshot): float
    {
        $rate = $feeSnapshot['advance_percentage'] ?? 0.005;
        return round($amount * $rate, 2);
    }

    /**
     * Calculate DSP fee
     */
    public function calculateDspFee(float $deliveryFee, array $feeSnapshot): float
    {
        $rate = $feeSnapshot['dsp_percentage'] ?? 0.005;
        return round($deliveryFee * $rate, 2);
    }

    /**
     * Calculate cashout fee
     */
    public function calculateCashoutFee(float $amount): float
    {
        $rate = config('peacelink.fees.cashout_percentage', 0.015);
        return round($amount * $rate, 2);
    }

    /**
     * Preview payout breakdown for a PeaceLink
     */
    public function previewPayoutBreakdown(
        float $itemAmount,
        float $deliveryFee,
        float $advancePercentage,
        array $feeSnapshot
    ): array {
        $advanceAmount = $advancePercentage > 0 
            ? round($itemAmount * $advancePercentage / 100, 2) 
            : 0;
        
        $remainingAmount = $itemAmount - $advanceAmount;

        // Advance payout
        $advanceFee = $advanceAmount > 0 
            ? $this->calculateAdvanceFee($advanceAmount, $feeSnapshot) 
            : 0;
        $advanceNet = $advanceAmount - $advanceFee;

        // Final payout (includes fixed fee)
        $finalFee = $remainingAmount > 0 
            ? $this->calculateMerchantFee($remainingAmount, $feeSnapshot, true) 
            : 0;
        $finalNet = $remainingAmount - $finalFee;

        // DSP payout
        $dspFee = $this->calculateDspFee($deliveryFee, $feeSnapshot);
        $dspNet = $deliveryFee - $dspFee;

        // Total platform profit
        $totalPlatformFee = $advanceFee + $finalFee + $dspFee;

        return [
            'buyer_pays' => $itemAmount + $deliveryFee,
            'merchant_receives' => [
                'advance' => [
                    'gross' => $advanceAmount,
                    'fee' => $advanceFee,
                    'net' => $advanceNet,
                ],
                'final' => [
                    'gross' => $remainingAmount,
                    'fee' => $finalFee,
                    'net' => $finalNet,
                ],
                'total_net' => $advanceNet + $finalNet,
            ],
            'dsp_receives' => [
                'gross' => $deliveryFee,
                'fee' => $dspFee,
                'net' => $dspNet,
            ],
            'platform_profit' => $totalPlatformFee,
        ];
    }
}


// =============================================================================
// app/Modules/Wallet/Services/CashoutService.php
// =============================================================================

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
 * CRITICAL BUG FIX: Fee deduction timing
 * 
 * OLD (BUGGY) BEHAVIOR:
 * - User requests 1000 EGP cashout
 * - 1000 EGP deducted from wallet
 * - On approval, 1.5% fee (15 EGP) deducted
 * - User receives 985 EGP
 * - PROBLEM: If rejected, user already lost nothing... but fee was never applied!
 * 
 * NEW (CORRECT) BEHAVIOR:
 * - User requests 1000 EGP cashout
 * - 1015 EGP deducted from wallet (amount + fee) at REQUEST time
 * - On approval, 1000 EGP transferred to user's bank
 * - Platform keeps 15 EGP
 * - If REJECTED: Full 1015 EGP refunded to wallet
 */
class CashoutService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly FeeCalculatorService $feeCalculator,
    ) {}

    /**
     * Request cashout - FEE DEDUCTED IMMEDIATELY
     */
    public function requestCashout(
        string $userId,
        string $cashoutMethodId,
        float $requestedAmount,
        string $pin
    ): CashoutRequest {
        // Validate PIN
        // ... PIN validation logic

        return DB::transaction(function () use ($userId, $cashoutMethodId, $requestedAmount) {
            $wallet = $this->walletService->getWalletByUserId($userId);
            $cashoutMethod = CashoutMethod::findOrFail($cashoutMethodId);

            // Calculate fee
            $feeAmount = $this->feeCalculator->calculateCashoutFee($requestedAmount);
            $totalDeduction = $requestedAmount + $feeAmount;

            // Check balance (must cover amount + fee)
            if ($wallet->available_balance < $totalDeduction) {
                throw new \DomainException(
                    "Insufficient balance. You need {$totalDeduction} EGP (including {$feeAmount} EGP fee)"
                );
            }

            // DEDUCT IMMEDIATELY (amount + fee)
            $transaction = $this->walletService->debit(
                $wallet,
                $totalDeduction,
                'cashout_request',
                null,
                "Cashout request: {$requestedAmount} EGP + {$feeAmount} EGP fee"
            );

            // Create cashout request
            $cashoutRequest = CashoutRequest::create([
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'cashout_method_id' => $cashoutMethodId,
                'requested_amount' => $requestedAmount,
                'fee_amount' => $feeAmount,
                'net_amount' => $requestedAmount, // Amount user will receive
                'total_deducted' => $totalDeduction,
                'status' => CashoutStatus::PENDING,
                'wallet_transaction_id' => $transaction->id,
            ]);

            event(new CashoutRequested($cashoutRequest));

            return $cashoutRequest;
        });
    }

    /**
     * Admin approves cashout
     */
    public function approveCashout(
        CashoutRequest $cashoutRequest,
        string $adminId,
        ?string $externalReference = null
    ): CashoutRequest {
        if ($cashoutRequest->status !== CashoutStatus::PENDING) {
            throw new \DomainException('Cashout request is not pending');
        }

        return DB::transaction(function () use ($cashoutRequest, $adminId, $externalReference) {
            // Fee already deducted at request time
            // Just mark as approved and process external transfer

            $cashoutRequest->update([
                'status' => CashoutStatus::APPROVED,
                'processed_by' => $adminId,
                'processed_at' => now(),
                'external_reference' => $externalReference,
            ]);

            // TODO: Trigger actual bank transfer via payment gateway
            // $this->paymentGateway->transfer(...);

            event(new CashoutApproved($cashoutRequest));

            return $cashoutRequest->fresh();
        });
    }

    /**
     * Admin rejects cashout - REFUND FEE
     */
    public function rejectCashout(
        CashoutRequest $cashoutRequest,
        string $adminId,
        string $rejectionReason
    ): CashoutRequest {
        if ($cashoutRequest->status !== CashoutStatus::PENDING) {
            throw new \DomainException('Cashout request is not pending');
        }

        return DB::transaction(function () use ($cashoutRequest, $adminId, $rejectionReason) {
            $wallet = $cashoutRequest->wallet;

            // REFUND FULL AMOUNT (including fee)
            $this->walletService->credit(
                $wallet,
                $cashoutRequest->total_deducted, // amount + fee
                'cashout_refund',
                $cashoutRequest->id,
                "Cashout rejected: {$rejectionReason}"
            );

            $cashoutRequest->update([
                'status' => CashoutStatus::REJECTED,
                'processed_by' => $adminId,
                'processed_at' => now(),
                'rejection_reason' => $rejectionReason,
            ]);

            event(new CashoutRejected($cashoutRequest));

            return $cashoutRequest->fresh();
        });
    }

    /**
     * Get cashout preview for UI
     */
    public function getCashoutPreview(float $amount): array
    {
        $fee = $this->feeCalculator->calculateCashoutFee($amount);
        
        return [
            'requested_amount' => $amount,
            'fee_amount' => $fee,
            'fee_percentage' => '1.5%',
            'total_deducted' => $amount + $fee,
            'you_receive' => $amount,
        ];
    }
}


// =============================================================================
// app/Modules/PeaceLink/Services/AdminResolutionService.php
// =============================================================================

namespace App\Modules\PeaceLink\Services;

use App\Modules\PeaceLink\Models\PeaceLink;
use App\Modules\PeaceLink\Models\PeaceLinkPayout;
use App\Modules\PeaceLink\Enums\PeaceLinkStatus;
use App\Modules\PeaceLink\Enums\PayoutType;
use App\Modules\Dispute\Models\Dispute;
use App\Modules\Dispute\Enums\DisputeStatus;
use App\Modules\Wallet\Services\WalletService;
use App\Modules\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * Admin Resolution Service
 * 
 * Handles admin dispute resolution with proper DSP payment handling.
 * 
 * BUG FIX: DSP must ALWAYS be paid if assigned, regardless of resolution.
 * BUG FIX: Merchant fee should NOT be charged if releasing to buyer.
 */
class AdminResolutionService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly FeeCalculatorService $feeCalculator,
        private readonly LedgerService $ledgerService,
    ) {}

    /**
     * Release funds to buyer (refund)
     * 
     * Rules:
     * - Buyer gets item amount refunded
     * - DSP gets paid (if assigned) - ALWAYS
     * - Merchant gets NOTHING, pays NO fee
     * - Platform keeps DSP fee only
     */
    public function releaseToBuyer(
        PeaceLink $peaceLink,
        Dispute $dispute,
        string $adminId,
        string $notes
    ): array {
        return DB::transaction(function () use ($peaceLink, $dispute, $adminId, $notes) {
            $buyerWallet = $this->walletService->getWalletByUserId($peaceLink->buyer_id);
            
            $buyerRefund = 0;
            $dspPayout = 0;
            $merchantPayout = 0;
            $platformFee = 0;

            // Calculate what buyer should get back
            // Item amount minus any advance already paid to merchant
            $advancePaid = $peaceLink->payouts()
                ->where('recipient_type', 'merchant')
                ->where('is_advance', true)
                ->sum('net_amount');
            
            // Buyer gets full item amount back
            // (Merchant must return advance if applicable)
            $buyerRefund = $peaceLink->item_amount;

            // If advance was paid, debit merchant
            if ($advancePaid > 0) {
                $merchantWallet = $this->walletService->getWalletByUserId($peaceLink->merchant_id);
                $this->walletService->debit(
                    $merchantWallet,
                    $advancePaid,
                    'dispute_resolution',
                    $peaceLink->id,
                    "Advance returned for dispute #{$dispute->id}"
                );
            }

            // Pay DSP if assigned (CRITICAL: DSP ALWAYS gets paid)
            if ($peaceLink->isDspAssigned()) {
                $dspFee = $this->feeCalculator->calculateDspFee(
                    $peaceLink->delivery_fee,
                    $peaceLink->fee_snapshot
                );
                $dspPayout = $peaceLink->delivery_fee - $dspFee;
                $platformFee = $dspFee;

                $this->payDsp($peaceLink, $dspPayout, $dspFee, "Dispute resolution");
            }

            // Refund buyer
            $this->walletService->releaseHoldAndCredit(
                $buyerWallet,
                $buyerRefund,
                'dispute_resolution',
                $peaceLink->id,
                "Refund from dispute #{$dispute->id}"
            );

            // Update PeaceLink
            $peaceLink->update(['status' => PeaceLinkStatus::RESOLVED]);

            // Update dispute
            $dispute->update([
                'status' => DisputeStatus::RESOLVED_BUYER,
                'resolved_by' => $adminId,
                'resolved_at' => now(),
                'resolution_type' => 'refund_buyer',
                'resolution_notes' => $notes,
                'buyer_amount' => $buyerRefund,
                'merchant_amount' => 0,
                'dsp_amount' => $dspPayout,
            ]);

            // Record in ledger
            $this->ledgerService->recordDisputeResolution($peaceLink, $dispute, [
                'buyer_refund' => $buyerRefund,
                'dsp_payout' => $dspPayout,
                'platform_fee' => $platformFee,
            ]);

            return [
                'buyer_receives' => $buyerRefund,
                'merchant_receives' => 0,
                'dsp_receives' => $dspPayout,
                'platform_fee' => $platformFee,
            ];
        });
    }

    /**
     * Release funds to merchant
     * 
     * Rules:
     * - Merchant gets item amount minus fees
     * - DSP gets paid (if assigned)
     * - Buyer gets nothing
     * - Platform keeps all fees
     */
    public function releaseToMerchant(
        PeaceLink $peaceLink,
        Dispute $dispute,
        string $adminId,
        string $notes
    ): array {
        return DB::transaction(function () use ($peaceLink, $dispute, $adminId, $notes) {
            $merchantWallet = $this->walletService->getWalletByUserId($peaceLink->merchant_id);
            
            $buyerRefund = 0;
            $dspPayout = 0;
            $merchantPayout = 0;
            $platformFee = 0;

            // Calculate merchant payout
            $advancePaid = $peaceLink->payouts()
                ->where('recipient_type', 'merchant')
                ->sum('net_amount');
            
            $remainingGross = $peaceLink->item_amount - $peaceLink->advance_amount;
            
            if ($remainingGross > 0) {
                $merchantFee = $this->feeCalculator->calculateMerchantFee(
                    $remainingGross,
                    $peaceLink->fee_snapshot,
                    true // Include fixed fee on final
                );
                $merchantNet = $remainingGross - $merchantFee;
                $merchantPayout = $merchantNet;
                $platformFee += $merchantFee;

                $this->walletService->credit(
                    $merchantWallet,
                    $merchantNet,
                    'dispute_resolution',
                    $peaceLink->id,
                    "Resolution payout for dispute #{$dispute->id}"
                );

                PeaceLinkPayout::create([
                    'peacelink_id' => $peaceLink->id,
                    'recipient_type' => 'merchant',
                    'recipient_id' => $peaceLink->merchant_id,
                    'wallet_id' => $merchantWallet->id,
                    'gross_amount' => $remainingGross,
                    'fee_amount' => $merchantFee,
                    'net_amount' => $merchantNet,
                    'payout_type' => PayoutType::FINAL,
                    'notes' => 'Dispute resolution',
                ]);
            }

            // Pay DSP if assigned
            if ($peaceLink->isDspAssigned()) {
                $dspFee = $this->feeCalculator->calculateDspFee(
                    $peaceLink->delivery_fee,
                    $peaceLink->fee_snapshot
                );
                $dspPayout = $peaceLink->delivery_fee - $dspFee;
                $platformFee += $dspFee;

                $this->payDsp($peaceLink, $dspPayout, $dspFee, "Dispute resolution");
            }

            // Update PeaceLink
            $peaceLink->update(['status' => PeaceLinkStatus::RESOLVED]);

            // Update dispute
            $dispute->update([
                'status' => DisputeStatus::RESOLVED_MERCHANT,
                'resolved_by' => $adminId,
                'resolved_at' => now(),
                'resolution_type' => 'release_merchant',
                'resolution_notes' => $notes,
                'buyer_amount' => 0,
                'merchant_amount' => $merchantPayout + $advancePaid,
                'dsp_amount' => $dspPayout,
            ]);

            // Release SPH
            $peaceLink->sphHold?->update([
                'status' => 'released',
                'released_at' => now(),
            ]);

            return [
                'buyer_receives' => 0,
                'merchant_receives' => $merchantPayout + $advancePaid,
                'dsp_receives' => $dspPayout,
                'platform_fee' => $platformFee,
            ];
        });
    }

    /**
     * Custom split resolution
     */
    public function resolveWithSplit(
        PeaceLink $peaceLink,
        Dispute $dispute,
        string $adminId,
        float $buyerPercentage,
        string $notes
    ): array {
        // Validate percentage
        if ($buyerPercentage < 0 || $buyerPercentage > 100) {
            throw new \DomainException('Invalid split percentage');
        }

        return DB::transaction(function () use ($peaceLink, $dispute, $adminId, $buyerPercentage, $notes) {
            $buyerWallet = $this->walletService->getWalletByUserId($peaceLink->buyer_id);
            $merchantWallet = $this->walletService->getWalletByUserId($peaceLink->merchant_id);
            
            $itemAmount = $peaceLink->item_amount;
            $buyerAmount = round($itemAmount * $buyerPercentage / 100, 2);
            $merchantAmount = $itemAmount - $buyerAmount;
            $platformFee = 0;
            $dspPayout = 0;

            // Refund buyer portion
            if ($buyerAmount > 0) {
                $this->walletService->releaseHoldAndCredit(
                    $buyerWallet,
                    $buyerAmount,
                    'dispute_resolution',
                    $peaceLink->id,
                    "Split resolution ({$buyerPercentage}%) for dispute #{$dispute->id}"
                );
            }

            // Pay merchant portion (with fees)
            if ($merchantAmount > 0) {
                $merchantFee = $this->feeCalculator->calculateMerchantFee(
                    $merchantAmount,
                    $peaceLink->fee_snapshot,
                    true
                );
                $merchantNet = $merchantAmount - $merchantFee;
                $platformFee += $merchantFee;

                $this->walletService->credit(
                    $merchantWallet,
                    $merchantNet,
                    'dispute_resolution',
                    $peaceLink->id,
                    "Split resolution for dispute #{$dispute->id}"
                );
            }

            // Pay DSP if assigned
            if ($peaceLink->isDspAssigned()) {
                $dspFee = $this->feeCalculator->calculateDspFee(
                    $peaceLink->delivery_fee,
                    $peaceLink->fee_snapshot
                );
                $dspPayout = $peaceLink->delivery_fee - $dspFee;
                $platformFee += $dspFee;

                $this->payDsp($peaceLink, $dspPayout, $dspFee, "Dispute resolution");
            }

            // Update dispute
            $dispute->update([
                'status' => DisputeStatus::RESOLVED_SPLIT,
                'resolved_by' => $adminId,
                'resolved_at' => now(),
                'resolution_type' => 'split',
                'resolution_notes' => $notes,
                'buyer_amount' => $buyerAmount,
                'merchant_amount' => $merchantAmount,
                'dsp_amount' => $dspPayout,
            ]);

            $peaceLink->update(['status' => PeaceLinkStatus::RESOLVED]);

            return [
                'buyer_receives' => $buyerAmount,
                'merchant_receives' => $merchantAmount - ($merchantAmount > 0 ? $platformFee - $dspFee : 0),
                'dsp_receives' => $dspPayout,
                'platform_fee' => $platformFee,
            ];
        });
    }

    /**
     * Helper to pay DSP
     */
    private function payDsp(PeaceLink $peaceLink, float $netAmount, float $fee, string $notes): void
    {
        $dspWallet = $this->walletService->getWalletByUserId($peaceLink->dsp_id);
        
        $this->walletService->credit(
            $dspWallet,
            $netAmount,
            'dispute_resolution',
            $peaceLink->id,
            $notes
        );

        PeaceLinkPayout::create([
            'peacelink_id' => $peaceLink->id,
            'recipient_type' => 'dsp',
            'recipient_id' => $peaceLink->dsp_id,
            'wallet_id' => $dspWallet->id,
            'gross_amount' => $peaceLink->delivery_fee,
            'fee_amount' => $fee,
            'net_amount' => $netAmount,
            'payout_type' => PayoutType::DELIVERY_FEE,
            'notes' => $notes,
        ]);

        $this->ledgerService->recordPlatformFee($peaceLink, $fee, 'dsp_fee_resolution');
    }
}
