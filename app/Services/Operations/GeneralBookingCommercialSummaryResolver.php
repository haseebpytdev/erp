<?php

namespace App\Services\Operations;

/**
 * Read-only commercial authority shared by the operational summary and review.
 * Product snapshots are breakdown inputs; persisted booking totals win when
 * they are present, with the established product/discount fallback otherwise.
 */
final class GeneralBookingCommercialSummaryResolver
{
    public function resolve(array $booking, array $air, array $hotel, array $transport, array $visa): array
    {
        $snapshots = compact('air', 'hotel', 'transport', 'visa');
        $customer = [];
        $supplier = [];
        foreach ($snapshots as $key => $snapshot) {
            $summary = (array) ($snapshot['summary'] ?? []);
            $customer[$key] = round(max(0, (float) ($summary['customer_total'] ?? 0)), 2);
            $supplier[$key] = round(max(0, (float) ($this->supplierValue($key, $summary))), 2);
        }

        $grossCustomer = round(array_sum($customer), 2);
        $grossSupplier = round(array_sum($supplier), 2);
        $discount = round(max(0, (float) ($this->first($booking, ['discount_amount', 'discount_value', 'total_discount']) ?: 0)), 2);
        $persistedFinal = $this->numeric($booking, ['booking_value', 'final_sale_total', 'net_total', 'grand_total', 'booking_total']);
        $final = $persistedFinal ?? max(0, $grossCustomer - $discount);
        $persistedSupplier = $this->numeric($booking, ['supplier_cost']);
        $supplierTotal = $persistedSupplier ?? $grossSupplier;
        $currency = strtoupper($this->first($booking, ['currency_code', 'currency', 'booking_currency']) ?: ($air['capabilities']['booking_currency'] ?? $hotel['booking']['currency'] ?? 'PKR'));

        return [
            'product_customer_totals' => $customer,
            'product_supplier_totals' => $supplier,
            'gross_customer_total' => $grossCustomer,
            'gross_supplier_total' => $grossSupplier,
            'discount_value' => $discount,
            'final_booking_value' => round($final, 2),
            'supplier_cost_total' => round($supplierTotal, 2),
            'gross_margin' => round($final - $supplierTotal, 2),
            'currency' => $currency ?: 'PKR',
            'persisted_booking_value' => $persistedFinal,
            'persisted_supplier_cost' => $persistedSupplier,
            // Existing Review template aliases.
            'final_sale_total' => round($final, 2),
            'vendor_cost_total' => round($supplierTotal, 2),
            'discount_type' => $this->first($booking, ['discount_type', 'discount_mode']) ?: 'Amount',
            'agent_commission' => (float) ($this->first($booking, ['agent_commission', 'agent_commission_amount']) ?: 0),
            'salesperson_commission' => (float) ($this->first($booking, ['salesperson_commission', 'sales_commission', 'salesperson_commission_amount']) ?: 0),
        ];
    }

    private function supplierValue(string $product, array $summary): float
    {
        $keys = $product === 'air'
            ? ['supplier_total', 'vendor_total']
            : ['vendor_total', 'supplier_total'];
        $value = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $summary) && $summary[$key] !== null && $summary[$key] !== '') {
                $value = $summary[$key];
                break;
            }
        }
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function numeric(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '' && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }
        return null;
    }

    private function first(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }
}
