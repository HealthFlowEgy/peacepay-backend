<?php

namespace App\Services;

use App\Constants\EscrowConstants;
use App\Models\Escrow;
use App\Models\User;
use App\Models\UserWallet;
use App\Models\LedgerEntry;
use App\Models\FeeConfiguration;
use App\Models\PlatformWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Exception;

/**
 * PeaceLink Service
 * Handles all business logic for PeaceLink (SPH) transactions
 * Based on Re-Engineering Specification v2.0
 */
class PeaceLinkService
{
    /**
     * Generate a unique reference number
     */
    public function generateReferenceNumber(): string
    {
        $prefix = 'PL';
        $timestamp = now()->format('ymd');
        $random = strtoupper(Str::random(4));
        return "{$prefix}{$timestamp}{$random}";
    }

    /**
     * Generate OTP for delivery confirmation
     */
    public function generateOtp(): array
    {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = Hash::make($otp);
        $expiresAt = now()->addHours(EscrowConstants::OTP_EXPIRY_HOURS);
        
        return [
            'otp' => $otp,
            'hash' => $hash,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(Escrow $escrow, string $otp): bool
    {
        if ($escrow->otp_attempts >= EscrowConstants::OTP_MAX_ATTEMPTS) {
            throw new Exception('Maximum OTP attempts exceeded');
        }

        if ($escrow->otp_expires_at && now()->isAfter($escrow->otp_expires_at)) {
            throw new Exception('OTP has expired');
        }

        if (!Hash::check($otp, $escrow->otp_hash)) {
            $escrow->increment('otp_attempts');
            return false;
        }

        return true;
    }

    /**
     * Get current fee configuration
     */
    public function getFeeConfiguration(): array
    {
        $fees = FeeConfiguration::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', now());
            })
            ->get()
            ->keyBy('fee_type');

        return [
            'merchant_percentage' => $fees['merchant_percentage']->rate ?? EscrowConstants::MERCHANT_FEE_PERCENTAGE,
            'merchant_fixed' => $fees['merchant_fixed']->fixed_amount ?? EscrowConstants::MERCHANT_FEE_FIXED,
            'dsp_percentage' => $fees['dsp_percentage']->rate ?? EscrowConstants::DSP_FEE_PERCENTAGE,
            'cashout_percentage' => $fees['cashout_percentage']->rate ?? EscrowConstants::CASHOUT_FEE_PERCENTAGE,
            'advance_percentage' => $fees['advance_percentage']->rate ?? EscrowConstants::ADVANCE_FEE_PERCENTAGE,
        ];
    }

    /**
     * Calculate merchant fee
     * BUG FIX: Fixed fee (2 EGP) only charged on FINAL release, not on advance
     */
    public function calculateMerchantFee(float $amount, bool $isAdvance = false): array
    {
        $fees = $this->getFeeConfiguration();
        
        $percentageFee = $amount * $fees['merchant_percentage'];
        
        // Fixed fee ONLY on final release, NOT on advance
        $fixedFee = $isAdvance ? 0 : $fees['merchant_fixed'];
        
        $totalFee = $percentageFee + $fixedFee;
        $netAmount = $amount - $totalFee;

        return [
            'gross_amount' => $amount,
            'percentage_fee' => round($percentageFee, 2),
            'fixed_fee' => round($fixedFee, 2),
            'total_fee' => round($totalFee, 2),
            'net_amount' => round($netAmount, 2),
        ];
    }

    /**
     * Calculate DSP fee
     */
    public function calculateDspFee(float $deliveryFee): array
    {
        $fees = $this->getFeeConfiguration();
        
        $fee = $deliveryFee * $fees['dsp_percentage'];
        $netAmount = $deliveryFee - $fee;

        return [
            'gross_amount' => $deliveryFee,
            'fee' => round($fee, 2),
            'net_amount' => round($netAmount, 2),
        ];
    }

    /**
     * Calculate cashout fee
     * BUG FIX: Fee must be deducted at REQUEST time, not approval
     */
    public function calculateCashoutFee(float $amount): array
    {
        $fees = $this->getFeeConfiguration();
        
        $fee = $amount * $fees['cashout_percentage'];
        $netAmount = $amount - $fee;

        return [
            'requested_amount' => $amount,
            'fee' => round($fee, 2),
            'net_amount' => round($netAmount, 2),
        ];
    }

