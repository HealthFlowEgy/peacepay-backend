<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Fee Configuration Model
 * Stores configurable fee rates for the platform
 * Based on Re-Engineering Specification v2.0
 */
class FeeConfiguration extends Model
{
    use HasFactory;

    protected $table = 'fee_configurations';

    protected $fillable = [
        'fee_type',
        'name',
        'name_ar',
        'description',
        'rate',
        'fixed_amount',
        'min_amount',
        'max_amount',
        'currency',
        'is_active',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'fixed_amount' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    /**
     * Get the user who created this configuration
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope for active fees
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>', now());
            });
    }

    /**
     * Scope for specific fee type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('fee_type', $type);
    }

    /**
     * Get current active fee for a type
     */
    public static function getCurrentFee(string $feeType): ?self
    {
        return self::active()
            ->ofType($feeType)
            ->orderBy('effective_from', 'desc')
            ->first();
    }

    /**
     * Calculate fee amount
     */
    public function calculateFee(float $amount): float
    {
        $percentageFee = $amount * $this->rate;
        $totalFee = $percentageFee + $this->fixed_amount;

        if ($this->min_amount && $totalFee < $this->min_amount) {
            $totalFee = $this->min_amount;
        }

        if ($this->max_amount && $totalFee > $this->max_amount) {
            $totalFee = $this->max_amount;
        }

        return round($totalFee, 2);
    }
}
