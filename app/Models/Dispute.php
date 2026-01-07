<?php

namespace App\Models;

use App\Constants\EscrowConstants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Dispute Model
 * Handles dispute resolution for PeaceLink transactions
 * Based on Re-Engineering Specification v2.0
 */
class Dispute extends Model
{
    use HasFactory;

    protected $table = 'disputes';

    protected $fillable = [
        'dispute_id',
        'escrow_id',
        'opened_by',
        'opened_by_role',
        'status',
        'reason',
        'reason_ar',
        'evidence_urls',
        'resolved_by',
        'resolved_at',
        'resolution_type',
        'resolution_notes',
        'buyer_amount',
        'merchant_amount',
        'dsp_amount',
    ];

    protected $casts = [
        'evidence_urls' => 'array',
        'resolved_at' => 'datetime',
        'buyer_amount' => 'decimal:2',
        'merchant_amount' => 'decimal:2',
        'dsp_amount' => 'decimal:2',
    ];

    /**
     * Boot method to generate UUID
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->dispute_id) {
                $model->dispute_id = Str::uuid();
            }
        });
    }

    /**
     * Get the escrow associated with this dispute
     */
    public function escrow()
    {
        return $this->belongsTo(Escrow::class);
    }

    /**
     * Get the user who opened the dispute
     */
    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * Get the admin who resolved the dispute
     */
    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Get dispute messages
     */
    public function messages()
    {
        return $this->hasMany(DisputeMessage::class);
    }

    /**
     * Scope for open disputes
     */
    public function scopeOpen($query)
    {
        return $query->where('status', EscrowConstants::DISPUTE_OPEN);
    }

    /**
     * Scope for disputes under review
     */
    public function scopeUnderReview($query)
    {
        return $query->where('status', EscrowConstants::DISPUTE_UNDER_REVIEW);
    }

    /**
     * Scope for resolved disputes
     */
    public function scopeResolved($query)
    {
        return $query->whereIn('status', [
            EscrowConstants::DISPUTE_RESOLVED_BUYER,
            EscrowConstants::DISPUTE_RESOLVED_MERCHANT,
            EscrowConstants::DISPUTE_RESOLVED_SPLIT,
        ]);
    }

    /**
     * Check if dispute is open
     */
    public function isOpen(): bool
    {
        return $this->status === EscrowConstants::DISPUTE_OPEN;
    }

    /**
     * Check if dispute is resolved
     */
    public function isResolved(): bool
    {
        return in_array($this->status, [
            EscrowConstants::DISPUTE_RESOLVED_BUYER,
            EscrowConstants::DISPUTE_RESOLVED_MERCHANT,
            EscrowConstants::DISPUTE_RESOLVED_SPLIT,
        ]);
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        $labels = [
            EscrowConstants::DISPUTE_OPEN => 'Open',
            EscrowConstants::DISPUTE_UNDER_REVIEW => 'Under Review',
            EscrowConstants::DISPUTE_RESOLVED_BUYER => 'Resolved (Buyer)',
            EscrowConstants::DISPUTE_RESOLVED_MERCHANT => 'Resolved (Merchant)',
            EscrowConstants::DISPUTE_RESOLVED_SPLIT => 'Resolved (Split)',
        ];

        return $labels[$this->status] ?? 'Unknown';
    }

    /**
     * Get Arabic status label
     */
    public function getStatusLabelArAttribute(): string
    {
        $labels = [
            EscrowConstants::DISPUTE_OPEN => 'مفتوح',
            EscrowConstants::DISPUTE_UNDER_REVIEW => 'قيد المراجعة',
            EscrowConstants::DISPUTE_RESOLVED_BUYER => 'تم الحل (لصالح المشتري)',
            EscrowConstants::DISPUTE_RESOLVED_MERCHANT => 'تم الحل (لصالح التاجر)',
            EscrowConstants::DISPUTE_RESOLVED_SPLIT => 'تم الحل (تقسيم)',
        ];

        return $labels[$this->status] ?? 'غير معروف';
    }
}
