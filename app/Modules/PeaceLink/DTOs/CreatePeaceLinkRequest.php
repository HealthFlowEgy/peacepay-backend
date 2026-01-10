<?php

declare(strict_types=1);

namespace App\Modules\PeaceLink\DTOs;

/**
 * Create PeaceLink Request DTO
 * 
 * Contains all data needed to create a new PeaceLink
 */
readonly class CreatePeaceLinkRequest
{
    public function __construct(
        public string $buyerPhone,
        public string $itemDescription,
        public ?string $itemDescriptionAr,
        public float $itemAmount,
        public float $deliveryFee,
        public string $deliveryFeePaidBy, // 'buyer' or 'merchant'
        public int $advancePercentage,
        public ?string $policyId = null,
        public ?array $policySnapshot = null,
        public ?string $categoryId = null,
        public ?array $attachments = null,
    ) {}

    /**
     * Create from array (e.g., validated request data)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            buyerPhone: $data['buyer_phone'],
            itemDescription: $data['item_description'],
            itemDescriptionAr: $data['item_description_ar'] ?? null,
            itemAmount: (float) $data['item_amount'],
            deliveryFee: (float) ($data['delivery_fee'] ?? 0),
            deliveryFeePaidBy: $data['delivery_fee_paid_by'] ?? 'buyer',
            advancePercentage: (int) ($data['advance_percentage'] ?? 0),
            policyId: $data['policy_id'] ?? null,
            policySnapshot: $data['policy_snapshot'] ?? null,
            categoryId: $data['category_id'] ?? null,
            attachments: $data['attachments'] ?? null,
        );
    }

    /**
     * Validate the request data
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->buyerPhone)) {
            $errors['buyer_phone'] = 'Buyer phone is required';
        }

        if (empty($this->itemDescription)) {
            $errors['item_description'] = 'Item description is required';
        }

        if ($this->itemAmount <= 0) {
            $errors['item_amount'] = 'Item amount must be positive';
        }

        if ($this->deliveryFee < 0) {
            $errors['delivery_fee'] = 'Delivery fee cannot be negative';
        }

        if (!in_array($this->deliveryFeePaidBy, ['buyer', 'merchant'])) {
            $errors['delivery_fee_paid_by'] = 'Invalid delivery fee payer';
        }

        if ($this->advancePercentage < 0 || $this->advancePercentage > 100) {
            $errors['advance_percentage'] = 'Advance percentage must be between 0 and 100';
        }

        return $errors;
    }
}
