<?php

namespace App\Services\Operations;

use InvalidArgumentException;

final class GeneralBookingBillingBatchContract
{
    public static function assertBatchSequence(int $batchNo, string $batchType): void
    {
        $type = strtolower(trim($batchType));
        if ($type === 'base' && $batchNo !== 0) {
            throw new InvalidArgumentException('Base billing batches must use batch_no 0.');
        }
        if ($type === 'supplementary' && $batchNo < 1) {
            throw new InvalidArgumentException('Supplementary billing batches must use a positive batch_no.');
        }
        if (! in_array($type, ['base', 'supplementary'], true)) {
            throw new InvalidArgumentException('Unsupported general billing batch type.');
        }
    }

    public static function assertInvoiceSequence(int $sequence, string $linkType): void
    {
        $type = strtolower(trim($linkType));
        if ($type === 'base' && $sequence !== 0) {
            throw new InvalidArgumentException('Base invoice links must use invoice_sequence 0.');
        }
        if ($type === 'supplementary' && $sequence < 1) {
            throw new InvalidArgumentException('Supplementary invoice links must use a positive invoice_sequence.');
        }
        if (! in_array($type, ['base', 'supplementary'], true)) {
            throw new InvalidArgumentException('Unsupported general invoice link type.');
        }
    }

    public static function assertLinkConsistency(int $batchNo, string $batchType, int $sequence, string $linkType): void
    {
        self::assertBatchSequence($batchNo, $batchType);
        self::assertInvoiceSequence($sequence, $linkType);
        if ($batchNo !== $sequence || strtolower(trim($batchType)) !== strtolower(trim($linkType))) {
            throw new InvalidArgumentException('General billing batch and invoice link sequences are inconsistent.');
        }
    }
}