    /**
     * Process buyer approval and create SPH
     */
    public function processBuyerApproval(Escrow $escrow, User $buyer): array
    {
        return DB::transaction(function () use ($escrow, $buyer) {
            // Validate state
            if (!in_array($escrow->status, [EscrowConstants::CREATED, EscrowConstants::PENDING_APPROVAL])) {
                throw new Exception('PeaceLink cannot be approved in current state');
            }

            // Get buyer wallet
            $buyerWallet = UserWallet::where('user_id', $buyer->id)
                ->where('currency_id', $escrow->escrowCurrency->id)
                ->first();

            if (!$buyerWallet) {
                throw new Exception('Buyer wallet not found');
            }

            // Calculate total amount buyer needs to pay
            $totalAmount = $escrow->item_amount;
            if ($escrow->delivery_fee_paid_by === 'buyer') {
                $totalAmount += $escrow->delivery_fee;
            }

            // Check balance
            if ($buyerWallet->balance < $totalAmount) {
                throw new Exception('Insufficient balance');
            }

            // Debit buyer wallet
            $buyerWallet->balance -= $totalAmount;
            $buyerWallet->save();

            // Create ledger entry for SPH hold
            $this->createLedgerEntry([
                'escrow_id' => $escrow->id,
                'debit_wallet_id' => $buyerWallet->id,
                'amount' => $totalAmount,
                'entry_type' => 'sph_hold',
                'description' => "SPH hold for PeaceLink {$escrow->reference_number}",
            ]);

            // Process advance payment if configured
            if ($escrow->advance_percentage > 0) {
                $this->processAdvancePayment($escrow);
            }

            // Update escrow status
            $escrow->status = EscrowConstants::SPH_ACTIVE;
            $escrow->buyer_or_seller_id = $buyer->id;
            $escrow->approved_at = now();
            $escrow->expires_at = null; // Clear link expiry
            $escrow->max_delivery_at = now()->addDays(EscrowConstants::MAX_DELIVERY_DAYS);
            $escrow->save();

            return [
                'success' => true,
                'escrow' => $escrow->fresh(),
                'amount_debited' => $totalAmount,
            ];
        });
    }

    /**
     * Process advance payment to merchant
     * BUG FIX: Only percentage fee on advance, NO fixed fee
     */
    public function processAdvancePayment(Escrow $escrow): void
    {
        $advanceAmount = $escrow->item_amount * ($escrow->advance_percentage / 100);
        $escrow->advance_amount = $advanceAmount;

        // Calculate fee (percentage only, no fixed fee)
        $feeCalc = $this->calculateMerchantFee($advanceAmount, true);

        // Get merchant wallet
        $merchantWallet = UserWallet::where('user_id', $escrow->user_id)
            ->where('currency_id', $escrow->escrowCurrency->id)
            ->first();

        if ($merchantWallet) {
            // Credit merchant wallet (net of fee)
            $merchantWallet->balance += $feeCalc['net_amount'];
            $merchantWallet->save();

            // Create ledger entry for advance payout
            $this->createLedgerEntry([
                'escrow_id' => $escrow->id,
                'credit_wallet_id' => $merchantWallet->id,
                'amount' => $feeCalc['net_amount'],
                'entry_type' => 'advance_payout',
                'description' => "Advance payment for PeaceLink {$escrow->reference_number}",
            ]);

            // BUG FIX: Update platform profit IMMEDIATELY
            $this->updatePlatformProfit($feeCalc['total_fee'], $escrow->id, 'advance_fee');
        }

        $escrow->advance_paid = true;
        $escrow->save();
    }

    /**
     * Assign DSP to PeaceLink
     */
    public function assignDsp(Escrow $escrow, User $dsp, ?string $dspWalletNumber = null): array
    {
        return DB::transaction(function () use ($escrow, $dsp, $dspWalletNumber) {
            // Validate state
            if ($escrow->status !== EscrowConstants::SPH_ACTIVE) {
                throw new Exception('DSP can only be assigned when SPH is active');
            }

            // Check reassignment limit
            if ($escrow->dsp_reassignment_count >= EscrowConstants::MAX_DSP_REASSIGNMENTS && $escrow->delivery_id) {
                throw new Exception('Maximum DSP reassignments reached');
            }

            // Generate OTP
            $otpData = $this->generateOtp();

            // Update escrow
            $escrow->delivery_id = $dsp->id;
            $escrow->dsp_wallet_number = $dspWalletNumber;
            $escrow->otp_hash = $otpData['hash'];
            $escrow->otp_generated_at = now();
            $escrow->otp_expires_at = $otpData['expires_at'];
            $escrow->otp_attempts = 0;
            $escrow->status = EscrowConstants::DSP_ASSIGNED;
            $escrow->dsp_assigned_at = now();
            
            if ($escrow->delivery_id !== $dsp->id) {
                $escrow->dsp_reassignment_count++;
            }
            
            $escrow->save();

            return [
                'success' => true,
                'escrow' => $escrow->fresh(),
                'otp' => $otpData['otp'], // Send to buyer via SMS
            ];
        });
    }

