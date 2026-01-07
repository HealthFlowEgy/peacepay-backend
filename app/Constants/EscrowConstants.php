<?php
namespace App\Constants;

/**
 * PeaceLink/Escrow Constants
 * Updated based on Re-Engineering Specification v2.0
 * 
 * State Machine:
 * CREATED -> PENDING_APPROVAL -> SPH_ACTIVE -> DSP_ASSIGNED -> OTP_GENERATED -> DELIVERED
 *                                    |              |              |
 *                                    v              v              v
 *                                CANCELED      CANCELED       DISPUTED -> RESOLVED
 */
class EscrowConstants 
{
    // ============================================================================
    // PEACELINK STATUS CODES
    // ============================================================================
    
    // Initial states
    const CREATED           = 1;   // Merchant created, waiting for buyer
    const PENDING_APPROVAL  = 2;   // Buyer received link, not yet approved
    const PAYMENT_PENDING   = 3;   // Legacy - buyer needs to pay (deprecated)
    
    // Active states
    const SPH_ACTIVE        = 4;   // Buyer approved & paid, SPH created
    const DSP_ASSIGNED      = 5;   // Merchant assigned DSP wallet
    const OTP_GENERATED     = 6;   // OTP generated and sent to buyer
    const ONGOING           = 7;   // Legacy - in progress
    
    // Terminal states
    const DELIVERED         = 8;   // OTP verified, funds released
    const RELEASED          = 9;   // Legacy - same as delivered
    const CANCELED          = 10;  // Canceled by any party
    const REFUNDED          = 11;  // Funds returned to buyer
    const EXPIRED           = 12;  // Link expired without action
    
    // Dispute states
    const ACTIVE_DISPUTE    = 13;  // Dispute opened, funds locked
    const DISPUTED          = 14;  // Legacy - same as active dispute
    const RESOLVED          = 15;  // Dispute resolved by admin
    
    // Legacy states (for backward compatibility)
    const APPROVAL_PENDING  = 2;   // Alias for PENDING_APPROVAL
    const PAYMENT_WATTING   = 3;   // Alias for PAYMENT_PENDING

    // ============================================================================
    // USER ROLES
    // ============================================================================
    
    const SELLER_TYPE   = "seller";
    const BUYER_TYPE    = "buyer";
    const MERCHANT_TYPE = "seller";  // Alias - merchant is seller in PeaceLink
    const DSP_TYPE      = "delivery";
    const ADMIN_TYPE    = "admin";

    // ============================================================================
    // FEE PAYER OPTIONS
    // ============================================================================
    
    const ME     = "me";
    const SELLER = "seller";
    const BUYER  = "buyer";
    const HALF   = "half";
    const MERCHANT = "seller";  // Alias

    // ============================================================================
    // PAYMENT TYPES
    // ============================================================================
    
    const MY_WALLET    = 1;
    const GATEWAY      = 2;
    const DID_NOT_PAID = 3;

    // ============================================================================
    // CANCELLATION PARTIES
    // ============================================================================
    
    const CANCEL_BY_BUYER    = 'buyer';
    const CANCEL_BY_MERCHANT = 'merchant';
    const CANCEL_BY_DSP      = 'dsp';
    const CANCEL_BY_ADMIN    = 'admin';
    const CANCEL_BY_SYSTEM   = 'system';

    // ============================================================================
    // FEE CONFIGURATION
    // ============================================================================
    
    // Fee rates (as decimals)
    const MERCHANT_FEE_PERCENTAGE = 0.005;  // 0.5%
    const MERCHANT_FEE_FIXED      = 2.00;   // 2 EGP (only on final release)
    const DSP_FEE_PERCENTAGE      = 0.005;  // 0.5%
    const CASHOUT_FEE_PERCENTAGE  = 0.015;  // 1.5%
    const ADVANCE_FEE_PERCENTAGE  = 0.005;  // 0.5% (no fixed fee)

    // ============================================================================
    // OTP CONFIGURATION
    // ============================================================================
    
    const OTP_LENGTH          = 6;
    const OTP_EXPIRY_HOURS    = 24;
    const OTP_MAX_ATTEMPTS    = 3;

    // ============================================================================
    // PEACELINK CONFIGURATION
    // ============================================================================
    
    const LINK_EXPIRY_HOURS       = 24;    // Link expires if not approved
    const MAX_DELIVERY_DAYS       = 7;     // Default max delivery time
    const MAX_DSP_REASSIGNMENTS   = 1;     // Max times DSP can be changed

    // ============================================================================
    // DISPUTE STATUS
    // ============================================================================
    
    const DISPUTE_OPEN           = 'open';
    const DISPUTE_UNDER_REVIEW   = 'under_review';
    const DISPUTE_RESOLVED_BUYER = 'resolved_buyer';
    const DISPUTE_RESOLVED_MERCHANT = 'resolved_merchant';
    const DISPUTE_RESOLVED_SPLIT = 'resolved_split';

    // ============================================================================
    // CASHOUT STATUS
    // ============================================================================
    
