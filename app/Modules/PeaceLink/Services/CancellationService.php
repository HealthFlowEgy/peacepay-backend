<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\Services;

use App\Modules\PeaceLink\Models\PeaceLink;
use App\Modules\PeaceLink\Models\PeaceLinkPayout;
use App\Modules\PeaceLink\Enums\PeaceLinkStatus;
use App\Modules\PeaceLink\Enums\CancellationParty;
use App\Modules\PeaceLink\Enums\PayoutType;
use App\Modules\PeaceLink\Events\PeaceLinkCanceled;
use App\Modules\PeaceLink\DTOs\CancellationResult;
use App\Modules\Wallet\Services\WalletService;
use App\Modules\Ledger\Services\LedgerService;
use Illuminate\Support\Facades\DB;

/**
 * Cancellation Service
 * 
 * Handles all cancellation scenarios based on PeaceLink business rules:
 * 
 * BUYER CANCELS:
 * - Before DSP: Full refund (item + delivery)
 * - After DSP: Item refund only, buyer pays DSP fee
 * - After OTP: NOT ALLOWED (dispute only)
 * 
 * MERCHANT CANCELS:
 * - Before DSP: Full refund
 * - After DSP: Full refund, MERCHANT pays DSP from their wallet
 * - After OTP: NOT ALLOWED
 * 
 * DSP CANCELS:
 * - Before OTP: Removed from order, PeaceLink returns to SPH_ACTIVE
 * - After OTP: NOT ALLOWED
 * 
 * CRITICAL RULES:
 * 1. DSP is ALWAYS paid if assigned (guaranteed)
 * 2. Advance payment is NOT refunded unless merchant fault
 * 3. Platform profit is KEPT on all cancellations
 * 4. Ledger invariant: buyer_debit = sum(all_credits)
 */
