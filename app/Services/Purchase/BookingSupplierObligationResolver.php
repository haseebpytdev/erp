<?php

namespace App\Services\Purchase;

use App\Services\Operations\UnifiedGroupPackageDataSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only projection of persisted booking vendor obligations.
 * Supplier Costing consumes these rows; it never becomes a second base-cost authority.
 */
final class BookingSupplierObligationResolver
{
    private const ACTIVE_COSTING_STATUSES = ['draft', 'pending_approval', 'approved', 'posted'];

    public function __construct(private readonly UnifiedGroupPackageDataSource $bookingData) {}

    /** @return list<array<string,mixed>> */
    public function resolve(int $bookingId, ?int $excludeCostingId = null): array
    {
        if ($bookingId <= 0 || ! Schema::hasTable('bookings') || ! DB::table('bookings')->where('id', $bookingId)->exists()) {
            return [];
        }

        $vendors = $this->vendorMap();
        $services = $this->bookingServices($bookingId);
        $rows = array_merge(
            $this->air($bookingId, $services, $vendors),
            $this->hotel($bookingId, $services, $vendors),
            $this->transport($bookingId, $services, $vendors),
            $this->visa($bookingId, $vendors),
        );

        $claims = $this->claims(array_column($rows, 'source_key'), $excludeCostingId);
        $legacy = $this->legacyActiveCosting($bookingId, $excludeCostingId);
        foreach ($rows as &$row) {
            $claim = $claims[$row['source_key']] ?? null;
            if ($claim) {
                $row['status'] = (string) $claim->status === 'posted' ? 'posted' : 'already_costed';
                $row['status_label'] = ((string) $claim->status === 'posted' ? 'Posted in ' : 'Already in ').$claim->costing_no;
                $row['costing_id'] = (int) $claim->supplier_costing_id;
                $row['costing_no'] = (string) $claim->costing_no;
            } elseif ($legacy) {
                $row['status'] = 'legacy_review';
                $row['status_label'] = 'Review existing '.$legacy->costing_no;
                $row['costing_id'] = (int) $legacy->id;
                $row['costing_no'] = (string) $legacy->costing_no;
            } elseif ((int) $row['supplier_id'] <= 0) {
                $row['status'] = 'vendor_required';
                $row['status_label'] = 'Vendor required in booking';
            } elseif ((float) $row['source_cost'] <= 0) {
                $row['status'] = 'cost_required';
                $row['status_label'] = 'Positive booking cost required';
            } else {
                $row['status'] = 'available';
                $row['status_label'] = 'Available';
            }
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => [$a['supplier_name'], $a['product_order'], $a['source_key']] <=> [$b['supplier_name'], $b['product_order'], $b['source_key']]);
        return $rows;
    }

    /** @return list<array{id:int,name:string}> */
    public function suppliers(array $obligations, bool $availableOnly = true): array
    {
        $map = [];
        foreach ($obligations as $row) {
            if ($availableOnly && ($row['status'] ?? '') !== 'available') continue;
            $id = (int) ($row['supplier_id'] ?? 0);
            if ($id <= 0) continue;
            $map[$id] = ['id' => $id, 'name' => (string) $row['supplier_name']];
        }
        return array_values($map);
    }

    /** @return list<array<string,mixed>> */
    public function availableForSupplier(array $obligations, int $supplierId): array
    {
        return array_values(array_filter($obligations, static fn (array $row): bool =>
            (int) ($row['supplier_id'] ?? 0) === $supplierId && ($row['status'] ?? '') === 'available'
        ));
    }

