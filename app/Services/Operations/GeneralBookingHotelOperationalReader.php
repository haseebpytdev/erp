<?php

namespace App\Services\Operations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only operational Hotel authority for a General/Multi-Service booking.
 *
 * Hotel detail rows are allowed to belong either directly to a booking or to
 * an individual Hotel booking_service.  This adapter deliberately exposes
 * only operational stay fields; supplier cost and margin never cross this
 * boundary.
 */
final class GeneralBookingHotelOperationalReader
{
    public function __construct(
        private readonly GeneralBookingHotelNativeStoreResolver $store,
        private readonly NativeProductServiceResolver $products,
        private readonly UnifiedGroupPackageDataSource $vendors,
    ) {}

    /** @return list<array<string,mixed>> */
    public function staysForBooking(int $bookingId): array
    {
        if ($bookingId <= 0 || ! Schema::hasTable('booking_services')) return [];
        $master = $this->products->findHotel();
        if (! $master || (int) ($master['id'] ?? 0) <= 0) return [];

        $services = DB::table('booking_services')
            ->where('booking_id', $bookingId)
            ->where('product_service_id', (int) $master['id'])
            ->get()
            ->filter(fn ($row): bool => $this->isActive((array) $row))
            ->values();
        if ($services->isEmpty()) return [];

        $resolved = $this->store->resolve();
        if (! $resolved) return [];
        $table = (string) $resolved['table'];
        $columns = Schema::getColumnListing($table);
        $rows = collect();
        $serviceIds = $services->pluck('id')->map(fn ($id): int => (int) $id)->filter()->values();

        if (($resolved['ownership_mode'] ?? '') === 'service_link') {
            $link = (string) ($resolved['service_link_column'] ?? '');
            if ($link === '' || $serviceIds->isEmpty()) return [];
            $rows = DB::table($table)->whereIn($link, $serviceIds->all())->get()->map(
                fn ($row) => $this->normalize((array) $row, $bookingId, (int) data_get($row, $link), $columns)
            );
        } else {
            $bookingColumn = (string) ($resolved['booking_column'] ?? 'booking_id');
            if (! in_array($bookingColumn, $columns, true)) return [];
            $rows = DB::table($table)->where($bookingColumn, $bookingId)->get()->map(function ($row) use ($bookingId, $serviceIds, $columns): array {
                $data = (array) $row;
                $serviceId = (int) ($data['booking_service_id'] ?? 0);
                if ($serviceId <= 0 && $serviceIds->count() === 1) $serviceId = (int) $serviceIds->first();
                return $this->normalize($data, $bookingId, $serviceId, $columns);
            });
        }

        $vendorMap = $this->vendorMap();
        $seen = [];
        return $rows
            ->map(function (array $row) use ($vendorMap): array {
                $vendorId = (int) ($row['vendor_id'] ?? 0);
                if (($row['vendor_name'] ?? '') === '' && $vendorId > 0) {
                    $row['vendor_name'] = $vendorMap[$vendorId] ?? '';
                }
                return $row;
            })
            ->filter(function (array $row) use (&$seen): bool {
                $key = (int) ($row['id'] ?? 0) . '|' . (int) ($row['booking_service_id'] ?? 0);
                if (isset($seen[$key])) return false;
                $seen[$key] = true;
                return true;
            })
            ->sortBy(fn (array $row): string => sprintf(
                '%s|%08d|%08d',
                (string) ($row['check_in'] ?? ''),
                (int) ($row['booking_service_id'] ?? 0),
                (int) ($row['id'] ?? 0)
            ))
            ->values()->all();
    }

    private function normalize(array $row, int $bookingId, int $serviceId, array $columns): array
    {
        $value = static function (array $data, array $aliases, mixed $fallback = ''): mixed {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $data) && $data[$alias] !== null && trim((string) $data[$alias]) !== '') return $data[$alias];
            }
            return $fallback;
        };
        $vendorId = (int) $value($row, ['supplier_vendor_party_id', 'vendor_id', 'supplier_id', 'service_provider_id'], 0);
        return [
            'id' => (int) $value($row, ['id'], 0),
            'booking_id' => $bookingId,
            'booking_service_id' => $serviceId,
            'vendor_id' => $vendorId,
            'vendor_name' => trim((string) $value($row, ['vendor_name', 'supplier_name', 'provider_name', 'vendor'], '')),
            'city' => trim((string) $value($row, ['city', 'city_name', 'destination', 'hotel_city_snapshot'], '')),
            'hotel_id' => (int) $value($row, ['hotel_id'], 0),
            'hotel_name' => trim((string) $value($row, ['hotel_name', 'hotel_name_snapshot', 'hotel', 'property_name', 'name'], '')),
            'confirmation_no' => trim((string) $value($row, ['confirmation_number', 'confirmation_no', 'booking_reference', 'reference_no'], '')),
            'room_type' => trim((string) $value($row, ['room_type', 'room_category', 'room'], '')),
            'board' => trim((string) $value($row, ['board', 'meal_plan'], '')),
            'check_in' => $value($row, ['check_in', 'check_in_date', 'checkin', 'arrival_date'], ''),
            'check_out' => $value($row, ['check_out', 'check_out_date', 'checkout', 'departure_date'], ''),
            'nights' => max(0, (int) $value($row, ['nights', 'number_of_nights', 'night_count'], 0)),
        ];
    }

    /** @return array<int,string> */
    private function vendorMap(): array
    {
        try {
            return $this->vendors->vendors()->mapWithKeys(function ($row): array {
                $data = (array) $row;
                return [(int) ($data['id'] ?? 0) => trim((string) ($data['name'] ?? $data['party_name'] ?? $data['company_name'] ?? ''))];
            })->filter(fn (string $name, int $id): bool => $id > 0 && $name !== '')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function isActive(array $row): bool
    {
        if (array_key_exists('deleted_at', $row) && $row['deleted_at'] !== null) return false;
        foreach (['is_active', 'active'] as $field) {
            if (array_key_exists($field, $row) && in_array($row[$field], [false, 0, '0'], true)) return false;
        }
        return ! in_array(strtolower(trim((string) ($row['status'] ?? ''))), ['inactive','retired','deleted','removed','cancelled','canceled'], true);
    }
}