    /**
     * Process delivery confirmation (OTP verified)
     */
    public function processDelivery(Escrow $escrow, User $verifiedBy): array
    {
        return DB::transaction(function () use ($escrow, $verifiedBy) {
            // Calculate remaining amount to merchant
            $remainingItemAmount = $escrow->item_amount - $escrow->advance_amount;
            
            // Calculate fee (with fixed fee on final release)
            $feeCalc = $this->calculateMerchantFee($remainingItemAmount, false);

            // Get merchant wallet
            $merchantWallet = UserWallet::where('user_id', $escrow->user_id)
                ->where('currency_id', $escrow->escrowCurrency->id)
                ->first();

            if ($merchantWallet) {
                // Credit merchant wallet
                $merchantWallet->balance += $feeCalc['net_amount'];
                $merchantWallet->save();

                // Create ledger entry
                $this->createLedgerEntry([
                    'escrow_id' => $escrow->id,
                    'credit_wallet_id' => $merchantWallet->id,
                    'amount' => $feeCalc['net_amount'],
                    'entry_type' => 'merchant_payout',
                    'description' => "Final payment for PeaceLink {$escrow->reference_number}",
                ]);

                // BUG FIX: Update platform profit IMMEDIATELY
                $this->updatePlatformProfit($feeCalc['total_fee'], $escrow->id, 'merchant_fee');
            }

            // Process DSP payout
            $this->processDspPayout($escrow);

            // Update escrow status
            $escrow->status = EscrowConstants::DELIVERED;
            $escrow->otp_verified_at = now();
            $escrow->otp_verified_by = $verifiedBy->id;
            $escrow->delivered_at = now();
            $escrow->save();

            return [
                'success' => true,
                'escrow' => $escrow->fresh(),
                'merchant_payout' => $feeCalc['net_amount'],
            ];
        });
    }

    /**
     * Process DSP payout
     */
    public function processDspPayout(Escrow $escrow): void
    {
        if (!$escrow->delivery_id) {
            return;
        }

        $dspFee = $this->calculateDspFee($escrow->delivery_fee);

        // Get DSP wallet
        $dspWallet = UserWallet::where('user_id', $escrow->delivery_id)
            ->where('currency_id', $escrow->escrowCurrency->id)
            ->first();

        if ($dspWallet) {
            // Credit DSP wallet
            $dspWallet->balance += $dspFee['net_amount'];
            $dspWallet->save();

            // Create ledger entry
            $this->createLedgerEntry([
                'escrow_id' => $escrow->id,
                'credit_wallet_id' => $dspWallet->id,
                'amount' => $dspFee['net_amount'],
                'entry_type' => 'dsp_payout',
                'description' => "DSP delivery fee for PeaceLink {$escrow->reference_number}",
            ]);

            // Update platform profit
            $this->updatePlatformProfit($dspFee['fee'], $escrow->id, 'dsp_fee');
        }
    }

    /**
     * Process cancellation based on rules
     */
    public function processCancellation(Escrow $escrow, string $canceledBy, ?string $reason = null): array
    {
        return DB::transaction(function () use ($escrow, $canceledBy, $reason) {
            $refundDetails = $this->calculateRefund($escrow, $canceledBy);

            // Process buyer refund
            if ($refundDetails['buyer_refund'] > 0) {
                $buyerWallet = UserWallet::where('user_id', $escrow->buyer_or_seller_id)
                    ->where('currency_id', $escrow->escrowCurrency->id)
                    ->first();

                if ($buyerWallet) {
                    $buyerWallet->balance += $refundDetails['buyer_refund'];
                    $buyerWallet->save();

                    $this->createLedgerEntry([
                        'escrow_id' => $escrow->id,
                        'credit_wallet_id' => $buyerWallet->id,
                        'amount' => $refundDetails['buyer_refund'],
                        'entry_type' => 'refund',
                        'description' => "Refund for canceled PeaceLink {$escrow->reference_number}",
                    ]);
                }
            }

            // Process advance refund if applicable
            if ($refundDetails['advance_refund'] && $escrow->advance_paid) {
                $merchantWallet = UserWallet::where('user_id', $escrow->user_id)
                    ->where('currency_id', $escrow->escrowCurrency->id)
                    ->first();

                if ($merchantWallet && $merchantWallet->balance >= $escrow->advance_amount) {
                    $merchantWallet->balance -= $escrow->advance_amount;
                    $merchantWallet->save();

                    $this->createLedgerEntry([
                        'escrow_id' => $escrow->id,
                        'debit_wallet_id' => $merchantWallet->id,
                        'amount' => $escrow->advance_amount,
                        'entry_type' => 'advance_refund',
                        'description' => "Advance refund for canceled PeaceLink {$escrow->reference_number}",
                    ]);
                }
            }

            // Process DSP fee if DSP was assigned
            if ($refundDetails['dsp_receives_fee'] && $escrow->delivery_id) {
                $this->processDspPayout($escrow);
                
                // If merchant cancels after DSP assigned, merchant pays DSP fee
                if ($canceledBy === EscrowConstants::CANCEL_BY_MERCHANT) {
                    $merchantWallet = UserWallet::where('user_id', $escrow->user_id)
                        ->where('currency_id', $escrow->escrowCurrency->id)
                        ->first();

                    if ($merchantWallet) {
                        $dspFee = $this->calculateDspFee($escrow->delivery_fee);
                        $merchantWallet->balance -= $dspFee['gross_amount'];
                        $merchantWallet->save();
                    }
                }
            }

            // Update escrow status
            $escrow->status = EscrowConstants::CANCELED;
            $escrow->canceled_at = now();
            $escrow->canceled_by = $canceledBy;
            $escrow->cancellation_reason = $reason;
            $escrow->save();

            return [
                'success' => true,
                'escrow' => $escrow->fresh(),
                'refund_details' => $refundDetails,
            ];
        });
    }

