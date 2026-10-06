<?php

namespace App\Services\Operations;

/** Context shared by native and supplementary product workspaces. */
final readonly class ProductWorkspaceContext
{
    public function __construct(
        public int $bookingId,
        public string $product,
        public string $billingContext = 'ORIGINAL',
        public ?int $billingBatchId = null,
    ) {
        if (! in_array($this->billingContext, ['ORIGINAL', 'SUPPLEMENTARY'], true)) {
            throw new \InvalidArgumentException('Invalid product workspace billing context.');
        }
        if ($this->billingContext === 'SUPPLEMENTARY' && ($this->billingBatchId ?? 0) <= 0) {
            throw new \InvalidArgumentException('Supplementary product workspaces require a billing batch.');
        }
    }

    public function isSupplementary(): bool
    {
        return $this->billingContext === 'SUPPLEMENTARY';
    }

    public function banner(): ?string
    {
        return $this->isSupplementary() ? 'Additional Services #'.$this->billingBatchId : null;
    }

    public function attributes(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'product' => $this->product,
            'billing_context' => $this->billingContext,
            'billing_batch_id' => $this->billingBatchId,
        ];
    }
}
