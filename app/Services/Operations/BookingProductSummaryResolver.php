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
            if ($product === 'hotel') {
                return $this->hotelSummary($booking, $serviceIds);
            }
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

    private function hotelSummary(int $booking, array $serviceIds): array
    {
        $authority = app(GeneralBookingHotelNativeStoreResolver::class)->resolve();
        $table = (string) ($authority['table'] ?? '');
        if ($table === '' || ! Schema::hasTable($table)) return $this->empty();
        $columns = Schema::getColumnListing($table);
        $query = DB::table($table);
        $mode = (string) ($authority['ownership_mode'] ?? '');
        if ($mode === 'direct_booking') {
            $bookingColumn = (string) ($authority['booking_column'] ?? '');
            if ($bookingColumn === '' || ! in_array($bookingColumn, $columns, true)) return $this->empty();
            $query->where($bookingColumn, $booking);
        } elseif ($mode === 'service_link') {
            $serviceColumn = (string) ($authority['service_link_column'] ?? '');
            if ($serviceColumn === '' || ! in_array($serviceColumn, $columns, true) || $serviceIds === []) return $this->empty();
            $query->whereIn($serviceColumn, $serviceIds);
        } else {
            return $this->empty();
        }
        if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
        $rows = $query->get();
        if ($rows->isEmpty()) return $this->empty();
        $sale = 0.0; $cost = 0.0;
        foreach ($rows as $row) {
            $data = (array) $row;
            $meta = $this->hotelMetadata($data, $table, $columns);
            $nights = (int) ($this->firstNumber($data, ['nights', 'total_nights', 'night_count']) ?: ($meta['nights'] ?? 0));
            $rowSale = (float) ($this->hotelMeaningfulNumber($data, ['selling_total','customer_total','sale_total','total_sale','customer_amount','sale_amount','selling_amount','gross_sale']) ?? 0);
            $rowCost = (float) ($this->hotelMeaningfulNumber($data, ['net_supplier_cost','supplier_total','vendor_total','cost_total','total_cost','vendor_amount','cost_amount','supplier_amount','gross_cost']) ?? 0);
            if ($rowSale == 0.0) $rowSale = $this->hotelSemanticNumber($data, $table, $columns, 'customer_total');
            if ($rowCost == 0.0) $rowCost = $this->hotelSemanticNumber($data, $table, $columns, 'vendor_total');
            if ($rowSale == 0.0) $rowSale = (float) ($meta['customer_total'] ?? 0);
            if ($rowCost == 0.0) $rowCost = (float) ($meta['vendor_total'] ?? 0);
            $saleRate = (float) ($this->hotelMeaningfulNumber($data, ['sale_rate','selling_rate','customer_rate','sale_price','selling_price','customer_price','sale','sell_price','room_sale_rate','nightly_sale_rate','selling_price_per_night','customer_price_per_night']) ?? 0);
            $costRate = (float) ($this->hotelMeaningfulNumber($data, ['cost_rate','supplier_rate','vendor_rate','cost_price','supplier_cost','vendor_cost','cost','purchase_price','room_cost_rate','nightly_cost_rate','cost_price_per_night','supplier_price_per_night','vendor_price_per_night']) ?? 0);
            if ($saleRate == 0.0) $saleRate = $this->hotelSemanticNumber($data, $table, $columns, 'sale_rate');
            if ($costRate == 0.0) $costRate = $this->hotelSemanticNumber($data, $table, $columns, 'cost_rate');
            if ($saleRate == 0.0) $saleRate = (float) ($meta['sale_rate'] ?? 0);
            if ($costRate == 0.0) $costRate = (float) ($meta['cost_rate'] ?? 0);
            if ($rowSale == 0.0 && $saleRate != 0.0 && $nights > 0) $rowSale = $saleRate * $nights;
            if ($rowCost == 0.0 && $costRate != 0.0 && $nights > 0) $rowCost = $costRate * $nights;
            $sale += $rowSale; $cost += $rowCost;
        }
        return ['count' => $rows->count(), 'customer_total' => round($sale, 2), 'supplier_total' => round($cost, 2), 'margin' => round($sale - $cost, 2)];
    }

    private function hotelMeaningfulNumber(array $row, array $fields): ?float
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '' || ! is_numeric($row[$field])) continue;
            $value = (float) $row[$field];
            if (abs($value) > 0.000001) return $value;
        }
        return null;
    }

    private function hotelSemanticNumber(array $row, string $table, array $columns, string $kind): float
    {
        $metadata = $this->hotelColumnMetadata($table);
        foreach ($columns as $field) {
            if (! array_key_exists($field, $row) || ! is_numeric($row[$field])) continue;
            $type = strtolower((string) (($metadata[$field]['type_name'] ?? $metadata[$field]['type'] ?? '')));
            if (! preg_match('/int|decimal|numeric|number|double|float|real/', $type)) continue;
            $name = strtolower($field);
            if (preg_match('/(^|_)(id|tax|discount|commission|markup|incentive|margin|profit|other)(_|$)/', $name)) continue;
            $isTotal = str_contains($name, 'total') || str_contains($name, 'amount') || str_contains($name, 'gross') || str_contains($name, 'net');
            $isRate = str_contains($name, 'rate') || str_contains($name, 'price') || str_contains($name, 'nightly') || str_contains($name, 'per_night');
            $saleSide = str_contains($name, 'sale') || str_contains($name, 'sell') || str_contains($name, 'customer');
            $costSide = str_contains($name, 'cost') || str_contains($name, 'purchase') || str_contains($name, 'supplier') || str_contains($name, 'vendor');
            $matches = match ($kind) {
                'customer_total' => $saleSide && $isTotal && ! $isRate,
                'vendor_total' => $costSide && $isTotal && ! $isRate,
                'sale_rate' => $saleSide && $isRate && ! $isTotal,
                'cost_rate' => $costSide && $isRate && ! $isTotal,
                default => false,
            };
            if ($matches && abs((float) $row[$field]) > 0.000001) return (float) $row[$field];
        }
        return 0.0;
    }

    private function hotelColumnMetadata(string $table): array
    {
        $result = [];
        try { foreach ((array) Schema::getColumns($table) as $meta) { $name = (string) ($meta['name'] ?? $meta['column_name'] ?? ''); if ($name !== '') $result[$name] = $meta; } } catch (Throwable) {}
        return $result;
    }

    private function hotelMetadata(array $row, string $table, array $columns): array
    {
        foreach ($this->hotelJsonCarrierFields($table, $columns) as $field) {
            if (! array_key_exists($field, $row)) continue;
            $decoded = is_array($row[$field]) ? $row[$field] : json_decode((string) ($row[$field] ?? ''), true);
            if (is_array($decoded['et_erp_hotel_stay'] ?? null)) return $decoded['et_erp_hotel_stay'];
        }
        foreach ($this->hotelTaggedCarrierFields($table, $columns) as $field) {
            if (! array_key_exists($field, $row)) continue;
            $decoded = $this->hotelTaggedPayload((string) ($row[$field] ?? ''));
            if (is_array($decoded)) return $decoded;
        }
        return [];
    }

    private function hotelJsonCarrierFields(string $table, array $columns): array
    {
        $fields = array_values(array_intersect(['meta','metadata','extra_data','details_json','attributes'], $columns));
        try {
            foreach ((array) Schema::getColumns($table) as $meta) {
                $name = (string) ($meta['name'] ?? $meta['column_name'] ?? '');
                $type = strtolower((string) ($meta['type_name'] ?? $meta['type'] ?? ''));
                if ($name !== '' && in_array($name, $columns, true) && (str_contains($type, 'json') || (preg_match('/meta|json|data|details|attributes/i', $name) && preg_match('/char|text|string|json/', $type)))) $fields[] = $name;
            }
        } catch (Throwable) {}
        return array_values(array_unique($fields));
    }

    private function hotelTaggedCarrierFields(string $table, array $columns): array
    {
        $preferred = ['notes','remarks','internal_notes','description','details','other_details','comment','comments'];
        $fields = [];
        try {
            foreach ((array) Schema::getColumns($table) as $meta) {
                $name = (string) ($meta['name'] ?? $meta['column_name'] ?? '');
                $type = strtolower((string) ($meta['type_name'] ?? $meta['type'] ?? ''));
                if ($name !== '' && in_array($name, $columns, true) && in_array($name, $preferred, true) && preg_match('/char|varchar|text|tinytext|mediumtext|longtext/', $type)) $fields[] = $name;
            }
        } catch (Throwable) { return []; }
        return array_values(array_unique($fields));
    }

    private function hotelTaggedPayload(string $value): ?array
    {
        if (! preg_match('/\[\[ETERP_HOTEL_STAY:([A-Za-z0-9+\/]+=*)\]\]/', $value, $match)) return null;
        $json = base64_decode($match[1], true);
        if ($json === false) return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
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
