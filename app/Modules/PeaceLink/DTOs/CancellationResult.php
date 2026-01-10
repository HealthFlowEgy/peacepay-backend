<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\DTOs;

/**
 * Cancellation Result DTO
 * 
 * Contains the financial breakdown of a cancellation
 */
readonly class CancellationResult
{
    public function __construct(
        public float $refundToBuyer,
        public float $dspPayout,
        public float $merchantPayout,
        public float $platformFee,
        public string $message,
        public ?string $messageAr = null,
    ) {}

    /**
     * Get total amount distributed
     */
    public function totalDistributed(): float
    {
        return $this->refundToBuyer + $this->dspPayout + abs($this->merchantPayout) + $this->platformFee;
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        return [
            'refund_to_buyer' => $this->refundToBuyer,
            'dsp_payout' => $this->dspPayout,
            'merchant_payout' => $this->merchantPayout,
            'platform_fee' => $this->platformFee,
            'message' => $this->message,
            'message_ar' => $this->messageAr,
        ];
    }
}
