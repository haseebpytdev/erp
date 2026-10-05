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
            if ($product === 'transport' && (! $table || ! Schema::hasTable($table))) {
                return $this->transportSnapshotSummary($serviceRows);
            }
            if (! $table || ! Schema::hasTable($table)) return $this->empty();
            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);
            if (in_array('booking_id', $columns, true)) $query->where('booking_id', $booking);
            elseif (in_array('booking_service_id', $columns, true)) $query->whereIn('booking_service_id', $serviceIds ?: [-1]);
            $rows = $query->get();
            if ($product === 'transport' && $rows->isEmpty()) return $this->transportSnapshotSummary($serviceRows);
            if ($product === 'visa') {
                $sale = $this->sum($rows, ['sale_pkr','customer_total','selling_total','sale_total','total_sale','customer_amount','sale_amount','selling_amount','gross_sale']);
                $cost = $this->sum($rows, ['vendor_cost_pkr','supplier_total','vendor_total','cost_total','total_cost','vendor_amount','cost_amount','supplier_amount','gross_cost','net_supplier_cost']);
                $margin = $this->sum($rows, ['margin_pkr']);
                if (abs($margin) <= 0.00001 && ($sale !== 0.0 || $cost !== 0.0)) $margin = $sale - $cost;
            } else {
                $sale = $this->sum($rows, ['sale_amount','selling_total','customer_total','sale_total','total_sale','customer_amount','selling_amount','customer_price','sale_price','selling_price']);
                $cost = $this->sum($rows, ['supplier_amount_pkr','vendor_total_pkr','cost_amount_pkr','supplier_total_pkr','supplier_amount','vendor_total','cost_total','total_cost','supplier_cost','vendor_cost','cost_amount','purchase_price','cost_price']);
                $margin = $sale - $cost;
            }
            return ['count' => $rows->count(), 'customer_total' => round($sale, 2), 'supplier_total' => round($cost, 2), 'margin' => round($margin, 2)];
        } catch (Throwable) { return $this->empty(); }
    }

    private function airSummary(iterable $serviceRows, array $serviceIds): array
    {
        $services = collect($serviceRows)->values();
        if ($services->isEmpty()) return $this->empty();

        $serviceSale = $this->sumAirCustomer($services);
        $serviceCost = $this->sumAirSupplier($services);
        $serviceSalePresent = $this->hasCommercialField($services, ['selling_total','customer_sale','customer_sell','customer_sale_amount','customer_sell_amount','sale_amount','sell_amount','selling_price','sale_price','customer_price','customer_total','receivable_amount']);
        $serviceCostPresent = $this->hasCommercialField($services, ['net_supplier_cost','supplier_cost','supplier_cost_amount','net_cost','purchase_cost','purchase_price','supplier_total','cost_amount']);

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

        $sale = $serviceSalePresent ? $serviceSale : $detailSale;
        $cost = $serviceCostPresent ? $serviceCost : $detailCost;
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

    private function transportSnapshotSummary(iterable $serviceRows): array
    {
        $rows = [];
        foreach ($serviceRows as $service) {
            $data = (array) $service;
            foreach (['meta', 'metadata', 'extra_data', 'details_json', 'attributes', 'notes', 'remarks', 'internal_notes', 'description', 'details', 'other_details', 'comment', 'comments'] as $field) {
                if (! array_key_exists($field, $data)) continue;
                $raw = $data[$field];
                $decoded = is_array($raw) ? $raw : json_decode((string) ($raw ?? ''), true);
                if (is_array($decoded)) {
                    $candidate = $decoded['et_erp_transport_rows']['transports'] ?? ($decoded['transports'] ?? null);
                    if (is_array($candidate)) { $rows = array_merge($rows, array_values(array_filter($candidate, 'is_array'))); continue; }
                }
                $text = (string) ($raw ?? '');
                if (str_contains($text, 'ETERP_TRANSPORT_ROWS')) {
                    $json = trim((string) preg_replace('/^.*?ETERP_TRANSPORT_ROWS\s*/s', '', $text));
                    $decoded = json_decode($json, true);
                    $candidate = is_array($decoded) ? ($decoded['transports'] ?? null) : null;
                    if (is_array($candidate)) $rows = array_merge($rows, array_values(array_filter($candidate, 'is_array')));
                }
            }
        }
        if ($rows === []) return $this->empty();
        $sale = 0.0; $cost = 0.0;
        foreach ($rows as $row) { $sale += (float) ($row['sale_amount'] ?? 0); $cost += (float) ($row['cost_amount'] ?? ($row['cost_rate'] ?? 0)); }
        return ['count' => count($rows), 'customer_total' => round($sale, 2), 'supplier_total' => round($cost, 2), 'margin' => round($sale - $cost, 2)];
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

    private function hasCommercialField(iterable $rows, array $fields): bool
    {
        foreach ($rows as $row) {
            $data = (array) $row;
            foreach ($fields as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '' && is_numeric($data[$field])) return true;
            }
        }
        return false;
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