    /** @return array<int,array<string,mixed>> */
    private function bookingServices(int $bookingId): array
    {
        if (! Schema::hasTable('booking_services')) return [];
        return DB::table('booking_services')->where('booking_id', $bookingId)->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->id => (array) $row])->all();
    }

    /** @param array<int,array<string,mixed>> $services @param array<int,string> $vendors */
    private function air(int $bookingId, array $services, array $vendors): array
    {
        if (! Schema::hasTable('air_ticket_details')) return [];
        $columns = Schema::getColumnListing('air_ticket_details');
        if (! in_array('id', $columns, true) || ! in_array('booking_service_id', $columns, true) || ! in_array('net_supplier_cost', $columns, true)) return [];
        $serviceIds = $this->serviceIds($services, 1);
        if (! $serviceIds) return [];
        $query = DB::table('air_ticket_details')->whereIn('booking_service_id', $serviceIds);
        if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
        $result = [];
        foreach ($query->orderBy('id')->get() as $object) {
            $row = (array) $object;
            $commercial = $this->embedded($row, ['et_erp_commercial']);
            $effective = array_merge($row, $commercial);
            $service = $services[(int) $row['booking_service_id']] ?? [];
            // booking_services.vendor_id is the corrected service-scoped Air
            // vendor authority. Ticket metadata remains a legacy read fallback.
            $vendorId = $this->positiveInt($service, ['vendor_id']);
            if ($vendorId <= 0) $vendorId = $this->positiveInt($effective, ['vendor_id', 'supplier_id']);
            $passengerId = $this->positiveInt($effective, ['booking_passenger_id', 'passenger_id', 'traveller_id', 'traveler_id']);
            $reference = $this->text($effective, ['ticket_number', 'ticket_no', 'e_ticket_number', 'document_number', 'pnr']);
            $result[] = $this->obligation(
                $bookingId, (int) $row['booking_service_id'], 'air_ticket_details', (int) $row['id'],
                'Air Ticket', 10, $vendorId, $this->vendorName($vendorId, $vendors, array_merge($effective, $service)),
                (float) $row['net_supplier_cost'], $reference ?: 'Air ticket #'.$row['id'],
                $this->passengerName($passengerId, $effective), $passengerId
            );
        }
        return $result;
    }

    /** @param array<int,array<string,mixed>> $services @param array<int,string> $vendors */
    private function hotel(int $bookingId, array $services, array $vendors): array
    {
        $result = [];
        foreach ($this->serviceIds($services, 3) as $serviceId) {
            $snapshot = $this->snapshotRows($services[$serviceId] ?? [], 'hotel');
            if ($snapshot !== []) {
                foreach ($snapshot as $index => $row) $result[] = $this->hotelRow($bookingId, $serviceId, 'booking_service_hotel_snapshot', $row, $index, $vendors);
                continue;
            }
            $found = false;
            foreach (['booking_hotel_stays', 'booking_hotels', 'hotel_stays', 'booking_hotel_details', 'booking_accommodations', 'hotel_booking_details'] as $table) {
                $physical = $this->physicalRows($table, $bookingId, $serviceId);
                if ($physical === null || $physical === []) continue;
                foreach ($physical as $index => $row) $result[] = $this->hotelRow($bookingId, $serviceId, $table, $row, $index, $vendors);
                $found = true;
                break;
            }
            if ($found) continue;
        }
        return $result;
    }

    private function hotelRow(int $bookingId, int $serviceId, string $sourceType, array $row, int $index, array $vendors): array
    {
        $meta = $this->embedded($row, ['et_erp_hotel_stay', 'et_erp_vendor']);
        $effective = array_merge($row, $meta);
        $vendorId = $this->positiveInt($effective, ['vendor_id', 'supplier_id', 'service_provider_id']);
        $total = $this->positiveNumber($effective, ['vendor_total', 'net_supplier_cost', 'supplier_total', 'cost_total', 'total_cost', 'vendor_amount', 'cost_amount', 'supplier_amount']);
        $nights = max(0, (int) ($effective['nights'] ?? $effective['total_nights'] ?? $effective['night_count'] ?? 0));
        if ($total <= 0 && $nights > 0) $total = $this->positiveNumber($effective, ['cost_rate', 'supplier_rate', 'vendor_rate', 'cost_price', 'supplier_cost', 'vendor_cost']) * $nights;
        $id = (int) ($row['id'] ?? 0);
        $reference = $this->text($effective, ['confirmation_no', 'confirmation_number', 'brn', 'booking_reference', 'voucher_no']);
        $detail = trim(implode(' · ', array_filter([$this->text($effective, ['hotel_name', 'property_name', 'name']), $this->text($effective, ['city', 'city_name']), $nights > 0 ? $nights.' night(s)' : ''])));
        return $this->obligation($bookingId, $serviceId, $sourceType, $id ?: null, 'Hotel', 20, $vendorId, $this->vendorName($vendorId, $vendors, $effective), $total, $reference ?: 'Hotel stay '.($index + 1), $detail, null, $index);
    }

    /** @param array<int,array<string,mixed>> $services @param array<int,string> $vendors */
    private function transport(int $bookingId, array $services, array $vendors): array
    {
        $result = [];
        foreach ($this->serviceIds($services, 4) as $serviceId) {
            $snapshot = $this->snapshotRows($services[$serviceId] ?? [], 'transport');
            if ($snapshot !== []) {
                foreach ($snapshot as $index => $row) $result[] = $this->transportRow($bookingId, $serviceId, 'booking_service_transport_snapshot', $row, $index, $vendors);
                continue;
            }
            $found = false;
            foreach (['booking_transport_segments', 'booking_transports', 'transport_booking_details', 'booking_transport_details'] as $table) {
                $physical = $this->physicalRows($table, $bookingId, $serviceId);
                if ($physical === null || $physical === []) continue;
                foreach ($physical as $index => $row) $result[] = $this->transportRow($bookingId, $serviceId, $table, $row, $index, $vendors);
                $found = true;
                break;
            }
            if ($found) continue;
        }
        return $result;
    }

    private function transportRow(int $bookingId, int $serviceId, string $sourceType, array $row, int $index, array $vendors): array
    {
        $meta = $this->embedded($row, ['et_erp_transport_row', 'et_erp_vendor']);
        $effective = array_merge($row, $meta);
        $vendorId = $this->positiveInt($effective, ['vendor_id', 'supplier_id', 'service_provider_id']);
        $total = $this->positiveNumber($effective, ['supplier_amount_pkr', 'vendor_total_pkr', 'cost_amount_pkr', 'supplier_total_pkr', 'cost_amount', 'vendor_total', 'cost_total', 'total_cost']);
        $id = (int) ($row['id'] ?? 0);
        $reference = $this->text($effective, ['supplier_reference', 'booking_reference', 'reference', 'voucher_no']);
        $detail = trim(implode(' · ', array_filter([$this->text($effective, ['route_name', 'route', 'sector']), $this->text($effective, ['vehicle_name', 'vehicle_type', 'transport_type']), $this->text($effective, ['company_name', 'vendor_name'])])));
        return $this->obligation($bookingId, $serviceId, $sourceType, $id ?: null, 'Transport', 30, $vendorId, $this->vendorName($vendorId, $vendors, $effective), $total, $reference ?: 'Transport service '.($index + 1), $detail, null, $index);
    }

    /** @param array<int,string> $vendors */
    private function visa(int $bookingId, array $vendors): array
    {
        if (! Schema::hasTable('booking_visa_services')) return [];
        $columns = Schema::getColumnListing('booking_visa_services');
        if (! in_array('id', $columns, true) || ! in_array('booking_id', $columns, true) || ! in_array('vendor_cost_pkr', $columns, true)) return [];
        $result = [];
        $query = DB::table('booking_visa_services')->where('booking_id', $bookingId);
        if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
        foreach ($query->orderBy('id')->get() as $object) {
            $row = (array) $object;
            $vendorId = $this->positiveInt($row, ['vendor_id']);
            $passengerId = $this->positiveInt($row, ['booking_passenger_id']);
            $reference = $this->text($row, ['application_reference', 'visa_number']);
            $detail = trim(implode(' · ', array_filter([$this->passengerName($passengerId, $row), $this->text($row, ['country']), $this->text($row, ['visa_type'])])));
            $result[] = $this->obligation($bookingId, null, 'booking_visa_services', (int) $row['id'], 'Visa', 40, $vendorId, $this->vendorName($vendorId, $vendors, $row), (float) $row['vendor_cost_pkr'], $reference ?: 'Visa service #'.$row['id'], $detail, $passengerId);
        }
        return $result;
    }

    private function obligation(int $bookingId, ?int $serviceId, string $sourceType, ?int $sourceId, string $product, int $order, int $vendorId, string $vendorName, float $cost, string $reference, string $detail = '', ?int $passengerId = null, int $index = 0): array
    {
        $sourceKey = $sourceType.':'.($sourceId ?: ($serviceId.':'.$index));
        return [
            'booking_id' => $bookingId, 'booking_service_id' => $serviceId,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'source_key' => $sourceKey,
            'product_type' => $product, 'product_order' => $order,
            'supplier_id' => $vendorId, 'supplier_name' => $vendorName,
            'source_cost' => round($cost, 2), 'currency_code' => 'PKR',
            'source_reference' => $reference, 'detail' => $detail,
            'passenger_id' => $passengerId, 'passenger_name' => $passengerId ? $this->passengerName($passengerId) : null,
            'status' => 'available', 'status_label' => 'Available', 'costing_id' => null, 'costing_no' => null,
        ];
    }

    /** @return array<int,string> */
    private function vendorMap(): array
    {
        try {
            return $this->bookingData->vendors()->mapWithKeys(static fn (array $row): array => [(int) ($row['id'] ?? 0) => trim((string) ($row['name'] ?? ''))])->filter()->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function vendorName(int $id, array $vendors, array $row = []): string
    {
        if ($id > 0 && isset($vendors[$id])) return $vendors[$id];
        $saved = $this->text($row, ['vendor_name', 'supplier_name', 'provider_name', 'company_name']);
        return $saved !== '' ? $saved : ($id > 0 ? 'Vendor #'.$id : 'Vendor unresolved');
    }

    private function passengerName(int $id, array $row = []): string
    {
        $saved = $this->text($row, ['passenger_name', 'traveller_name', 'traveler_name', 'full_name']);
        if ($saved !== '') return $saved;
        if ($id <= 0 || ! Schema::hasTable('booking_passengers')) return $id > 0 ? 'Passenger #'.$id : '';
        $columns = Schema::getColumnListing('booking_passengers');
        $record = DB::table('booking_passengers')->where('id', $id)->first();
        if (! $record) return 'Passenger #'.$id;
        $data = (array) $record;
        $first = $this->text($data, array_values(array_intersect(['first_name', 'given_name', 'name', 'passenger_name'], $columns)));
        $last = $this->text($data, array_values(array_intersect(['last_name', 'surname', 'family_name'], $columns)));
        return trim($first.' '.$last) ?: 'Passenger #'.$id;
    }

    /** @return list<int> */
    private function serviceIds(array $services, int $productId): array
    {
        return array_values(array_map('intval', array_keys(array_filter($services, fn (array $row): bool =>
            (int) ($row['product_service_id'] ?? 0) === $productId && $this->active($row)
        ))));
    }

    private function active(array $row): bool
    {
        if (! empty($row['deleted_at'])) return false;
        if (array_key_exists('is_active', $row) && ! (bool) $row['is_active']) return false;
        return ! in_array(strtolower(trim((string) ($row['status'] ?? ''))), ['inactive', 'deleted', 'removed', 'cancelled', 'canceled'], true);
    }

    /** @return list<array<string,mixed>>|null */
    private function physicalRows(string $table, int $bookingId, int $serviceId): ?array
    {
        if (! Schema::hasTable($table)) return null;
        $columns = Schema::getColumnListing($table);
        if (! in_array('booking_id', $columns, true)) return null;
        $query = DB::table($table)->where('booking_id', $bookingId);
        foreach (['booking_service_id', 'service_id'] as $field) if (in_array($field, $columns, true)) { $query->where($field, $serviceId); break; }
        if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
        return $query->orderBy(in_array('id', $columns, true) ? 'id' : 'booking_id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @return list<array<string,mixed>> */
    private function snapshotRows(array $service, string $product): array
    {
        $key = $product === 'hotel' ? 'et_erp_hotel_stays' : 'et_erp_transport_rows';
        $child = $product === 'hotel' ? 'stays' : 'transports';
        $tag = $product === 'hotel' ? 'ETERP_HOTEL_STAYS' : 'ETERP_TRANSPORT_ROWS';
        foreach (['meta', 'metadata', 'extra_data', 'details_json', 'attributes'] as $field) {
            if (! array_key_exists($field, $service)) continue;
            $decoded = is_array($service[$field]) ? $service[$field] : json_decode((string) ($service[$field] ?? ''), true);
            $rows = is_array($decoded) ? ($decoded[$key][$child] ?? null) : null;
            if (is_array($rows)) return array_values(array_filter($rows, 'is_array'));
        }
        foreach (['notes', 'remarks', 'internal_notes', 'description', 'details', 'other_details', 'comment', 'comments'] as $field) {
            if (! array_key_exists($field, $service) || ! preg_match('/\[\['.preg_quote($tag, '/').':([A-Za-z0-9+\/=]+)\]\]/', (string) ($service[$field] ?? ''), $match)) continue;
            $json = base64_decode((string) $match[1], true);
            $decoded = $json === false ? null : json_decode($json, true);
            $rows = is_array($decoded) ? ($decoded[$child] ?? null) : null;
            if (is_array($rows)) return array_values(array_filter($rows, 'is_array'));
        }
        return [];
    }

    private function embedded(array $row, array $keys): array
    {
        foreach ($row as $value) {
            if (! is_string($value) || $value === '') continue;
            $decoded = json_decode($value, true);
            if (! is_array($decoded)) continue;
            foreach ($keys as $key) if (is_array($decoded[$key] ?? null)) return $decoded[$key];
        }
        return [];
    }

    /** @return array<string,object> */
    private function claims(array $sourceKeys, ?int $excludeCostingId): array
    {
        if (! $sourceKeys || ! Schema::hasTable('supplier_costing_source_links')) return [];
        $query = DB::table('supplier_costing_source_links as source')
            ->join('supplier_costings as costing', 'costing.id', '=', 'source.supplier_costing_id')
            ->whereIn('source.source_key', $sourceKeys)->whereIn('costing.status', self::ACTIVE_COSTING_STATUSES);
        if ($excludeCostingId) $query->where('costing.id', '!=', $excludeCostingId);
        return $query->get(['source.source_key', 'source.supplier_costing_id', 'costing.costing_no', 'costing.status'])->keyBy('source_key')->all();
    }

    private function legacyActiveCosting(int $bookingId, ?int $excludeCostingId): ?object
    {
        if (! Schema::hasTable('supplier_costings')) return null;
        $query = DB::table('supplier_costings as costing')->where('costing.booking_id', $bookingId)->whereIn('costing.status', self::ACTIVE_COSTING_STATUSES);
        if ($excludeCostingId) $query->where('costing.id', '!=', $excludeCostingId);
        if (Schema::hasTable('supplier_costing_source_links')) {
            $query->whereNotExists(fn ($links) => $links->selectRaw('1')->from('supplier_costing_source_links as legacy_source')->whereColumn('legacy_source.supplier_costing_id', 'costing.id'));
        }
        return $query->orderBy('costing.id')->first(['costing.id', 'costing.costing_no']);
    }

    private function positiveInt(array $row, array $fields): int
    {
        foreach ($fields as $field) if ((int) ($row[$field] ?? 0) > 0) return (int) $row[$field];
        return 0;
    }

    private function positiveNumber(array $row, array $fields): float
    {
        foreach ($fields as $field) if (isset($row[$field]) && is_numeric($row[$field]) && (float) $row[$field] > 0) return (float) $row[$field];
        return 0.0;
    }

    private function text(array $row, array $fields): string
    {
        foreach ($fields as $field) if (trim((string) ($row[$field] ?? '')) !== '') return trim((string) $row[$field]);
        return '';
    }
}
