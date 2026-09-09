<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Read-only profitability projection for a native Sales Invoice snapshot.
 * Sale always comes from invoice lines/header; product costs are resolved from
 * the source booking stores and never participate in invoice accounting.
 */
final class SalesInvoiceProductCommercialSummaryResolver
{
    /** @return array<string,mixed> */
    public function resolve(Model $invoice, int $bookingId): array
    {
        $invoiceTotal = $this->invoiceTotal($invoice);
        $serviceMap = $this->bookingServices($bookingId);
        $products = [];

        foreach ($this->invoiceLines($invoice) as $line) {
            $serviceId = $this->firstPositiveInt($line, ['source_booking_service_id', 'booking_service_id']);
            $service = $serviceId > 0 ? ($serviceMap[$serviceId] ?? []) : [];
            $productId = $this->firstPositiveInt($line, ['product_service_id'])
                ?: (int) ($service['product_service_id'] ?? 0);
            $identity = $this->productIdentity($productId, $line, $service);
            $key = $identity['key'];
            if (! isset($products[$key])) {
                $products[$key] = $identity + [
                    'product_service_id' => $productId ?: null,
                    'sale_total' => 0.0,
                    'cost_total' => null,
                    'cost_resolved' => false,
                    'margin' => null,
                    'margin_resolved' => false,
                ];
            }
            $products[$key]['sale_total'] += $this->lineTotal($line);
        }

        $costs = [
            'air' => $this->airCost($bookingId),
            'hotel' => $this->hotelCost($bookingId, $serviceMap),
            'transport' => $this->transportCost($bookingId, $serviceMap),
            'visa' => $this->visaCost($bookingId),
        ];
        foreach ($products as $key => &$product) {
            $product['sale_total'] = round((float) $product['sale_total'], 2);
            $cost = $costs[$key] ?? ['resolved' => false, 'total' => null];
            $product['cost_resolved'] = (bool) ($cost['resolved'] ?? false);
            $product['cost_total'] = $product['cost_resolved'] ? round((float) $cost['total'], 2) : null;
            $product['margin_resolved'] = $product['cost_resolved'];
            $product['margin'] = $product['cost_resolved']
                ? round((float) $product['sale_total'] - (float) $product['cost_total'], 2)
                : null;
        }
        unset($product);

        uasort($products, static fn (array $a, array $b): int => $a['display_order'] <=> $b['display_order']);
        $products = array_values($products);
        $productSaleTotal = round(array_sum(array_column($products, 'sale_total')), 2);
        $costComplete = $products !== [] && ! collect($products)->contains(
            static fn (array $product): bool => ! $product['cost_resolved']
        );
        $totalCost = $costComplete ? round(array_sum(array_column($products, 'cost_total')), 2) : null;
        $grossMargin = $costComplete ? round($invoiceTotal - (float) $totalCost, 2) : null;
        $passengers = $this->passengerSummary($bookingId);

        return [
            'products' => $products,
            'invoice_sale_total' => $invoiceTotal,
            'product_sale_total' => $productSaleTotal,
            'sale_reconciles' => abs($productSaleTotal - $invoiceTotal) < 0.01,
            'total_cost' => $totalCost,
            'total_cost_complete' => $costComplete,
            'gross_margin' => $grossMargin,
            'gross_margin_complete' => $costComplete,
            'product_count' => count($products),
            'passenger_count' => $passengers['total'],
            'passenger_mix' => $passengers['mix'],
        ];
    }

