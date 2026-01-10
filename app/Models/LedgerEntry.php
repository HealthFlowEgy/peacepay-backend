<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ledger Entry Model
 * Append-only audit log for all financial transactions
 * Based on Re-Engineering Specification v2.0
 */
class LedgerEntry extends Model
{
    use HasFactory;

    protected $table = 'ledger_entries';
    
    public $timestamps = false;
    
    protected $fillable = [
        'entry_id',
        'escrow_id',
        'debit_wallet_id',
        'credit_wallet_id',
        'platform_wallet_name',
        'amount',
        'entry_type',
        'description',
        'metadata',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the escrow associated with this entry
     */
    public function escrow()
    {
        return $this->belongsTo(Escrow::class);
    }

    /**
     * Get the debit wallet
     */
    public function debitWallet()
    {
        return $this->belongsTo(UserWallet::class, 'debit_wallet_id');
    }

    /**
     * Get the credit wallet
     */
    public function creditWallet()
    {
        return $this->belongsTo(UserWallet::class, 'credit_wallet_id');
    }

    /**
     * Scope for entries by escrow
     */
    public function scopeForEscrow($query, $escrowId)
    {
        return $query->where('escrow_id', $escrowId);
    }

    /**
     * Scope for entries by type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('entry_type', $type);
    }

    /**
     * Scope for platform fees
     */
    public function scopePlatformFees($query)
    {
        return $query->whereNotNull('platform_wallet_name');
    }

    /**
     * Boot method to prevent updates and deletes
     */
    protected static function boot()
    {
        parent::boot();

        // Prevent updates
        static::updating(function ($model) {
            throw new \Exception('Ledger entries cannot be modified');
        });

        // Prevent deletes
        static::deleting(function ($model) {
            throw new \Exception('Ledger entries cannot be deleted');
        });
    }
}
