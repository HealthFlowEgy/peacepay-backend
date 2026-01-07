<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\Enums;

/**
 * PeaceLink Status Enum
 * 
 * Based on Re-Engineering Specification v2.0
 * Defines all possible states in the PeaceLink lifecycle
 */
enum PeaceLinkStatus: string
{
    // Initial States
    case CREATED = 'created';
    case PENDING_APPROVAL = 'pending_approval';
    
    // Active States
    case SPH_ACTIVE = 'sph_active';
    case DSP_ASSIGNED = 'dsp_assigned';
    case OTP_GENERATED = 'otp_generated';
    case IN_TRANSIT = 'in_transit';
    
    // Terminal States
    case DELIVERED = 'delivered';
    case CANCELED = 'canceled';
    case EXPIRED = 'expired';
    
    // Dispute States
    case ACTIVE_DISPUTE = 'active_dispute';
    case DISPUTE_RESOLVED = 'dispute_resolved';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Created',
            self::PENDING_APPROVAL => 'Pending Approval',
            self::SPH_ACTIVE => 'Payment Held',
            self::DSP_ASSIGNED => 'Delivery Assigned',
            self::OTP_GENERATED => 'Ready for Delivery',
            self::IN_TRANSIT => 'In Transit',
            self::DELIVERED => 'Delivered',
            self::CANCELED => 'Canceled',
            self::EXPIRED => 'Expired',
            self::ACTIVE_DISPUTE => 'Dispute Active',
            self::DISPUTE_RESOLVED => 'Dispute Resolved',
        };
    }

    /**
     * Get Arabic label
     */
    public function labelAr(): string
    {
        return match ($this) {
            self::CREATED => 'تم الإنشاء',
            self::PENDING_APPROVAL => 'في انتظار الموافقة',
            self::SPH_ACTIVE => 'الدفع محجوز',
            self::DSP_ASSIGNED => 'تم تعيين التوصيل',
            self::OTP_GENERATED => 'جاهز للتوصيل',
            self::IN_TRANSIT => 'قيد التوصيل',
            self::DELIVERED => 'تم التوصيل',
            self::CANCELED => 'ملغي',
            self::EXPIRED => 'منتهي الصلاحية',
            self::ACTIVE_DISPUTE => 'نزاع نشط',
            self::DISPUTE_RESOLVED => 'تم حل النزاع',
        };
    }

    /**
     * Get numeric code for API compatibility
     */
    public function code(): int
    {
        return match ($this) {
            self::CREATED => 0,
            self::SPH_ACTIVE => 1,
            self::CANCELED => 2,
            self::PENDING_APPROVAL => 3,
            self::DSP_ASSIGNED => 4,
            self::OTP_GENERATED => 5,
            self::IN_TRANSIT => 6,
            self::DELIVERED => 7,
            self::EXPIRED => 8,
            self::ACTIVE_DISPUTE => 10,
            self::DISPUTE_RESOLVED => 11,
        };
    }

    /**
     * Create from numeric code
     */
    public static function fromCode(int $code): self
    {
        return match ($code) {
            0 => self::CREATED,
            1 => self::SPH_ACTIVE,
            2 => self::CANCELED,
            3 => self::PENDING_APPROVAL,
            4 => self::DSP_ASSIGNED,
            5 => self::OTP_GENERATED,
            6 => self::IN_TRANSIT,
            7 => self::DELIVERED,
            8 => self::EXPIRED,
            10 => self::ACTIVE_DISPUTE,
            11 => self::DISPUTE_RESOLVED,
            default => throw new \InvalidArgumentException("Invalid status code: {$code}"),
        };
    }

    /**
     * Check if this is a terminal state
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::DELIVERED,
            self::CANCELED,
            self::EXPIRED,
            self::DISPUTE_RESOLVED,
        ]);
    }

    /**
     * Check if DSP is assigned in this state
     */
    public function isDspAssigned(): bool
    {
        return in_array($this, [
            self::DSP_ASSIGNED,
            self::OTP_GENERATED,
            self::IN_TRANSIT,
            self::DELIVERED,
        ]);
    }

    /**
     * Check if cancellation is allowed in this state
     */
    public function canCancel(): bool
    {
        return in_array($this, [
            self::CREATED,
            self::PENDING_APPROVAL,
            self::SPH_ACTIVE,
            self::DSP_ASSIGNED,
            self::OTP_GENERATED,
        ]);
    }

    /**
     * Get allowed transitions from this state
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::CREATED => [self::PENDING_APPROVAL, self::CANCELED, self::EXPIRED],
            self::PENDING_APPROVAL => [self::SPH_ACTIVE, self::CANCELED, self::EXPIRED],
            self::SPH_ACTIVE => [self::DSP_ASSIGNED, self::CANCELED, self::EXPIRED],
            self::DSP_ASSIGNED => [self::OTP_GENERATED, self::SPH_ACTIVE, self::CANCELED],
            self::OTP_GENERATED => [self::IN_TRANSIT, self::DELIVERED, self::CANCELED],
            self::IN_TRANSIT => [self::DELIVERED, self::ACTIVE_DISPUTE],
            self::DELIVERED => [self::ACTIVE_DISPUTE],
            self::ACTIVE_DISPUTE => [self::DISPUTE_RESOLVED],
            self::CANCELED, self::EXPIRED, self::DISPUTE_RESOLVED => [],
        };
    }
}
