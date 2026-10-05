<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Lightweight, read-only product summary authority for the booking hub. */
final class BookingProductSummaryResolver
{
    public function resolve(int $booking): array
    {
        return [
            'air' => $this->summary($booking, 'air'),
            'hotel' => $this->summary($booking, 'hotel'),
            'transport' => $this->summary($booking, 'transport'),
            'visa' => $this->summary($booking, 'visa'),
        ];
    }

    private function summary(int $booking, string $product): array
    {
        try {
            $master = app(NativeProductServiceResolver::class)->{'find'.ucfirst($product)}();
            $serviceRows = Schema::hasTable('booking_services') && $master
                ? DB::table('booking_services')->where('booking_id', $booking)->where('product_service_id', (int) $master['id'])
                    ->where(function ($q): void { $q->whereNull('deleted_at')->orWhere('deleted_at', ''); })
                    ->get()
                : collect();
            $serviceIds = $serviceRows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if ($product === 'air') {
                return $this->airSummary($serviceRows, $serviceIds);
            }
            $table = match ($product) {
                'air' => 'air_ticket_details',
                'hotel' => app(GeneralBookingHotelNativeStoreResolver::class)->resolve()['table'] ?? null,
                'transport' => $this->transportTable(),
                'visa' => 'booking_visa_services',
            };
            if (! $table || ! Schema::hasTable($table)) return $this->empty();
            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);
            if (in_array('booking_id', $columns, true)) $query->where('booking_id', $booking);
            elseif (in_array('booking_service_id', $columns, true)) $query->whereIn('booking_service_id', $serviceIds ?: [-1]);
            $rows = $query->get();
            $sale = $this->sum($rows, ['customer_total','selling_total','sale_total','total_sale','sale_amount']);
            $cost = $this->sum($rows, ['supplier_total','vendor_total','cost_total','total_cost','cost_amount']);
            return ['count' => $rows->count(), 'customer_total' => round($sale, 2), 'supplier_total' => round($cost, 2), 'margin' => round($sale - $cost, 2)];
        } catch (Throwable) { return $this->empty(); }
    }

    private function airSummary(iterable $serviceRows, array $serviceIds): array
    {
        $services = collect($serviceRows)->values();
        if ($services->isEmpty()) return $this->empty();

        $serviceSale = $this->sumAirCustomer($services);
        $serviceCost = $this->sumAirSupplier($services);

        // Persisted booking_services snapshots are the Air commercial authority.
        // Detail rows are only a compatibility fallback for older rows without
        // a service-level commercial snapshot; issued ticket numbers are never
        // used to decide whether the Air product exists.
        $detailSale = 0.0;
        $detailCost = 0.0;
        if (Schema::hasTable('air_ticket_details') && $serviceIds !== []) {
            $columns = Schema::getColumnListing('air_ticket_details');
            $query = DB::table('air_ticket_details');
            if (in_array('booking_service_id', $columns, true)) {
                $query->whereIn('booking_service_id', $serviceIds);
            } else {
                $query->whereRaw('1 = 0');
            }
            if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
            $details = $query->get();
            $detailSale = $this->sumAirCustomer($details);
            $detailCost = $this->sumAirSupplier($details);
        }

        $sale = $serviceSale > 0 ? $serviceSale : $detailSale;
        $cost = $serviceCost > 0 ? $serviceCost : $detailCost;
        return [
            'count' => $services->count(),
            'customer_total' => round($sale, 2),
            'supplier_total' => round($cost, 2),
            'margin' => round($sale - $cost, 2),
        ];
    }

    private function transportTable(): ?string
    {
        foreach (Schema::getTables() as $meta) {
            $name = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
            if ($name !== '' && str_starts_with(strtolower($name), 'booking_') && str_contains(strtolower($name), 'transport')) return $name;
        }
        return null;
    }

    private function sum(iterable $rows, array $fields): float
    {
        $total = 0.0;
        foreach ($rows as $row) foreach ($fields as $field) if (isset($row->{$field}) && is_numeric($row->{$field})) { $total += (float) $row->{$field}; break; }
        return $total;
    }

    private function sumAirCustomer(iterable $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $data = (array) $row;
            $direct = $this->firstMeaningful($data, [
                'selling_total', 'customer_sale', 'customer_sell', 'customer_sale_amount',
                'customer_sell_amount', 'sale_amount', 'sell_amount', 'selling_price',
                'sale_price', 'customer_price', 'customer_total', 'receivable_amount',
            ]);
            if ($direct !== null) {
                $total += $direct;
                continue;
            }
            $total += max(0.0,
                $this->firstNumber($data, ['base_fare', 'basic_fare'])
                + $this->firstNumber($data, ['airline_taxes', 'taxes', 'tax_amount'])
                + $this->firstNumber($data, ['customer_service_fee', 'service_markup', 'service_charge', 'markup'])
                - $this->firstNumber($data, ['customer_discount_amount', 'discount_amount', 'discount'])
            );
        }
        return round($total, 2);
    }

    private function sumAirSupplier(iterable $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $data = (array) $row;
            $direct = $this->firstMeaningful($data, [
                'net_supplier_cost', 'supplier_cost', 'supplier_cost_amount', 'net_cost',
                'purchase_cost', 'purchase_price', 'supplier_total', 'cost_amount',
            ]);
            if ($direct !== null) {
                $total += $direct;
                continue;
            }
            $total += max(0.0,
                $this->firstNumber($data, ['supplier_base_fare', 'base_fare', 'basic_fare'])
                + $this->firstNumber($data, ['supplier_taxes', 'airline_taxes', 'taxes'])
                + $this->firstNumber($data, ['supplier_charges', 'supplier_charge', 'supplier_markup', 'supplier_other_charges', 'supplier_other_charge', 'supplier_other_cost', 'vendor_other_charges', 'vendor_other_charge', 'vendor_other_cost'])
            );
        }
        return round($total, 2);
    }

    private function firstMeaningful(array $row, array $fields): ?float
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '' || ! is_numeric($row[$field])) continue;
            $value = (float) $row[$field];
            if (abs($value) <= 0.00001) continue;
            return $value;
        }
        return null;
    }

    private function firstNumber(array $row, array $fields): float
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '' && is_numeric($row[$field])) return (float) $row[$field];
        }
        return 0.0;
    }

    private function empty(): array { return ['count' => 0, 'customer_total' => 0.0, 'supplier_total' => 0.0, 'margin' => 0.0]; }
}
