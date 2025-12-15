<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingTier extends Model
{
    use HasFactory;

    // Tier type constants
    const TYPE_DELIVERY = 'delivery';
    const TYPE_MERCHANT = 'merchant';
    const TYPE_CASH_OUT = 'cash_out';

    protected $fillable = [
        'name',
        'description',
        'type',
        'fixed_charge',
        'percent_charge',
        'status',
    ];

    protected $casts = [
        'fixed_charge' => 'decimal:8',
        'percent_charge' => 'decimal:8',
        'status' => 'boolean',
    ];

    /**
     * Get the users assigned to this pricing tier
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'pricing_tier_user')
                    ->withTimestamps();
    }

    /**
     * Calculate fees for a given amount based on this tier
     */
    public function calculateFees($amount)
    {
        $percentFee = $amount * ($this->percent_charge / 100);
        $fixedFee = $this->fixed_charge;
        return $percentFee + $fixedFee;
    }

    /**
     * Scope to filter by tier type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get active tiers
     */
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * Get tier type label
     */
    public function getTypeLabelAttribute()
    {
        return match($this->type) {
            self::TYPE_DELIVERY => 'Delivery Fees',
            self::TYPE_MERCHANT => 'Merchant Fees',
            self::TYPE_CASH_OUT => 'Cash Out Fees',
            default => ucfirst($this->type),
        };
    }
}