    /**
     * Calculate refund based on cancellation rules
     */
    public function calculateRefund(Escrow $escrow, string $canceledBy): array
    {
        $isDspAssigned = EscrowConstants::isDspAssigned($escrow->status);
        $totalPaid = $escrow->item_amount;
        
        if ($escrow->delivery_fee_paid_by === 'buyer') {
            $totalPaid += $escrow->delivery_fee;
        }

        $result = [
            'buyer_refund' => 0,
            'advance_refund' => false,
            'dsp_receives_fee' => false,
            'merchant_pays_dsp' => false,
        ];

        switch ($canceledBy) {
            case EscrowConstants::CANCEL_BY_BUYER:
                if (!$isDspAssigned) {
                    // Before DSP: Full refund
                    $result['buyer_refund'] = $totalPaid;
                    $result['advance_refund'] = true;
                } else {
                    // After DSP: Item refund only, buyer pays DSP fee
                    $result['buyer_refund'] = $escrow->item_amount;
                    $result['advance_refund'] = true;
                    $result['dsp_receives_fee'] = true;
                }
                break;

            case EscrowConstants::CANCEL_BY_MERCHANT:
                // Merchant cancels: Full refund to buyer
                $result['buyer_refund'] = $totalPaid;
                $result['advance_refund'] = true;
                
                if ($isDspAssigned) {
                    // Merchant pays DSP fee
                    $result['dsp_receives_fee'] = true;
                    $result['merchant_pays_dsp'] = true;
                }
                break;

            case EscrowConstants::CANCEL_BY_DSP:
                // DSP cancels: No refund, just remove DSP
                // Escrow returns to SPH_ACTIVE state
                break;

            case EscrowConstants::CANCEL_BY_ADMIN:
            case EscrowConstants::CANCEL_BY_SYSTEM:
                // Admin/System: Full refund
                $result['buyer_refund'] = $totalPaid;
                $result['advance_refund'] = true;
                break;
        }

        return $result;
    }

    /**
     * Update platform profit ledger
     * BUG FIX: Must update IMMEDIATELY, not batch
     */
    protected function updatePlatformProfit(float $amount, int $escrowId, string $feeType): void
    {
        // Get or create platform wallet
        $platformWallet = PlatformWallet::firstOrCreate(
            ['name' => 'peacepay_profit'],
            ['balance' => 0, 'currency' => 'EGP']
        );

        // Update balance
        $platformWallet->balance += $amount;
        $platformWallet->save();

        // Create ledger entry
        $this->createLedgerEntry([
            'escrow_id' => $escrowId,
            'platform_wallet_name' => 'peacepay_profit',
            'amount' => $amount,
            'entry_type' => 'platform_fee',
            'description' => "Platform fee ({$feeType}) - {$amount} EGP",
        ]);
    }

    /**
     * Create ledger entry
     */
    protected function createLedgerEntry(array $data): LedgerEntry
    {
        return LedgerEntry::create([
            'entry_id' => Str::uuid(),
            'escrow_id' => $data['escrow_id'] ?? null,
            'debit_wallet_id' => $data['debit_wallet_id'] ?? null,
            'credit_wallet_id' => $data['credit_wallet_id'] ?? null,
            'platform_wallet_name' => $data['platform_wallet_name'] ?? null,
            'amount' => $data['amount'],
            'entry_type' => $data['entry_type'],
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);
    }
}
