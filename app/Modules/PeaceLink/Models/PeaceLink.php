<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\Models;

use App\Modules\PeaceLink\Enums\PeaceLinkStatus;
use App\Modules\PeaceLink\Enums\CancellationParty;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PeaceLink extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'peacelinks';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'reference_number',
        'merchant_id',
        'buyer_id',
        'buyer_phone',
        'dsp_id',
        'dsp_wallet_number',
        'assigned_driver_id',
        'policy_id',
        'policy_snapshot',
        'fee_snapshot',
        'item_amount',
        'delivery_fee',
        'total_amount',
        'delivery_fee_paid_by',
        'advance_percentage',
        'advance_amount',
        'item_description',
        'item_description_ar',
        'item_quantity',
        'item_metadata',
        'status',
        'otp_hash',
        'otp_generated_at',
        'otp_expires_at',
        'otp_attempts',
        'otp_verified_at',
        'otp_verified_by',
        'expires_at',
        'max_delivery_at',
        'approved_at',
        'dsp_assigned_at',
        'delivered_at',
        'canceled_at',
        'canceled_by',
        'cancellation_reason',
        'dsp_reassignment_count',
    ];

    protected $casts = [
        'policy_snapshot' => 'array',
        'fee_snapshot' => 'array',
        'item_metadata' => 'array',
        'item_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'advance_percentage' => 'decimal:2',
        'advance_amount' => 'decimal:2',
        'status' => PeaceLinkStatus::class,
        'canceled_by' => CancellationParty::class,
        'otp_generated_at' => 'datetime',
        'otp_expires_at' => 'datetime',
        'otp_verified_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_delivery_at' => 'datetime',
        'approved_at' => 'datetime',
        'dsp_assigned_at' => 'datetime',
        'delivered_at' => 'datetime',
        'canceled_at' => 'datetime',
        'item_quantity' => 'integer',
        'otp_attempts' => 'integer',
        'dsp_reassignment_count' => 'integer',
    ];

    protected $attributes = [
        'status' => PeaceLinkStatus::CREATED,
        'item_quantity' => 1,
        'otp_attempts' => 0,
        'dsp_reassignment_count' => 0,
        'delivery_fee_paid_by' => 'buyer',
        'advance_percentage' => 0,
        'advance_amount' => 0,
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function dsp(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dsp_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(DeliveryPolicy::class, 'policy_id');
    }

    public function sphHold(): HasOne
    {
        return $this->hasOne(SphHold::class, 'peacelink_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(PeaceLinkPayout::class, 'peacelink_id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(\App\Modules\Dispute\Models\Dispute::class, 'peacelink_id');
    }

    // =========================================================================
    // STATE MACHINE GUARDS
    // =========================================================================

    /**
     * Check if PeaceLink can be approved by buyer
     */
    public function canBeApproved(): bool
    {
        return $this->status === PeaceLinkStatus::PENDING_APPROVAL
            && $this->expires_at->isFuture();
    }

    /**
     * Check if DSP can be assigned
     */
    public function canAssignDsp(): bool
    {
        return $this->status === PeaceLinkStatus::SPH_ACTIVE;
    }

    /**
     * Check if DSP can be reassigned
     */
    public function canReassignDsp(): bool
    {
        return $this->status === PeaceLinkStatus::DSP_ASSIGNED
            && !$this->isOtpUsed()
            && $this->dsp_reassignment_count < 1;
    }

    /**
     * Check if delivery can be confirmed
     */
    public function canConfirmDelivery(): bool
    {
        return in_array($this->status, [
            PeaceLinkStatus::DSP_ASSIGNED,
            PeaceLinkStatus::OTP_GENERATED,
        ]) && $this->otp_hash !== null;
    }

    /**
     * Check if PeaceLink can be canceled
     */
    public function canBeCanceled(): bool
    {
        return !in_array($this->status, [
            PeaceLinkStatus::DELIVERED,
            PeaceLinkStatus::CANCELED,
            PeaceLinkStatus::EXPIRED,
            PeaceLinkStatus::RESOLVED,
        ]);
    }

    /**
     * Check if dispute can be opened
     */
    public function canOpenDispute(): bool
    {
        return in_array($this->status, [
            PeaceLinkStatus::SPH_ACTIVE,
            PeaceLinkStatus::DSP_ASSIGNED,
            PeaceLinkStatus::OTP_GENERATED,
            PeaceLinkStatus::DELIVERED,
        ]);
    }

    // =========================================================================
    // STATE HELPERS
    // =========================================================================

    /**
     * Check if DSP is assigned
     */
    public function isDspAssigned(): bool
    {
        return $this->dsp_id !== null;
    }

    /**
     * Check if OTP has been used
     */
    public function isOtpUsed(): bool
    {
        return $this->otp_verified_at !== null;
    }

    /**
     * Check if OTP is expired
     */
    public function isOtpExpired(): bool
    {
        return $this->otp_expires_at && $this->otp_expires_at->isPast();
    }

    /**
     * Check if advance payment was made
     */
    public function hasAdvancePayment(): bool
    {
        return $this->advance_amount > 0;
    }

    /**
     * Check if PeaceLink is active (funds held)
     */
    public function isActive(): bool
    {
        return in_array($this->status, [
            PeaceLinkStatus::SPH_ACTIVE,
            PeaceLinkStatus::DSP_ASSIGNED,
            PeaceLinkStatus::OTP_GENERATED,
            PeaceLinkStatus::DISPUTED,
        ]);
    }

    // =========================================================================
    // OTP METHODS
    // =========================================================================

    /**
     * Validate OTP
     */
    public function validateOtp(string $otp): bool
    {
        if ($this->isOtpExpired()) {
            return false;
        }

        if ($this->otp_attempts >= 5) {
            return false;
        }

        return hash_equals($this->otp_hash, hash('sha256', $otp));
    }

    /**
     * Get visible OTP (only for buyer, only after DSP assigned)
     * In reality, OTP is sent via SMS, but this is for display purposes
     */
    public function getVisibleOtp(): ?string
    {
        // OTP is hashed - we don't store plain text
        // This method exists for API response structure
        // Actual OTP is sent via SMS
        return null;
    }

    // =========================================================================
    // LOGGING METHODS
    // =========================================================================

    /**
     * Log DSP reassignment
     */
    public function logReassignment(string $reason): void
    {
        $metadata = $this->item_metadata ?? [];
        $metadata['reassignment_log'] = $metadata['reassignment_log'] ?? [];
        $metadata['reassignment_log'][] = [
            'previous_dsp_id' => $this->dsp_id,
            'reason' => $reason,
            'timestamp' => now()->toIso8601String(),
        ];
        
        $this->update(['item_metadata' => $metadata]);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeForMerchant($query, string $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeForBuyer($query, string $buyerId)
    {
        return $query->where('buyer_id', $buyerId);
    }

    public function scopeForDsp($query, string $dspId)
    {
        return $query->where('dsp_id', $dspId);
    }

    public function scopeByStatus($query, PeaceLinkStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            PeaceLinkStatus::SPH_ACTIVE,
            PeaceLinkStatus::DSP_ASSIGNED,
            PeaceLinkStatus::OTP_GENERATED,
        ]);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', PeaceLinkStatus::PENDING_APPROVAL)
            ->where('expires_at', '<', now());
    }

    public function scopePendingDelivery($query)
    {
        return $query->whereIn('status', [
            PeaceLinkStatus::DSP_ASSIGNED,
            PeaceLinkStatus::OTP_GENERATED,
        ]);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Get remaining amount after advance
     */
    public function getRemainingAmountAttribute(): float
    {
        return $this->item_amount - $this->advance_amount;
    }

    /**
     * Get total merchant fee (estimated)
     */
    public function getEstimatedMerchantFeeAttribute(): float
    {
        $feeSnapshot = $this->fee_snapshot;
        $advanceFee = $this->advance_amount * ($feeSnapshot['advance_percentage'] ?? 0.005);
        $finalFee = $this->remaining_amount * ($feeSnapshot['merchant_percentage'] ?? 0.005);
        $fixedFee = $feeSnapshot['merchant_fixed'] ?? 2.00;
        
        return round($advanceFee + $finalFee + $fixedFee, 2);
    }

    /**
     * Get estimated merchant payout
     */
    public function getEstimatedMerchantPayoutAttribute(): float
    {
        return $this->item_amount - $this->estimated_merchant_fee;
    }

    /**
     * Check if buyer should see OTP
     * CRITICAL: OTP should ONLY be visible after DSP is assigned
     */
    public function getShouldShowOtpAttribute(): bool
    {
        return $this->isDspAssigned() && 
               in_array($this->status, [
                   PeaceLinkStatus::DSP_ASSIGNED,
                   PeaceLinkStatus::OTP_GENERATED,
               ]);
    }

    /**
     * Get appropriate cancel button label
     * CRITICAL FIX: Should say "Cancel Order" not "Return Item"
     */
    public function getCancelButtonLabelAttribute(): string
    {
        return 'إلغاء الطلب'; // "Cancel Order" in Arabic
    }

    /**
     * Get appropriate cancel button label in English
     */
    public function getCancelButtonLabelEnAttribute(): string
    {
        return 'Cancel Order';
    }
}


// =============================================================================
// app/Modules/PeaceLink/Enums/PeaceLinkStatus.php
// =============================================================================

namespace App\Modules\PeaceLink\Enums;

enum PeaceLinkStatus: string
{
    case CREATED = 'created';
    case PENDING_APPROVAL = 'pending_approval';
    case SPH_ACTIVE = 'sph_active';
    case DSP_ASSIGNED = 'dsp_assigned';
    case OTP_GENERATED = 'otp_generated';
    case DELIVERED = 'delivered';
    case CANCELED = 'canceled';
    case DISPUTED = 'disputed';
    case RESOLVED = 'resolved';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match($this) {
            self::CREATED => 'Created',
            self::PENDING_APPROVAL => 'Pending Approval',
            self::SPH_ACTIVE => 'Payment Held',
            self::DSP_ASSIGNED => 'DSP Assigned',
            self::OTP_GENERATED => 'Ready for Delivery',
            self::DELIVERED => 'Delivered',
            self::CANCELED => 'Canceled',
            self::DISPUTED => 'Disputed',
            self::RESOLVED => 'Resolved',
            self::EXPIRED => 'Expired',
        };
    }

    public function labelAr(): string
    {
        return match($this) {
            self::CREATED => 'تم الإنشاء',
            self::PENDING_APPROVAL => 'في انتظار الموافقة',
            self::SPH_ACTIVE => 'قيد الضمان',
            self::DSP_ASSIGNED => 'تم تعيين المندوب',
            self::OTP_GENERATED => 'جاهز للتسليم',
            self::DELIVERED => 'تم التسليم',
            self::CANCELED => 'ملغي',
            self::DISPUTED => 'نزاع مفتوح',
            self::RESOLVED => 'تم الحل',
            self::EXPIRED => 'منتهي',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::CREATED => '#9E9E9E',
            self::PENDING_APPROVAL => '#F57C00',
            self::SPH_ACTIVE => '#1976D2',
            self::DSP_ASSIGNED => '#7B1FA2',
            self::OTP_GENERATED => '#0288D1',
            self::DELIVERED => '#388E3C',
            self::CANCELED => '#D32F2F',
            self::DISPUTED => '#E64A19',
            self::RESOLVED => '#00796B',
            self::EXPIRED => '#616161',
        };
    }
}


// =============================================================================
// app/Modules/PeaceLink/Enums/CancellationParty.php
// =============================================================================

namespace App\Modules\PeaceLink\Enums;

enum CancellationParty: string
{
    case BUYER = 'buyer';
    case MERCHANT = 'merchant';
    case DSP = 'dsp';
    case ADMIN = 'admin';
    case SYSTEM = 'system';
}


// =============================================================================
// app/Modules/PeaceLink/Enums/PayoutType.php
// =============================================================================

namespace App\Modules\PeaceLink\Enums;

enum PayoutType: string
{
    case ADVANCE = 'advance';
    case FINAL = 'final';
    case DELIVERY_FEE = 'delivery_fee';
    case REFUND = 'refund';
    case PLATFORM_FEE = 'platform_fee';
}
