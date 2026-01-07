<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\Enums;

/**
 * Cancellation Party Enum
 * 
 * Defines who can cancel a PeaceLink and the associated rules
 */
enum CancellationParty: string
{
    case BUYER = 'buyer';
    case MERCHANT = 'merchant';
    case DSP = 'dsp';
    case ADMIN = 'admin';
    case SYSTEM = 'system';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::BUYER => 'Buyer',
            self::MERCHANT => 'Merchant',
            self::DSP => 'Delivery Partner',
            self::ADMIN => 'Administrator',
            self::SYSTEM => 'System',
        };
    }

    /**
     * Get Arabic label
     */
    public function labelAr(): string
    {
        return match ($this) {
            self::BUYER => 'المشتري',
            self::MERCHANT => 'التاجر',
            self::DSP => 'شريك التوصيل',
            self::ADMIN => 'المسؤول',
            self::SYSTEM => 'النظام',
        };
    }

    /**
     * Check if this party pays DSP fee on cancellation after DSP assignment
     */
    public function paysDspFeeOnCancel(): bool
    {
        return match ($this) {
            self::BUYER => true,      // Buyer pays DSP fee from their held funds
            self::MERCHANT => true,   // Merchant pays DSP fee from their wallet
            self::DSP => false,       // DSP doesn't pay (just gets removed)
            self::ADMIN => false,     // Admin decides case by case
            self::SYSTEM => false,    // System (expiry) - no DSP fee
        };
    }

    /**
     * Check if advance is refunded when this party cancels
     */
    public function refundsAdvance(): bool
    {
        return match ($this) {
            self::BUYER => false,     // Buyer fault - no advance refund
            self::MERCHANT => true,   // Merchant fault - advance refunded
            self::DSP => false,       // DSP cancel doesn't affect advance
            self::ADMIN => true,      // Admin can refund advance
            self::SYSTEM => true,     // System expiry - advance refunded
        };
    }
}