    const CASHOUT_PENDING    = 'pending';
    const CASHOUT_APPROVED   = 'approved';
    const CASHOUT_REJECTED   = 'rejected';
    const CASHOUT_PROCESSING = 'processing';
    const CASHOUT_COMPLETED  = 'completed';
    const CASHOUT_FAILED     = 'failed';

    // ============================================================================
    // HELPER METHODS
    // ============================================================================

    /**
     * Get human-readable status name
     */
    public static function getStatusName(int $status): string
    {
        $statuses = [
            self::CREATED          => 'Created',
            self::PENDING_APPROVAL => 'Pending Approval',
            self::PAYMENT_PENDING  => 'Payment Pending',
            self::SPH_ACTIVE       => 'SPH Active',
            self::DSP_ASSIGNED     => 'DSP Assigned',
            self::OTP_GENERATED    => 'OTP Generated',
            self::ONGOING          => 'Ongoing',
            self::DELIVERED        => 'Delivered',
            self::RELEASED         => 'Released',
            self::CANCELED         => 'Canceled',
            self::REFUNDED         => 'Refunded',
            self::EXPIRED          => 'Expired',
            self::ACTIVE_DISPUTE   => 'Active Dispute',
            self::DISPUTED         => 'Disputed',
            self::RESOLVED         => 'Resolved',
        ];

        return $statuses[$status] ?? 'Unknown';
    }

    /**
     * Get Arabic status name
     */
    public static function getStatusNameAr(int $status): string
    {
        $statuses = [
            self::CREATED          => 'تم الإنشاء',
            self::PENDING_APPROVAL => 'في انتظار الموافقة',
            self::PAYMENT_PENDING  => 'في انتظار الدفع',
            self::SPH_ACTIVE       => 'الحجز نشط',
            self::DSP_ASSIGNED     => 'تم تعيين المندوب',
            self::OTP_GENERATED    => 'تم إنشاء رمز التحقق',
            self::ONGOING          => 'جاري',
            self::DELIVERED        => 'تم التسليم',
            self::RELEASED         => 'تم الإفراج',
            self::CANCELED         => 'ملغي',
            self::REFUNDED         => 'تم الاسترداد',
            self::EXPIRED          => 'منتهي الصلاحية',
            self::ACTIVE_DISPUTE   => 'نزاع نشط',
            self::DISPUTED         => 'متنازع عليه',
            self::RESOLVED         => 'تم الحل',
        ];

        return $statuses[$status] ?? 'غير معروف';
    }

    /**
     * Check if buyer can cancel at current status
     */
    public static function canBuyerCancel(int $status): bool
    {
        // Buyer can cancel before OTP is used (DELIVERED)
        return in_array($status, [
            self::SPH_ACTIVE,
            self::DSP_ASSIGNED,
            self::OTP_GENERATED,
        ]);
    }

    /**
     * Check if merchant can cancel at current status
     */
    public static function canMerchantCancel(int $status): bool
    {
        // Merchant can cancel before OTP is used
        return in_array($status, [
            self::SPH_ACTIVE,
            self::DSP_ASSIGNED,
            self::OTP_GENERATED,
        ]);
    }

    /**
     * Check if DSP can cancel at current status
     */
    public static function canDspCancel(int $status): bool
    {
        // DSP can only cancel when assigned and before OTP used
        return in_array($status, [
            self::DSP_ASSIGNED,
            self::OTP_GENERATED,
        ]);
    }

    /**
     * Check if DSP is assigned at current status
     */
    public static function isDspAssigned(int $status): bool
    {
        return in_array($status, [
            self::DSP_ASSIGNED,
            self::OTP_GENERATED,
            self::DELIVERED,
            self::RELEASED,
        ]);
    }

    /**
     * Check if OTP should be visible to buyer
     */
    public static function isOtpVisibleToBuyer(int $status): bool
    {
        // OTP only visible after DSP is assigned
        return in_array($status, [
            self::DSP_ASSIGNED,
            self::OTP_GENERATED,
        ]);
    }

    /**
     * Check if transaction is in terminal state
     */
    public static function isTerminalState(int $status): bool
    {
        return in_array($status, [
            self::DELIVERED,
            self::RELEASED,
            self::CANCELED,
            self::REFUNDED,
            self::EXPIRED,
            self::RESOLVED,
        ]);
    }

    /**
     * Get all valid status transitions
     */
    public static function getValidTransitions(int $currentStatus): array
    {
        $transitions = [
            self::CREATED => [self::PENDING_APPROVAL, self::EXPIRED],
            self::PENDING_APPROVAL => [self::SPH_ACTIVE, self::EXPIRED, self::CANCELED],
            self::SPH_ACTIVE => [self::DSP_ASSIGNED, self::CANCELED, self::ACTIVE_DISPUTE],
            self::DSP_ASSIGNED => [self::OTP_GENERATED, self::CANCELED, self::ACTIVE_DISPUTE],
            self::OTP_GENERATED => [self::DELIVERED, self::CANCELED, self::ACTIVE_DISPUTE],
            self::DELIVERED => [self::ACTIVE_DISPUTE],
            self::ACTIVE_DISPUTE => [self::RESOLVED],
        ];

        return $transitions[$currentStatus] ?? [];
    }
}