    /** @return array{total:int,mix:array{ADULT:int,CHILD:int,INFANT:int}} */
    private function passengerSummary(int $bookingId): array
    {
        $mix = ['ADULT' => 0, 'CHILD' => 0, 'INFANT' => 0];
        if ($bookingId <= 0 || ! Schema::hasTable('booking_passengers')) return ['total' => 0, 'mix' => $mix];
        try {
            $columns = Schema::getColumnListing('booking_passengers');
            if (! in_array('booking_id', $columns, true)) return ['total' => 0, 'mix' => $mix];
            $fareColumn = $this->firstColumn($columns, ['fare_as', 'fare_type', 'passenger_type', 'pax_type', 'age_type']);
            $query = DB::table('booking_passengers')->where('booking_id', $bookingId);
            if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
            if (in_array('is_active', $columns, true)) $query->where('is_active', true);
            $rows = $query->get();
            foreach ($rows as $row) {
                $fare = strtoupper(trim((string) ($fareColumn ? ($row->{$fareColumn} ?? '') : '')));
                $key = str_contains($fare, 'INF') ? 'INFANT' : ((str_contains($fare, 'CHD') || str_contains($fare, 'CHILD')) ? 'CHILD' : 'ADULT');
                $mix[$key]++;
            }
            return ['total' => $rows->count(), 'mix' => $mix];
        } catch (Throwable) {
            return ['total' => 0, 'mix' => $mix];
        }
    }

    private function invoiceTotal(Model $invoice): float
    {
        foreach (['grand_total', 'total_amount', 'net_total', 'invoice_total', 'total', 'amount'] as $field) {
            $value = $invoice->getAttribute($field);
            if ($value !== null && $value !== '' && is_numeric($value)) return round((float) $value, 2);
        }
        return round(array_sum(array_map(fn (array $line): float => $this->lineTotal($line), $this->invoiceLines($invoice))), 2);
    }

