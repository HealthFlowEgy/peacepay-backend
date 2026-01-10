<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\Services;

use App\Modules\PeaceLink\Models\PeaceLink;
use App\Modules\PeaceLink\Models\SphHold;
use App\Modules\PeaceLink\Models\PeaceLinkPayout;
use App\Modules\PeaceLink\Enums\PeaceLinkStatus;
use App\Modules\PeaceLink\Enums\CancellationParty;
use App\Modules\PeaceLink\Enums\PayoutType;
use App\Modules\PeaceLink\Events\PeaceLinkCreated;
use App\Modules\PeaceLink\Events\PeaceLinkApproved;
use App\Modules\PeaceLink\Events\DspAssigned;
use App\Modules\PeaceLink\Events\DeliveryConfirmed;
use App\Modules\PeaceLink\Events\PeaceLinkCanceled;
use App\Modules\PeaceLink\DTOs\CreatePeaceLinkRequest;
use App\Modules\PeaceLink\DTOs\CancellationResult;
use App\Modules\Wallet\Services\WalletService;
use App\Modules\Ledger\Services\LedgerService;
use App\Modules\Notification\Services\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PeaceLinkService
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly FeeCalculatorService $feeCalculator,
        private readonly OtpGeneratorService $otpGenerator,
        private readonly LedgerService $ledgerService,
        private readonly SmsService $smsService,
    ) {}

    /**
     * Create a new PeaceLink
     */
    public function create(CreatePeaceLinkRequest $request, string $merchantId): PeaceLink
    {
        return DB::transaction(function () use ($request, $merchantId) {
            // Freeze fee configuration at creation time
            $feeSnapshot = $this->feeCalculator->getCurrentFeeSnapshot();
            
            // Calculate amounts
            $totalAmount = $request->itemAmount + $request->deliveryFee;
            $advanceAmount = $request->advancePercentage > 0 
                ? ($request->itemAmount * $request->advancePercentage / 100)
                : 0;

            $peaceLink = PeaceLink::create([
                'id' => Str::uuid(),
                'reference_number' => $this->generateReferenceNumber(),
                'merchant_id' => $merchantId,
                'buyer_phone' => $request->buyerPhone,
                'item_description' => $request->itemDescription,
                'item_description_ar' => $request->itemDescriptionAr,
                'item_amount' => $request->itemAmount,
                'delivery_fee' => $request->deliveryFee,
                'total_amount' => $totalAmount,
                'delivery_fee_paid_by' => $request->deliveryFeePaidBy,
                'advance_percentage' => $request->advancePercentage,
                'advance_amount' => $advanceAmount,
                'policy_id' => $request->policyId,
                'policy_snapshot' => $request->policySnapshot ?? [],
                'fee_snapshot' => $feeSnapshot,
                'status' => PeaceLinkStatus::CREATED,
                'expires_at' => now()->addHours(24),
            ]);

            // Send SMS to buyer
            $this->smsService->sendPeaceLinkNotification(
                $request->buyerPhone,
                $peaceLink
            );

            // Update status to pending approval
            $peaceLink->update(['status' => PeaceLinkStatus::PENDING_APPROVAL]);

            event(new PeaceLinkCreated($peaceLink));

            return $peaceLink->fresh();
        });
    }

    /**
     * Buyer approves and pays for PeaceLink
     */
    public function approve(PeaceLink $peaceLink, string $buyerId, string $pin): PeaceLink
    {
        // Validate state
        if (!$peaceLink->canBeApproved()) {
            throw new \DomainException('PeaceLink cannot be approved in current state');
        }

        return DB::transaction(function () use ($peaceLink, $buyerId) {
            $buyerWallet = $this->walletService->getWalletByUserId($buyerId);
            
            // Check balance
            if ($buyerWallet->available_balance < $peaceLink->total_amount) {
                throw new \DomainException('Insufficient wallet balance');
            }

            // Create SPH Hold - debit buyer and hold in escrow
            $this->walletService->hold(
                $buyerWallet,
                $peaceLink->total_amount,
                'peacelink',
                $peaceLink->id,
                "SPH Hold for PeaceLink #{$peaceLink->reference_number}"
            );

            // Create SPH record
            $sphHold = SphHold::create([
                'peacelink_id' => $peaceLink->id,
                'buyer_wallet_id' => $buyerWallet->id,
                'amount' => $peaceLink->total_amount,
                'status' => 'active',
            ]);

            // Process advance payment if configured
            if ($peaceLink->advance_amount > 0) {
                $this->processAdvancePayment($peaceLink, $sphHold);
            }

            // Update PeaceLink
            $peaceLink->update([
                'buyer_id' => $buyerId,
                'status' => PeaceLinkStatus::SPH_ACTIVE,
                'approved_at' => now(),
                'max_delivery_at' => now()->addDays(
                    $peaceLink->policy_snapshot['max_delivery_days'] ?? 7
                ),
            ]);

            // Record in ledger
            $this->ledgerService->recordSphHold($peaceLink, $sphHold);

            event(new PeaceLinkApproved($peaceLink));

            return $peaceLink->fresh();
        });
    }

    /**
     * Process advance payment to merchant
     */
    private function processAdvancePayment(PeaceLink $peaceLink, SphHold $sphHold): void
    {
        $merchantWallet = $this->walletService->getWalletByUserId($peaceLink->merchant_id);
        
        // Calculate fee (0.5% only, NO fixed fee on advance)
        $fee = $this->feeCalculator->calculateAdvanceFee(
            $peaceLink->advance_amount,
            $peaceLink->fee_snapshot
        );
        
        $netAmount = $peaceLink->advance_amount - $fee;

        // Credit merchant
        $this->walletService->credit(
            $merchantWallet,
            $netAmount,
            'peacelink_advance',
            $peaceLink->id,
            "Advance payment for PeaceLink #{$peaceLink->reference_number}"
        );

        // Record payout
        PeaceLinkPayout::create([
            'peacelink_id' => $peaceLink->id,
            'recipient_type' => 'merchant',
            'recipient_id' => $peaceLink->merchant_id,
            'wallet_id' => $merchantWallet->id,
            'gross_amount' => $peaceLink->advance_amount,
            'fee_amount' => $fee,
            'net_amount' => $netAmount,
            'payout_type' => PayoutType::ADVANCE,
            'is_advance' => true,
        ]);

        // Record platform fee IMMEDIATELY (not on final release)
        $this->ledgerService->recordPlatformFee($peaceLink, $fee, 'advance_fee');
    }

    /**
     * Assign DSP to PeaceLink
     */
    public function assignDsp(
        PeaceLink $peaceLink, 
        string $dspWalletNumber,
        ?string $driverId = null
    ): PeaceLink {
        // Validate state
        if (!$peaceLink->canAssignDsp()) {
            throw new \DomainException('Cannot assign DSP in current state');
        }

        // Validate DSP wallet exists
        $dspWallet = $this->walletService->getWalletByNumber($dspWalletNumber);
        if (!$dspWallet) {
            throw new \DomainException('DSP wallet not found');
        }

        return DB::transaction(function () use ($peaceLink, $dspWallet, $driverId) {
            // Generate OTP
            $otp = $this->otpGenerator->generate();
            $otpHash = hash('sha256', $otp);

            $peaceLink->update([
                'dsp_id' => $dspWallet->user_id,
                'dsp_wallet_number' => $dspWallet->wallet_number,
                'assigned_driver_id' => $driverId,
                'otp_hash' => $otpHash,
                'otp_generated_at' => now(),
                'otp_expires_at' => now()->addHours(24),
                'dsp_assigned_at' => now(),
                'status' => PeaceLinkStatus::DSP_ASSIGNED,
            ]);

            // Send OTP to buyer via SMS
            $this->smsService->sendOtp($peaceLink->buyer_phone, $otp, $peaceLink);

            event(new DspAssigned($peaceLink));

            return $peaceLink->fresh();
        });
    }

    /**
     * Reassign DSP (only allowed once, before OTP used)
     */
    public function reassignDsp(
        PeaceLink $peaceLink,
        string $newDspWalletNumber,
        string $reason
    ): PeaceLink {
        // Validate state
        if (!$peaceLink->canReassignDsp()) {
            throw new \DomainException('Cannot reassign DSP: OTP already used or max reassignments reached');
        }

        // Check reassignment count (max 1)
        if ($peaceLink->dsp_reassignment_count >= 1) {
            throw new \DomainException('Maximum DSP reassignments reached');
        }

        // Validate new DSP wallet
        $newDspWallet = $this->walletService->getWalletByNumber($newDspWalletNumber);
        if (!$newDspWallet) {
            throw new \DomainException('DSP wallet not found');
        }

        return DB::transaction(function () use ($peaceLink, $newDspWallet, $reason) {
            // Log reassignment
            $peaceLink->logReassignment($reason);

            // Generate new OTP
            $otp = $this->otpGenerator->generate();
            $otpHash = hash('sha256', $otp);

            $peaceLink->update([
                'dsp_id' => $newDspWallet->user_id,
                'dsp_wallet_number' => $newDspWallet->wallet_number,
                'otp_hash' => $otpHash,
                'otp_generated_at' => now(),
                'otp_expires_at' => now()->addHours(24),
                'dsp_reassignment_count' => $peaceLink->dsp_reassignment_count + 1,
            ]);

            // Send new OTP to buyer
            $this->smsService->sendOtp($peaceLink->buyer_phone, $otp, $peaceLink);

            return $peaceLink->fresh();
        });
    }

    /**
     * Confirm delivery with OTP
     */
    public function confirmDelivery(PeaceLink $peaceLink, string $otp, string $verifiedById): PeaceLink
    {
        // Validate state
        if (!$peaceLink->canConfirmDelivery()) {
            throw new \DomainException('Cannot confirm delivery in current state');
        }

        // Validate OTP
        if (!$peaceLink->validateOtp($otp)) {
            $peaceLink->increment('otp_attempts');
            throw new \DomainException('Invalid OTP');
        }

        return DB::transaction(function () use ($peaceLink, $verifiedById) {
            // Process final payouts
            $this->processFinalPayouts($peaceLink);

            // Update status
            $peaceLink->update([
                'status' => PeaceLinkStatus::DELIVERED,
                'delivered_at' => now(),
                'otp_verified_at' => now(),
                'otp_verified_by' => $verifiedById,
            ]);

            // Release SPH
            $sphHold = $peaceLink->sphHold;
            $sphHold->update([
                'status' => 'released',
                'released_at' => now(),
            ]);

            event(new DeliveryConfirmed($peaceLink));

            return $peaceLink->fresh();
        });
    }

    /**
     * Process final payouts on delivery
     */
    private function processFinalPayouts(PeaceLink $peaceLink): void
    {
        // 1. Calculate remaining merchant payout
        $alreadyPaidToMerchant = $peaceLink->advance_amount;
        $remainingItemAmount = $peaceLink->item_amount - $alreadyPaidToMerchant;

        if ($remainingItemAmount > 0) {
            $merchantWallet = $this->walletService->getWalletByUserId($peaceLink->merchant_id);
            
            // Calculate fee (0.5% + 2 EGP fixed fee on FINAL release only)
            $fee = $this->feeCalculator->calculateMerchantFee(
                $remainingItemAmount,
                $peaceLink->fee_snapshot,
                true // includedFixedFee = true for final release
            );
            
            $netAmount = $remainingItemAmount - $fee;

            $this->walletService->credit(
                $merchantWallet,
                $netAmount,
                'peacelink_final',
                $peaceLink->id,
                "Final payment for PeaceLink #{$peaceLink->reference_number}"
            );

            PeaceLinkPayout::create([
                'peacelink_id' => $peaceLink->id,
                'recipient_type' => 'merchant',
                'recipient_id' => $peaceLink->merchant_id,
                'wallet_id' => $merchantWallet->id,
                'gross_amount' => $remainingItemAmount,
                'fee_amount' => $fee,
                'net_amount' => $netAmount,
                'payout_type' => PayoutType::FINAL,
                'is_advance' => false,
            ]);

            // Record platform fee IMMEDIATELY
            $this->ledgerService->recordPlatformFee($peaceLink, $fee, 'merchant_final_fee');
        }

        // 2. Pay DSP
        $this->processDspPayout($peaceLink);
    }

    /**
     * Process DSP payout
     */
    private function processDspPayout(PeaceLink $peaceLink): void
    {
        $dspWallet = $this->walletService->getWalletByUserId($peaceLink->dsp_id);
        
        // DSP fee: 0.5% of delivery fee
        $fee = $this->feeCalculator->calculateDspFee(
            $peaceLink->delivery_fee,
            $peaceLink->fee_snapshot
        );
        
        $netAmount = $peaceLink->delivery_fee - $fee;

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
            'is_advance' => false,
        ]);

        // Record platform fee IMMEDIATELY
        $this->ledgerService->recordPlatformFee($peaceLink, $fee, 'dsp_fee');
    }

    /**
     * Generate unique reference number
     */
    private function generateReferenceNumber(): string
    {
        do {
            $reference = 'PL' . strtoupper(Str::random(8));
        } while (PeaceLink::where('reference_number', $reference)->exists());

        return $reference;
    }
}
