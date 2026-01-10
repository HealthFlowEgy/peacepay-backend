<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform Wallet Model
 * Stores PeacePay platform profits
 * Based on Re-Engineering Specification v2.0
 */
class PlatformWallet extends Model
{
    use HasFactory;

    protected $table = 'platform_wallets';

    protected $fillable = [
        'name',
        'balance',
        'currency',
        'version',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'version' => 'integer',
    ];

    /**
     * Get the profit wallet
     */
    public static function getProfitWallet(): self
    {
        return self::firstOrCreate(
            ['name' => 'peacepay_profit'],
            ['balance' => 0, 'currency' => 'EGP']
        );
    }

    /**
     * Add to balance with optimistic locking
     */
    public function addBalance(float $amount): bool
    {
        $currentVersion = $this->version;
        
        $updated = self::where('id', $this->id)
            ->where('version', $currentVersion)
            ->update([
                'balance' => $this->balance + $amount,
                'version' => $currentVersion + 1,
            ]);

        if ($updated) {
            $this->refresh();
            return true;
        }

        return false;
    }

    /**
     * Get ledger entries for this wallet
     */
    public function ledgerEntries()
    {
        return LedgerEntry::where('platform_wallet_name', $this->name);
    }

    /**
     * Get total profit for a date range
     */
    public static function getTotalProfit(?string $startDate = null, ?string $endDate = null): float
    {
        $query = LedgerEntry::where('platform_wallet_name', 'peacepay_profit');

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('created_at', '<=', $endDate);
        }

        return $query->sum('amount');
    }

    /**
     * Get daily profit summary
     */
    public static function getDailyProfit(int $days = 30): array
    {
        return LedgerEntry::where('platform_wallet_name', 'peacepay_profit')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get()
            ->toArray();
    }
}