    /** @return list<array<string,mixed>> */
    private function invoiceLines(Model $invoice): array
    {
        $scores = [];
        try {
            foreach ((new ReflectionClass($invoice))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getNumberOfRequiredParameters() > 0) continue;
                $name = $method->getName();
                $lower = strtolower($name);
                $score = match ($lower) {
                    'invoiceitems' => 3200, 'items', 'invoicelines' => 3000,
                    'lines' => 2800, 'details' => 2200, default => 0,
                };
                if ($score === 0 && str_contains($lower, 'item')) $score = 1000;
                if ($score === 0 && str_contains($lower, 'line')) $score = 900;
                if ($score > 0) $scores[$name] = $score;
            }
        } catch (Throwable) {
        }
        arsort($scores);
        foreach (array_keys($scores) as $name) {
            try {
                $relation = $invoice->{$name}();
                if (! $relation instanceof Relation) continue;
                return $relation->get()->map(
                    static fn (Model $line): array => $line->getAttributes()
                )->values()->all();
            } catch (Throwable) {
            }
        }
        return $this->physicalInvoiceLines($invoice);
    }

    /** @return list<array<string,mixed>> */
    private function physicalInvoiceLines(Model $invoice): array
    {
        $candidates = [];
        try {
            foreach (Schema::getTables() as $metadata) {
                $table = is_array($metadata) ? (string) ($metadata['name'] ?? $metadata['table_name'] ?? '') : '';
                $lower = strtolower($table);
                if ($table === '' || ! str_contains($lower, 'invoice') || (! str_contains($lower, 'line') && ! str_contains($lower, 'item') && ! str_contains($lower, 'detail'))) continue;
                $columns = Schema::getColumnListing($table);
                $amount = $this->firstColumn($columns, ['line_total', 'net_amount', 'total_amount', 'amount', 'total']);
                if (! $amount) continue;
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    $target = (string) ($foreign['foreign_table'] ?? $foreign['foreign_table_name'] ?? $foreign['table'] ?? '');
                    if ($target !== $invoice->getTable()) continue;
                    $local = (string) (((array) ($foreign['columns'] ?? $foreign['local_columns'] ?? []))[0] ?? '');
                    if ($local === '' || ! in_array($local, $columns, true)) continue;
                    $rows = DB::table($table)->where($local, $invoice->getKey())->get()
                        ->map(static fn (object $row): array => (array) $row)->all();
                    if (! $rows) continue;
                    $score = 100;
                    if (in_array('product_service_id', $columns, true)) $score += 80;
                    if (in_array('source_booking_service_id', $columns, true) || in_array('booking_service_id', $columns, true)) $score += 70;
                    $candidates[] = ['score' => $score, 'rows' => $rows];
                }
            }
        } catch (Throwable) {
        }
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return $candidates[0]['rows'] ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    private function bookingServices(int $bookingId): array
    {
        if ($bookingId <= 0 || ! Schema::hasTable('booking_services')) return [];
        try {
            return DB::table('booking_services')->where('booking_id', $bookingId)->get()
                ->mapWithKeys(static fn (object $row): array => [(int) $row->id => (array) $row])->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array{key:string,product_name:string,icon_key:string,display_order:int} */
    private function productIdentity(int $productId, array $line, array $service): array
    {
        $fixed = [
            1 => ['key' => 'air', 'product_name' => 'Air Ticket', 'icon_key' => 'plane', 'display_order' => 10],
            3 => ['key' => 'hotel', 'product_name' => 'Hotel', 'icon_key' => 'building', 'display_order' => 20],
            4 => ['key' => 'transport', 'product_name' => 'Transport', 'icon_key' => 'bus', 'display_order' => 30],
        ];
        if (isset($fixed[$productId])) return $fixed[$productId];
        $text = strtolower(implode(' ', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $line + $service)));
        if (preg_match('/(^|[^a-z0-9])visa([^a-z0-9]|$)/i', $text)) {
            return ['key' => 'visa', 'product_name' => 'Visa', 'icon_key' => 'file-description', 'display_order' => 40];
        }
        $name = trim((string) ($line['description'] ?? $line['service_name'] ?? $service['service_name'] ?? 'Product'));
        return [
            'key' => 'product_'.($productId ?: substr(sha1($name), 0, 8)),
            'product_name' => $name !== '' ? $name : 'Product',
            'icon_key' => 'package',
            'display_order' => 100 + $productId,
        ];
    }

    private function lineTotal(array $line): float
    {
        foreach (['line_total', 'net_amount', 'total_amount', 'amount', 'total'] as $field) {
            if (array_key_exists($field, $line) && $line[$field] !== null && $line[$field] !== '' && is_numeric($line[$field])) {
                return round((float) $line[$field], 2);
            }
        }
        $quantity = $this->firstNumber($line, ['quantity', 'qty'], 1.0);
        $rate = $this->firstNumber($line, ['unit_price', 'rate', 'price', 'sale_price', 'selling_price'], 0.0);
        $discount = $this->firstNumber($line, ['discount_amount', 'discount'], 0.0);
        return round(($quantity * $rate) - $discount, 2);
    }

    /** @return array{resolved:bool,total:?float} */
    private function airCost(int $bookingId): array
    {
        if ($bookingId <= 0 || ! Schema::hasTable('air_ticket_details') || ! Schema::hasTable('booking_services')) return $this->unresolved();
        try {
            $columns = Schema::getColumnListing('air_ticket_details');
            if (! in_array('booking_service_id', $columns, true) || ! in_array('net_supplier_cost', $columns, true)) return $this->unresolved();
            $serviceIds = DB::table('booking_services')->where('booking_id', $bookingId)->where('product_service_id', 1)->pluck('id')->all();
            if (! $serviceIds) return $this->unresolved();
            $query = DB::table('air_ticket_details')->whereIn('booking_service_id', $serviceIds);
            if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
            $rows = $query->get();
            if ($rows->isEmpty() || $rows->contains(static fn (object $row): bool => $row->net_supplier_cost === null || $row->net_supplier_cost === '')) return $this->unresolved();
            return $this->resolved((float) $rows->sum(static fn (object $row): float => (float) $row->net_supplier_cost));
        } catch (Throwable) {
            return $this->unresolved();
        }
    }

    /** @param array<int,array<string,mixed>> $services @return array{resolved:bool,total:?float} */
    private function hotelCost(int $bookingId, array $services): array
    {
        $service = $this->activeProductService($services, 3);
        if (! $service) return $this->unresolved();
        $rows = $this->snapshotRows($service, 'hotel');
        if (! $rows) $rows = $this->physicalHotelRows($bookingId, (int) $service['id']);
        if (! $rows) return $this->unresolved();
        $total = 0.0;
        foreach ($rows as $row) {
            if (array_key_exists('vendor_total', $row) && is_numeric($row['vendor_total'])) {
                $total += (float) $row['vendor_total'];
                continue;
            }
            if (! is_numeric($row['cost_rate'] ?? null) || (int) ($row['nights'] ?? 0) < 1) return $this->unresolved();
            $total += (float) $row['cost_rate'] * (int) $row['nights'];
        }
        return $this->resolved($total);
    }

    /** @param array<int,array<string,mixed>> $services @return array{resolved:bool,total:?float} */
    private function transportCost(int $bookingId, array $services): array
    {
        $service = $this->activeProductService($services, 4);
        if (! $service) return $this->unresolved();
        $rows = $this->snapshotRows($service, 'transport');
        if (! $rows) $rows = $this->physicalTransportRows($bookingId, (int) $service['id']);
        if (! $rows || collect($rows)->contains(static fn (array $row): bool => ! array_key_exists('cost_amount', $row) || ! is_numeric($row['cost_amount']))) return $this->unresolved();
        return $this->resolved(array_sum(array_map(static fn (array $row): float => (float) $row['cost_amount'], $rows)));
    }

    /** @return array{resolved:bool,total:?float} */
    private function visaCost(int $bookingId): array
    {
        if ($bookingId <= 0 || ! Schema::hasTable('booking_visa_services')) return $this->unresolved();
        try {
            $columns = Schema::getColumnListing('booking_visa_services');
            if (! in_array('booking_id', $columns, true) || ! in_array('vendor_cost_pkr', $columns, true)) return $this->unresolved();
            $query = DB::table('booking_visa_services')->where('booking_id', $bookingId);
            if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
            $rows = $query->get();
            if ($rows->isEmpty() || $rows->contains(static fn (object $row): bool => $row->vendor_cost_pkr === null || $row->vendor_cost_pkr === '')) return $this->unresolved();
            return $this->resolved((float) $rows->sum(static fn (object $row): float => (float) $row->vendor_cost_pkr));
        } catch (Throwable) {
            return $this->unresolved();
        }
    }

    /** @param array<int,array<string,mixed>> $services */
    private function activeProductService(array $services, int $productId): ?array
    {
        $matches = array_values(array_filter($services, fn (array $row): bool => (int) ($row['product_service_id'] ?? 0) === $productId && $this->isActive($row)));
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return list<array<string,mixed>> */
    private function snapshotRows(array $service, string $product): array
    {
        $jsonKey = $product === 'hotel' ? 'et_erp_hotel_stays' : 'et_erp_transport_rows';
        $rowKey = $product === 'hotel' ? 'stays' : 'transports';
        $tag = $product === 'hotel' ? 'ETERP_HOTEL_STAYS' : 'ETERP_TRANSPORT_ROWS';
        foreach (['meta', 'metadata', 'extra_data', 'details_json', 'attributes'] as $field) {
            if (! array_key_exists($field, $service)) continue;
            $decoded = is_array($service[$field]) ? $service[$field] : json_decode((string) ($service[$field] ?? ''), true);
            $rows = is_array($decoded) ? ($decoded[$jsonKey][$rowKey] ?? null) : null;
            if (is_array($rows) && $rows) return array_values(array_filter($rows, 'is_array'));
        }
        foreach (['notes', 'remarks', 'internal_notes', 'description', 'details', 'other_details', 'comment', 'comments'] as $field) {
            if (! array_key_exists($field, $service)) continue;
            if (! preg_match('/\[\['.preg_quote($tag, '/').':([A-Za-z0-9+\/=]+)\]\]/', (string) ($service[$field] ?? ''), $match)) continue;
            $json = base64_decode((string) ($match[1] ?? ''), true);
            $decoded = $json === false ? null : json_decode($json, true);
            $rows = is_array($decoded) ? ($decoded[$rowKey] ?? null) : null;
            if (is_array($rows) && $rows) return array_values(array_filter($rows, 'is_array'));
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function physicalHotelRows(int $bookingId, int $serviceId): array
    {
        foreach (['booking_hotel_stays', 'booking_hotels', 'hotel_stays', 'booking_hotel_details', 'booking_accommodations', 'hotel_booking_details'] as $table) {
            $rows = $this->physicalRows($table, $bookingId, $serviceId);
            if (! $rows) continue;
            $columns = Schema::getColumnListing($table);
            return array_map(function (array $row) use ($columns): array {
                return [
                    'vendor_total' => $this->firstNullableNumber($row, $columns, ['vendor_total', 'supplier_total', 'cost_total', 'total_cost', 'vendor_amount', 'cost_amount', 'supplier_amount']),
                    'cost_rate' => $this->firstNullableNumber($row, $columns, ['cost_rate', 'supplier_rate', 'vendor_rate', 'cost_price', 'supplier_cost', 'vendor_cost', 'cost', 'purchase_price']),
                    'nights' => (int) ($this->firstValue($row, $columns, ['nights', 'total_nights', 'night_count']) ?? 0),
                ];
            }, $rows);
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function physicalTransportRows(int $bookingId, int $serviceId): array
    {
        foreach (['booking_transport_segments', 'booking_transports', 'transport_booking_details', 'booking_transport_details'] as $table) {
            $rows = $this->physicalRows($table, $bookingId, $serviceId);
            if (! $rows) continue;
            $columns = Schema::getColumnListing($table);
            return array_map(fn (array $row): array => [
                'cost_amount' => $this->firstNullableNumber($row, $columns, ['supplier_amount_pkr', 'vendor_total_pkr', 'cost_amount_pkr', 'supplier_total_pkr', 'cost_amount', 'vendor_total', 'cost_total', 'total_cost']),
            ], $rows);
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function physicalRows(string $table, int $bookingId, int $serviceId): array
    {
        if (! Schema::hasTable($table)) return [];
        try {
            $columns = Schema::getColumnListing($table);
            if (! in_array('booking_id', $columns, true)) return [];
            $query = DB::table($table)->where('booking_id', $bookingId);
            if (in_array('booking_service_id', $columns, true)) $query->where('booking_service_id', $serviceId);
            if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
            return $query->get()->map(static fn (object $row): array => (array) $row)->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function isActive(array $row): bool
    {
        if (! empty($row['deleted_at'])) return false;
        if (array_key_exists('is_active', $row) && ! (bool) $row['is_active']) return false;
        if (array_key_exists('active', $row) && ! (bool) $row['active']) return false;
        return ! in_array(strtolower(trim((string) ($row['status'] ?? ''))), ['inactive', 'deleted', 'removed', 'cancelled', 'canceled'], true);
    }

    private function firstPositiveInt(array $row, array $fields): int
    {
        foreach ($fields as $field) {
            $value = (int) ($row[$field] ?? 0);
            if ($value > 0) return $value;
        }
        return 0;
    }

    private function firstNumber(array $row, array $fields, float $default): float
    {
        foreach ($fields as $field) if (array_key_exists($field, $row) && is_numeric($row[$field])) return (float) $row[$field];
        return $default;
    }

    private function firstNullableNumber(array $row, array $columns, array $fields): ?float
    {
        foreach ($fields as $field) {
            if (! in_array($field, $columns, true) || ! array_key_exists($field, $row)) continue;
            if ($row[$field] !== null && $row[$field] !== '' && is_numeric($row[$field])) return (float) $row[$field];
        }
        return null;
    }

    private function firstValue(array $row, array $columns, array $fields): mixed
    {
        foreach ($fields as $field) if (in_array($field, $columns, true) && array_key_exists($field, $row)) return $row[$field];
        return null;
    }

    private function firstColumn(array $columns, array $fields): ?string
    {
        foreach ($fields as $field) if (in_array($field, $columns, true)) return $field;
        return null;
    }

    /** @return array{resolved:bool,total:float} */
    private function resolved(float $total): array
    {
        return ['resolved' => true, 'total' => round($total, 2)];
    }

    /** @return array{resolved:bool,total:null} */
    private function unresolved(): array
    {
        return ['resolved' => false, 'total' => null];
    }
}
