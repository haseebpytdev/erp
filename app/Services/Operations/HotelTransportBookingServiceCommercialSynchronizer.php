<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Writes the host-native booking_services commercial contract for the two
 * PER_SERVICE products whose detailed rows remain their amount authority.
 */
final class HotelTransportBookingServiceCommercialSynchronizer
{
    private const HOTEL_PRODUCT_SERVICE_ID = 3;
    private const TRANSPORT_PRODUCT_SERVICE_ID = 4;

    /** @return array<string,mixed> */
    public function syncHotelSummary(int $serviceId, float $customerTotal): array
    {
        return $this->writeNativeCommercial(
            $this->lockedServiceById($serviceId, self::HOTEL_PRODUCT_SERVICE_ID, 'Hotel'),
            $customerTotal,
            'Hotel'
        );
    }

    /** @return array<string,mixed> */
    public function syncTransportSummary(int $serviceId, float $customerTotal): array
    {
        return $this->writeNativeCommercial(
            $this->lockedServiceById($serviceId, self::TRANSPORT_PRODUCT_SERVICE_ID, 'Transport'),
            $customerTotal,
            'Transport'
        );
    }

    /**
     * Rebuild zero/stale native commercials for already-approved bookings from
     * each product's own persisted rows (including its lossless compatibility
     * snapshot). No booking-total residual or description participates.
     *
     * @return array{hotel:array<string,mixed>|null,transport:array<string,mixed>|null}
     */
    public function reconcileForInvoice(int $bookingId): array
    {
        $this->assertTransaction('Hotel / Transport invoice reconciliation');
        if (! Schema::hasTable('booking_services')) {
            $this->fail('invoice', 'The native booking service store is unavailable.');
        }

        $services = DB::table('booking_services')
            ->where('booking_id', $bookingId)
            ->whereIn('product_service_id', [self::HOTEL_PRODUCT_SERVICE_ID, self::TRANSPORT_PRODUCT_SERVICE_ID])
            ->lockForUpdate()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->filter(fn (array $row): bool => $this->isActive($row))
            ->values()
            ->all();

        $result = ['hotel' => null, 'transport' => null];
        foreach ([
            self::HOTEL_PRODUCT_SERVICE_ID => ['key' => 'hotel', 'label' => 'Hotel'],
            self::TRANSPORT_PRODUCT_SERVICE_ID => ['key' => 'transport', 'label' => 'Transport'],
        ] as $productId => $definition) {
            $matches = array_values(array_filter(
                $services,
                static fn (array $row): bool => (int) ($row['product_service_id'] ?? 0) === $productId
            ));
            if (! $matches) continue;
            if (count($matches) !== 1) {
                $this->fail('invoice', $definition['label'].' commercial reconciliation found multiple active native booking services.');
            }

            $rows = $this->persistedProductRows($matches[0], $definition['key']);
            if (! $rows) {
                $this->fail('invoice', $definition['label'].' commercial reconciliation could not resolve its persisted product rows safely.');
            }
            $total = $definition['key'] === 'hotel'
                ? $this->hotelTotal($rows)
                : $this->transportTotal($rows);
            $result[$definition['key']] = $this->writeNativeCommercial($matches[0], $total, $definition['label']);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function lockedServiceById(int $serviceId, int $productId, string $label): array
    {
        $this->assertTransaction($label.' normal-save synchronization');
        if (! Schema::hasTable('booking_services')) {
            $this->fail(strtolower($label), 'The native booking service store is unavailable.');
        }
        $row = (array) (DB::table('booking_services')->where('id', $serviceId)->lockForUpdate()->first() ?? []);
        if (! $row || (int) ($row['product_service_id'] ?? 0) !== $productId || ! $this->isActive($row)) {
            $this->fail(strtolower($label), $label.' native booking service identity could not be resolved safely.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function writeNativeCommercial(array $service, float $customerTotal, string $label): array
    {
        $columns = Schema::getColumnListing('booking_services');
        foreach (['quantity', 'unit_price', 'line_total', 'currency_code', 'pricing_basis_snapshot'] as $required) {
            if (! in_array($required, $columns, true)) {
                $this->fail(strtolower($label), $label.' native booking service is missing required commercial field '.$required.'.');
            }
        }

        $basis = strtoupper(trim((string) ($service['pricing_basis_snapshot'] ?? '')));
        if (! in_array($basis, ['PER_SERVICE', 'FLAT', 'FIXED'], true)) {
            $this->fail(strtolower($label), $label.' native pricing basis must be PER_SERVICE for deterministic reconciliation.');
        }
        if (! is_finite($customerTotal) || $customerTotal < 0) {
            $this->fail(strtolower($label), $label.' authoritative customer total is invalid.');
        }

        $total = round($customerTotal, 2);
        $update = [
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
            'currency_code' => 'PKR',
        ];
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        DB::table('booking_services')->where('id', (int) $service['id'])->update($update);

        $fresh = (array) (DB::table('booking_services')->where('id', (int) $service['id'])->first() ?? []);
        if ((int) ($fresh['quantity'] ?? 0) !== 1
            || abs((float) ($fresh['unit_price'] ?? -1) - $total) >= 0.01
            || abs((float) ($fresh['line_total'] ?? -1) - $total) >= 0.01
            || strtoupper(trim((string) ($fresh['currency_code'] ?? ''))) !== 'PKR') {
            $this->fail(strtolower($label), $label.' native booking service commercial verification failed.');
        }
        return $fresh;
    }

    /** @return list<array<string,mixed>> */
    private function persistedProductRows(array $service, string $product): array
    {
        $jsonKey = $product === 'hotel' ? 'et_erp_hotel_stays' : 'et_erp_transport_rows';
        $rowKey = $product === 'hotel' ? 'stays' : 'transports';
        $tag = $product === 'hotel' ? 'ETERP_HOTEL_STAYS' : 'ETERP_TRANSPORT_ROWS';

        foreach (['meta', 'metadata', 'extra_data', 'details_json', 'attributes'] as $field) {
            if (! array_key_exists($field, $service)) continue;
            $decoded = is_array($service[$field]) ? $service[$field] : json_decode((string) ($service[$field] ?? ''), true);
            $rows = is_array($decoded) ? ($decoded[$jsonKey][$rowKey] ?? null) : null;
            if (is_array($rows)) {
                $rows = array_values(array_filter($rows, 'is_array'));
                if ($rows) return $rows;
            }
        }
        foreach (['notes', 'remarks', 'internal_notes', 'description', 'details', 'other_details', 'comment', 'comments'] as $field) {
            if (! array_key_exists($field, $service)) continue;
            $payload = $this->readTaggedPayload((string) ($service[$field] ?? ''), $tag);
            $rows = is_array($payload) ? ($payload[$rowKey] ?? null) : null;
            if (is_array($rows)) {
                $rows = array_values(array_filter($rows, 'is_array'));
                if ($rows) return $rows;
            }
        }
        return $this->physicalProductRows(
            (int) ($service['booking_id'] ?? 0),
            (int) ($service['id'] ?? 0),
            $product
        );
    }

    /** @return list<array<string,mixed>> */
    private function physicalProductRows(int $bookingId, int $serviceId, string $product): array
    {
        $candidates = $product === 'hotel'
            ? ['booking_hotel_stays', 'booking_hotels', 'hotel_stays', 'booking_hotel_details', 'booking_accommodations', 'hotel_booking_details']
            : ['booking_transport_segments', 'booking_transports', 'transport_booking_details', 'booking_transport_details'];
        foreach ($candidates as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table);
            if (! in_array('booking_id', $columns, true)) continue;
            $query = DB::table($table)->where('booking_id', $bookingId);
            $serviceColumn = $this->bookingServiceLinkColumn($table, $columns);
            if ($serviceColumn !== null) $query->where($serviceColumn, $serviceId);
            if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
            $rows = $query->get()->map(static fn (object $row): array => (array) $row)->all();
            if (! $rows) continue;

            return array_map(function (array $row) use ($columns, $product): array {
                if ($product === 'transport') {
                    return ['sale_amount' => $this->firstMeaningfulNumber($row, $columns, [
                        'sale_amount', 'selling_total', 'customer_total', 'sale_total', 'total_sale',
                        'customer_amount', 'selling_amount', 'customer_price', 'sale_price', 'selling_price',
                    ])];
                }
                $nights = (int) ($this->firstExistingValue($row, $columns, ['nights', 'total_nights', 'night_count']) ?? 0);
                $total = $this->firstMeaningfulNumber($row, $columns, [
                    'selling_total', 'customer_total', 'sale_total', 'total_sale', 'customer_amount',
                    'sale_amount', 'selling_amount', 'gross_sale',
                ]);
                $rate = $this->firstMeaningfulNumber($row, $columns, [
                    'sale_rate', 'selling_rate', 'customer_rate', 'sale_price', 'selling_price',
                    'customer_price', 'sale', 'sell_price', 'room_sale_rate', 'nightly_sale_rate',
                    'selling_price_per_night', 'customer_price_per_night',
                ]);
                return [
                    'customer_total' => $total > 0 ? $total : null,
                    'sale_rate' => $rate,
                    'nights' => $nights,
                ];
            }, $rows);
        }
        return [];
    }

    /** @param list<string> $columns */
    private function bookingServiceLinkColumn(string $table, array $columns): ?string
    {
        if (in_array('booking_service_id', $columns, true)) return 'booking_service_id';
        try {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $target = strtolower((string) ($foreign['foreign_table'] ?? $foreign['foreign_table_name'] ?? $foreign['table'] ?? ''));
                if ($target !== 'booking_services') continue;
                foreach ((array) ($foreign['columns'] ?? $foreign['local_columns'] ?? []) as $column) {
                    if (in_array((string) $column, $columns, true)) return (string) $column;
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /** @param list<string> $columns @param list<string> $fields */
    private function firstMeaningfulNumber(array $row, array $columns, array $fields): float
    {
        $fallback = 0.0;
        foreach ($fields as $field) {
            if (! in_array($field, $columns, true) || ! array_key_exists($field, $row) || ! is_numeric($row[$field])) continue;
            $value = (float) $row[$field];
            if ($value > 0) return $value;
            $fallback = $value;
        }
        return $fallback;
    }

    /** @param list<string> $columns @param list<string> $fields */
    private function firstExistingValue(array $row, array $columns, array $fields): mixed
    {
        foreach ($fields as $field) {
            if (in_array($field, $columns, true) && array_key_exists($field, $row)) return $row[$field];
        }
        return null;
    }

    /** @param list<array<string,mixed>> $rows */
    private function hotelTotal(array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $index => $row) {
            $amount = $row['customer_total'] ?? null;
            if (! is_numeric($amount)) {
                $rate = $row['sale_rate'] ?? null;
                $nights = $row['nights'] ?? null;
                if (! is_numeric($rate) || ! is_numeric($nights) || (int) $nights < 1) {
                    $this->fail('invoice', 'Hotel persisted stay '.($index + 1).' has no safe customer-total authority.');
                }
                $amount = (float) $rate * (int) $nights;
            }
            if (! is_finite((float) $amount) || (float) $amount < 0) {
                $this->fail('invoice', 'Hotel persisted stay '.($index + 1).' has an invalid customer total.');
            }
            $total += (float) $amount;
        }
        return round($total, 2);
    }

    /** @param list<array<string,mixed>> $rows */
    private function transportTotal(array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $index => $row) {
            if (! array_key_exists('sale_amount', $row) || ! is_numeric($row['sale_amount'])) {
                $this->fail('invoice', 'Transport persisted row '.($index + 1).' has no safe sale_amount authority.');
            }
            $amount = (float) $row['sale_amount'];
            if (! is_finite($amount) || $amount < 0) {
                $this->fail('invoice', 'Transport persisted row '.($index + 1).' has an invalid sale_amount.');
            }
            $total += $amount;
        }
        return round($total, 2);
    }

    /** @return array<string,mixed>|null */
    private function readTaggedPayload(string $text, string $tag): ?array
    {
        if ($text === '' || ! preg_match('/\[\['.preg_quote($tag, '/').':([A-Za-z0-9+\/=]+)\]\]/', $text, $match)) return null;
        $json = base64_decode((string) ($match[1] ?? ''), true);
        if ($json === false) return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $row */
    private function isActive(array $row): bool
    {
        if (! empty($row['deleted_at'])) return false;
        if (array_key_exists('is_active', $row) && ! (bool) $row['is_active']) return false;
        if (array_key_exists('active', $row) && ! (bool) $row['active']) return false;
        return ! in_array(strtolower(trim((string) ($row['status'] ?? ''))), [
            'inactive', 'deleted', 'removed', 'cancelled', 'canceled',
        ], true);
    }

    private function assertTransaction(string $operation): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException($operation.' must run inside a database transaction.');
        }
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
