<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GeneralBookingAdditionalServiceSnapshotIntegrity
{
    private const MESSAGE = 'The supplementary Draft batch is not commercially consistent.';

    public function foundationReady(): bool
    {
        return Schema::hasTable('general_booking_billing_batches')
            && Schema::hasTable('general_booking_billing_batch_items')
            && Schema::hasTable('general_booking_invoice_links');
    }

    public function build(int $bookingId, int $batchId): array
    {
        if (! $this->foundationReady()) {
            throw new \InvalidArgumentException('Additional Services requires the General Booking Billing database upgrade.');
        }
        $batch = DB::table('general_booking_billing_batches')->where('id', $batchId)->where('booking_id', $bookingId)->first();
        if (! $batch) throw new \InvalidArgumentException('Supplementary batch was not found.');
        $items = DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->orderBy('line_no')->orderBy('id')->get();
        $subtotal = 0.0; $cost = 0.0;
        $canonicalItems = [];
        foreach ($items as $item) {
            if ((int) $item->booking_id !== $bookingId) throw new \InvalidArgumentException(self::MESSAGE);
            if ((string) $item->currency_code !== (string) $batch->currency_code || (float) $item->exchange_rate !== (float) $batch->exchange_rate) throw new \InvalidArgumentException(self::MESSAGE);
            if (! preg_match('/^[a-f0-9]{64}$/i', (string) $item->source_hash)) throw new \InvalidArgumentException('A supplementary item has an invalid source hash.');
            $sale = round((float) $item->sale_amount, 2); $itemCost = round((float) $item->supplier_cost_snapshot, 2);
            if (round((float) $item->margin_snapshot, 2) !== round($sale - $itemCost, 2)) throw new \InvalidArgumentException(self::MESSAGE);
            $subtotal += $sale; $cost += $itemCost;
            $snapshot = json_decode((string) ($item->product_snapshot ?? ''), true);
            if (! is_array($snapshot)) throw new \InvalidArgumentException(self::MESSAGE);
            $canonicalItems[] = $this->sort([
                'line_no' => (int) $item->line_no, 'product_type' => (string) $item->product_type, 'source_key' => (string) $item->source_key,
                'booking_passenger_id' => $item->booking_passenger_id === null ? null : (int) $item->booking_passenger_id,
                'description_snapshot' => $item->description_snapshot, 'quantity' => (float) $item->quantity, 'unit_price' => (float) $item->unit_price,
                'sale_amount' => $sale, 'supplier_cost_snapshot' => $itemCost, 'margin_snapshot' => (float) $item->margin_snapshot,
                'currency_code' => (string) $item->currency_code, 'exchange_rate' => (float) $item->exchange_rate,
                'revenue_mapping_key_snapshot' => $item->revenue_mapping_key_snapshot, 'source_hash' => strtolower((string) $item->source_hash), 'product_snapshot' => $this->sort($snapshot),
            ]);
        }
        $subtotal = round($subtotal, 2); $cost = round($cost, 2); $total = $subtotal;
        $expected = ['customer_subtotal' => $subtotal, 'discount_total' => 0.0, 'customer_total' => $total, 'supplier_cost_total' => $cost, 'agent_commission_total' => 0.0, 'salesperson_commission_total' => 0.0, 'margin_total' => round($total - $cost, 2)];
        foreach ($expected as $field => $value) if (abs((float) $batch->{$field} - $value) > 0.005) throw new \InvalidArgumentException(self::MESSAGE);
        $authority = $this->sort(['booking_id' => $bookingId, 'batch_no' => (int) $batch->batch_no, 'batch_type' => (string) $batch->batch_type, 'currency_code' => (string) $batch->currency_code, 'exchange_rate' => (float) $batch->exchange_rate] + $expected + ['items' => $canonicalItems]);
        return ['hash' => hash('sha256', json_encode($authority, JSON_UNESCAPED_SLASHES)), 'items' => $canonicalItems, 'batch' => (array) $batch, 'totals' => $expected];
    }

    private function sort(array $value): array { foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->sort($item); ksort($value); return $value; }
}
