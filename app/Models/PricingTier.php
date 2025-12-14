<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'delivery_fixed_charge',
        'delivery_percent_charge',
        'merchant_fixed_charge',
        'merchant_percent_charge',
        'cash_out_fixed_charge',
        'cash_out_percent_charge',
        'status',
    ];

    protected $casts = [
        'delivery_fixed_charge' => 'decimal:8',
        'delivery_percent_charge' => 'decimal:8',
        'merchant_fixed_charge' => 'decimal:8',
        'merchant_percent_charge' => 'decimal:8',
        'cash_out_fixed_charge' => 'decimal:8',
        'cash_out_percent_charge' => 'decimal:8',
        'status' => 'boolean',
    ];

    /**
     * Get the users assigned to this pricing tier
     */
    public function users()
    {
        return $this->hasMany(User::class, 'pricing_tier_id');
    }

    /**
     * Calculate delivery fees for a given amount
     */
    public function calculateDeliveryFees($amount)
    {
        $percentFee = $amount * ($this->delivery_percent_charge / 100);
        $fixedFee = $this->delivery_fixed_charge;
        return $percentFee + $fixedFee;
    }

    /**
     * Calculate merchant fees for a given amount
     */
    public function calculateMerchantFees($amount)
    {
        $percentFee = $amount * ($this->merchant_percent_charge / 100);
        $fixedFee = $this->merchant_fixed_charge;
        return $percentFee + $fixedFee;
    }

    /**
     * Calculate cash out fees for a given amount
     */
    public function calculateCashOutFees($amount)
    {
        $percentFee = $amount * ($this->cash_out_percent_charge / 100);
        $fixedFee = $this->cash_out_fixed_charge;
        return $percentFee + $fixedFee;
    }
}
