<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\Enums;

/**
 * Payout Type Enum
 * 
 * Defines types of payouts in PeaceLink transactions
 */
enum PayoutType: string
{
    case ADVANCE = 'advance';
    case FINAL_RELEASE = 'final_release';
    case DSP_PAYMENT = 'dsp_payment';
    case REFUND = 'refund';
    case DISPUTE_RESOLUTION = 'dispute_resolution';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::ADVANCE => 'Advance Payment',
            self::FINAL_RELEASE => 'Final Release',
            self::DSP_PAYMENT => 'Delivery Payment',
            self::REFUND => 'Refund',
            self::DISPUTE_RESOLUTION => 'Dispute Resolution',
        };
    }

    /**
     * Get Arabic label
     */
    public function labelAr(): string
    {
        return match ($this) {
            self::ADVANCE => 'دفعة مقدمة',
            self::FINAL_RELEASE => 'الإفراج النهائي',
            self::DSP_PAYMENT => 'دفعة التوصيل',
            self::REFUND => 'استرداد',
            self::DISPUTE_RESOLUTION => 'حل النزاع',
        };
    }

    /**
     * Check if fixed fee applies to this payout type
     */
    public function hasFixedFee(): bool
    {
        return match ($this) {
            self::ADVANCE => false,        // No fixed fee on advance
            self::FINAL_RELEASE => true,   // Fixed fee only on final release
            self::DSP_PAYMENT => false,    // No fixed fee for DSP
            self::REFUND => false,         // No fee on refunds
            self::DISPUTE_RESOLUTION => false,
        };
    }

    /**
     * Check if percentage fee applies to this payout type
     */
    public function hasPercentageFee(): bool
    {
        return match ($this) {
            self::ADVANCE => true,         // 0.5% on advance
            self::FINAL_RELEASE => true,   // 0.5% on final release
            self::DSP_PAYMENT => true,     // Fee on DSP payment
            self::REFUND => false,
            self::DISPUTE_RESOLUTION => false,
        };
    }
}
