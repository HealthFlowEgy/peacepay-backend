<?php
/**
 * Fee Configuration Service - Backend Implementation
 * Supports dynamic fee management without code changes
 */

namespace App\Services;

use App\Models\FeeConfig;
use Illuminate\Support\Facades\Cache;

class FeeConfigService
{
    const CACHE_KEY = 'fee_configs';
    const CACHE_TTL = 3600; // 1 hour

    /**
     * Get all active fee configurations
     */
    public function getAllConfigs(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return FeeConfig::where('is_active', true)->get()->toArray();
        });
    }

    /**
     * Get specific fee config by type
     */
    public function getConfig(string $type): ?array
    {
        $configs = $this->getAllConfigs();
        return collect($configs)->firstWhere('type', $type);
    }

    /**
     * Calculate fee for a given amount and type
     */
    public function calculateFee(string $type, float $amount): array
    {
        $config = $this->getConfig($type);
        
        if (!$config) {
            throw new \Exception("Fee configuration not found for type: {$type}");
        }

        $percentageFee = $amount * ($config['percentage_fee'] / 100);
        $fixedFee = $config['fixed_fee'];
        $totalFee = $percentageFee + $fixedFee;

        // Apply min/max limits
        if (isset($config['min_fee']) && $totalFee < $config['min_fee']) {
            $totalFee = $config['min_fee'];
        }
        if (isset($config['max_fee']) && $totalFee > $config['max_fee']) {
            $totalFee = $config['max_fee'];
        }

        return [
            'base_amount' => $amount,
            'percentage_fee' => $percentageFee,
            'fixed_fee' => $fixedFee,
            'total_fee' => $totalFee,
            'net_amount' => $amount - $totalFee,
            'config_id' => $config['id'],
        ];
    }

    /**
     * Update fee configuration
     */
    public function updateConfig(string $id, array $data): FeeConfig
    {
        $config = FeeConfig::findOrFail($id);
        
        // Log the change for audit
        FeeConfigHistory::create([
            'fee_config_id' => $config->id,
            'old_values' => $config->toArray(),
            'new_values' => $data,
            'changed_by' => auth()->id(),
        ]);

        $config->update($data);
        
        // Clear cache
        Cache::forget(self::CACHE_KEY);
        
        return $config->fresh();
    }

    /**
     * Get merchant PeaceLink fee
     */
    public function getMerchantPeaceLinkFee(float $itemPrice): array
    {
        return $this->calculateFee('peacelink_merchant', $itemPrice);
    }

    /**
     * Get DSP PeaceLink fee
     */
    public function getDspPeaceLinkFee(float $deliveryFee): array
    {
        return $this->calculateFee('peacelink_dsp', $deliveryFee);
    }

    /**
     * Get advance payment fee
     */
    public function getAdvancePaymentFee(float $advanceAmount): array
    {
        return $this->calculateFee('advance_payment', $advanceAmount);
    }

    /**
     * Get cashout fee
     */
    public function getCashoutFee(float $amount): array
    {
        return $this->calculateFee('cashout', $amount);
    }
}