class CancellationService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly FeeCalculatorService $feeCalculator,
        private readonly LedgerService $ledgerService,
    ) {}

    /**
     * Process cancellation based on party and current state
     */
    public function cancel(
        PeaceLink $peaceLink,
        CancellationParty $canceledBy,
        string $reason,
        ?string $cancelingUserId = null
    ): CancellationResult {
        // Validate cancellation is allowed
        $this->validateCancellation($peaceLink, $canceledBy);

        return DB::transaction(function () use ($peaceLink, $canceledBy, $reason, $cancelingUserId) {
            $result = match ($canceledBy) {
                CancellationParty::BUYER => $this->processBuyerCancellation($peaceLink),
                CancellationParty::MERCHANT => $this->processMerchantCancellation($peaceLink),
                CancellationParty::DSP => $this->processDspCancellation($peaceLink),
                CancellationParty::ADMIN => throw new \DomainException('Use AdminResolutionService for admin actions'),
                CancellationParty::SYSTEM => $this->processSystemCancellation($peaceLink),
            };

            // Update PeaceLink status
            $peaceLink->update([
                'status' => PeaceLinkStatus::CANCELED,
                'canceled_at' => now(),
                'canceled_by' => $canceledBy->value,
                'cancellation_reason' => $reason,
            ]);

            // Release SPH hold
            if ($peaceLink->sphHold) {
                $peaceLink->sphHold->update([
                    'status' => 'refunded',
                    'refunded_at' => now(),
                ]);
            }

            // Record in ledger
            $this->ledgerService->recordCancellation($peaceLink, $result);

            event(new PeaceLinkCanceled($peaceLink, $result));

            return $result;
        });
    }

    /**
     * Validate cancellation is allowed
     */
    private function validateCancellation(PeaceLink $peaceLink, CancellationParty $canceledBy): void
    {
        // Cannot cancel after OTP used (delivered)
        if ($peaceLink->status === PeaceLinkStatus::DELIVERED) {
            throw new \DomainException('Cannot cancel: Order already delivered');
        }

        // Cannot cancel expired/canceled
        if (in_array($peaceLink->status, [PeaceLinkStatus::CANCELED, PeaceLinkStatus::EXPIRED])) {
            throw new \DomainException('Cannot cancel: Order already ' . $peaceLink->status->value);
        }

        // DSP can only cancel if assigned and OTP not used
        if ($canceledBy === CancellationParty::DSP && !$peaceLink->isDspAssigned()) {
            throw new \DomainException('DSP cancellation not applicable');
        }
    }

    /**
     * Process buyer-initiated cancellation
     * 
     * Rules:
     * - Before DSP: Full refund
     * - After DSP: Item only, buyer pays DSP fee
     * - Advance NOT refunded (buyer fault)
     */
    private function processBuyerCancellation(PeaceLink $peaceLink): CancellationResult
    {
        $buyerWallet = $this->walletService->getWalletByUserId($peaceLink->buyer_id);
        
        $refundToBuyer = 0;
        $dspPayout = 0;
        $merchantPayout = 0;
        $platformFee = 0;

        if (!$peaceLink->isDspAssigned()) {
            // BEFORE DSP: Full refund (item + delivery)
            // BUT: Advance is NOT refunded (already paid to merchant)
            $refundToBuyer = $peaceLink->total_amount - $peaceLink->advance_amount;
            
            // If advance was paid, that money stays with merchant
            // No additional merchant payout needed
            
        } else {
            // AFTER DSP: Buyer pays DSP fee
            // Refund only unpaid item amount (item - advance)
            $unpaidItemAmount = $peaceLink->item_amount - $peaceLink->advance_amount;
            $refundToBuyer = $unpaidItemAmount; // Delivery fee retained for DSP
            
            // Pay DSP
            $dspFee = $this->feeCalculator->calculateDspFee(
                $peaceLink->delivery_fee,
                $peaceLink->fee_snapshot
            );
            $dspPayout = $peaceLink->delivery_fee - $dspFee;
            $platformFee = $dspFee;
            
            $this->payDsp($peaceLink, $dspPayout, $dspFee);
        }

        // Refund buyer
        if ($refundToBuyer > 0) {
            $this->walletService->releaseHoldAndCredit(
                $buyerWallet,
                $refundToBuyer,
                'peacelink_refund',
                $peaceLink->id,
                "Refund for canceled PeaceLink #{$peaceLink->reference_number}"
            );
        }

        return new CancellationResult(
            refundToBuyer: $refundToBuyer,
            dspPayout: $dspPayout,
            merchantPayout: $merchantPayout,
            platformFee: $platformFee,
            message: $this->getCancellationMessage('buyer', $peaceLink->isDspAssigned())
        );
    }

    /**
     * Process merchant-initiated cancellation
     * 
     * Rules:
     * - Full refund to buyer (item + delivery)
     * - Advance is refunded (merchant fault)
     * - If DSP assigned: MERCHANT pays DSP from their wallet
     */
    private function processMerchantCancellation(PeaceLink $peaceLink): CancellationResult
    {
        $buyerWallet = $this->walletService->getWalletByUserId($peaceLink->buyer_id);
        $merchantWallet = $this->walletService->getWalletByUserId($peaceLink->merchant_id);
        
        $refundToBuyer = 0;
        $dspPayout = 0;
        $merchantDeduction = 0;
        $platformFee = 0;

        // Buyer gets full refund (merchant fault)
        $refundToBuyer = $peaceLink->total_amount;

        // If advance was paid, merchant needs to return it
        if ($peaceLink->advance_amount > 0) {
            // The advance was already paid to merchant, so:
            // - Buyer is refunded from SPH (which doesn't include advance anymore)
            // - Merchant must return the advance from their wallet
            
            // Actually, the SPH still holds the full amount
            // We just need to refund the full SPH to buyer
            // And the advance payout to merchant is already done, 
            // so we need to debit merchant to "return" it
            
            $advanceNetReceived = $peaceLink->payouts()
                ->where('recipient_type', 'merchant')
                ->where('is_advance', true)
                ->sum('net_amount');
            
            if ($advanceNetReceived > 0) {
                $this->walletService->debit(
                    $merchantWallet,
                    $advanceNetReceived,
                    'peacelink_advance_return',
                    $peaceLink->id,
                    "Return advance for canceled PeaceLink #{$peaceLink->reference_number}"
                );
                $merchantDeduction += $advanceNetReceived;
            }
        }

        // If DSP assigned: Merchant pays DSP
        if ($peaceLink->isDspAssigned()) {
            $dspFee = $this->feeCalculator->calculateDspFee(
                $peaceLink->delivery_fee,
                $peaceLink->fee_snapshot
            );
            $dspPayout = $peaceLink->delivery_fee - $dspFee;
            $platformFee = $dspFee;
            
            // Debit merchant for DSP payment
            $this->walletService->debit(
                $merchantWallet,
                $peaceLink->delivery_fee, // Full delivery fee debited
                'peacelink_dsp_payment',
                $peaceLink->id,
                "DSP payment for canceled PeaceLink #{$peaceLink->reference_number}"
            );
            $merchantDeduction += $peaceLink->delivery_fee;
            
            // Pay DSP
            $this->payDsp($peaceLink, $dspPayout, $dspFee);
        }

        // Refund buyer from SPH
        $this->walletService->releaseHoldAndCredit(
            $buyerWallet,
            $refundToBuyer,
            'peacelink_refund',
            $peaceLink->id,
            "Full refund for canceled PeaceLink #{$peaceLink->reference_number}"
        );

        return new CancellationResult(
            refundToBuyer: $refundToBuyer,
            dspPayout: $dspPayout,
            merchantPayout: -$merchantDeduction, // Negative = deduction
            platformFee: $platformFee,
            message: $this->getCancellationMessage('merchant', $peaceLink->isDspAssigned())
        );
    }

    /**
     * Process DSP-initiated cancellation
     * 
     * Rules:
     * - DSP is removed from order
     * - PeaceLink returns to SPH_ACTIVE for reassignment
     * - No payouts, no refunds
     */
    private function processDspCancellation(PeaceLink $peaceLink): CancellationResult
    {
        // DSP cancellation doesn't actually "cancel" the PeaceLink
        // It just removes DSP assignment and returns to SPH_ACTIVE
        
        $peaceLink->update([
            'dsp_id' => null,
            'dsp_wallet_number' => null,
            'assigned_driver_id' => null,
            'otp_hash' => null,
            'otp_generated_at' => null,
            'otp_expires_at' => null,
            'dsp_assigned_at' => null,
            'status' => PeaceLinkStatus::SPH_ACTIVE, // Return to awaiting DSP
        ]);

        // Don't fire canceled event - it's just a reassignment
        return new CancellationResult(
            refundToBuyer: 0,
            dspPayout: 0,
            merchantPayout: 0,
            platformFee: 0,
            message: 'DSP removed. Order awaiting new DSP assignment.'
        );
    }

    /**
     * Process system-initiated cancellation (expiry, timeout)
     */
    private function processSystemCancellation(PeaceLink $peaceLink): CancellationResult
    {
        // System cancellation = merchant fault (service not rendered)
        // Full refund to buyer
        
        if (!$peaceLink->buyer_id || !$peaceLink->sphHold) {
            // Never approved - no refund needed
            return new CancellationResult(
                refundToBuyer: 0,
                dspPayout: 0,
                merchantPayout: 0,
                platformFee: 0,
                message: 'Order expired without buyer approval.'
            );
        }

        // Treat as merchant fault for refund purposes
        return $this->processMerchantCancellation($peaceLink);
    }

    /**
     * Pay DSP their delivery fee
     */
    private function payDsp(PeaceLink $peaceLink, float $netAmount, float $fee): void
    {
        $dspWallet = $this->walletService->getWalletByUserId($peaceLink->dsp_id);
        
        $this->walletService->credit(
            $dspWallet,
            $netAmount,
            'peacelink_delivery',
            $peaceLink->id,
            "Delivery fee for PeaceLink #{$peaceLink->reference_number}"
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
            'notes' => 'Paid on cancellation',
        ]);

        // Record platform fee IMMEDIATELY
        $this->ledgerService->recordPlatformFee($peaceLink, $fee, 'dsp_fee_on_cancel');
    }

    /**
     * Get appropriate cancellation message
     */
    private function getCancellationMessage(string $party, bool $dspAssigned): string
    {
        if ($party === 'buyer') {
            return $dspAssigned
                ? 'تم إلغاء الطلب. تم استرداد قيمة المنتج. رسوم التوصيل تم دفعها للمندوب.'
                : 'تم إلغاء الطلب. تم استرداد كامل المبلغ.';
        }

        if ($party === 'merchant') {
            return $dspAssigned
                ? 'تم إلغاء الطلب. تم استرداد كامل المبلغ للمشتري. تم خصم رسوم التوصيل من محفظتك.'
                : 'تم إلغاء الطلب. تم استرداد كامل المبلغ للمشتري.';
        }

        return 'تم إلغاء الطلب.';
    }
}


// app/Modules/PeaceLink/DTOs/CancellationResult.php

namespace App\Modules\PeaceLink\DTOs;

class CancellationResult
{
    public function __construct(
        public readonly float $refundToBuyer,
        public readonly float $dspPayout,
        public readonly float $merchantPayout,
        public readonly float $platformFee,
        public readonly string $message,
    ) {}

    public function toArray(): array
    {
        return [
            'refund_to_buyer' => $this->refundToBuyer,
            'dsp_payout' => $this->dspPayout,
            'merchant_payout' => $this->merchantPayout,
            'platform_fee' => $this->platformFee,
            'message' => $this->message,
        ];
    }
}
