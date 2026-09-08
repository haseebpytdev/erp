<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Keeps the host-native generic service->passengers relation consistent with
 * a product's already-persisted passenger authority. It never derives an
 * assignment from passenger count: Air uses explicit ticket links, while the
 * native Hotel/Transport products use an explicit MULTIPLE + PER_SERVICE
 * booking-wide applicability contract.
 */
final class GenericServicePassengerLinkSynchronizer
{
    private const TABLE = 'booking_service_passengers';
    private const AIR_PRODUCT_SERVICE_ID = 1;
    private const HOTEL_PRODUCT_SERVICE_ID = 3;
    private const TRANSPORT_PRODUCT_SERVICE_ID = 4;

    /**
     * Normal Air-save authority. This is called inside the caller's existing
     * transaction after air_ticket_details has been written and read back.
     *
     * @return list<int>
     */
    public function syncAirFromNative(int $bookingId, int $serviceId): array
    {
        return $this->synchronizeAir($bookingId, $serviceId, true);
    }

    /** @return list<int> */
    public function syncHotelBookingWide(int $bookingId, int $serviceId): array
    {
        try {
            return $this->synchronizeBookingWide(
                $bookingId,
                $serviceId,
                self::HOTEL_PRODUCT_SERVICE_ID,
                'Hotel',
            );
        } catch (ValidationException $exception) {
            $this->rethrowForProduct($exception, 'hotel');
        }
    }

    /** @return list<int> */
    public function syncTransportBookingWide(int $bookingId, int $serviceId): array
    {
        try {
            return $this->synchronizeBookingWide(
                $bookingId,
                $serviceId,
                self::TRANSPORT_PRODUCT_SERVICE_ID,
                'Transport',
            );
        } catch (ValidationException $exception) {
            $this->rethrowForProduct($exception, 'transport');
        }
    }

    /**
     * Visa child rows carry an explicit passenger subset. The Visa service
     * resolver supplies the product identity discovered from the native master;
     * this method never guesses that identity or expands the subset booking-wide.
     *
     * @param list<int> $passengerIds
     * @return list<int>
     */
    public function syncVisaFromNative(
        int $bookingId,
        int $serviceId,
        int $productServiceId,
        array $passengerIds,
    ): array {
        try {
            $rawIds = array_map(static fn ($id): int => (int) $id, $passengerIds);
            $passengerIds = array_values(array_filter($rawIds, static fn (int $id): bool => $id > 0));
            sort($passengerIds);
            if (count($passengerIds) !== count($rawIds)
                || count($passengerIds) !== count(array_unique($passengerIds))) {
                $this->fail('Native Visa passenger links are incomplete or duplicated; generic service links were not changed.');
            }

            $service = $this->serviceForBooking($bookingId, $serviceId);
            if ((int) ($service['product_service_id'] ?? 0) !== $productServiceId) {
                $this->fail('Native Visa passenger links cannot be applied to another Product/Service master.');
            }
            if (! $this->serviceIsActive($service)) {
                $this->fail('Native Visa passenger links cannot be applied to an inactive booking service.');
            }

            $this->assertPassengerOwnership($bookingId, $passengerIds);
            return $this->synchronizeExact($bookingId, $serviceId, $passengerIds);
        } catch (ValidationException $exception) {
            $this->rethrowForProduct($exception, 'visa');
        }
    }

    /**
     * Reconciles every deterministic active service before the host invoice
     * call. The caller owns NativeSalesInvoiceRuntimeBridge's transaction, so
     * a later native validation failure rolls the complete set back atomically.
     *
     * @return array<int,list<int>> service id => linked booking passenger ids
     */
    public function reconcileDeterministicServicesForInvoice(int $bookingId): array
    {
        $this->assertGenericSchema();
        if (! Schema::hasTable('booking_services')) {
            return [];
        }

        $serviceColumns = Schema::getColumnListing('booking_services');
        if (! in_array('id', $serviceColumns, true) || ! in_array('booking_id', $serviceColumns, true)) {
            return [];
        }

        $services = DB::table('booking_services')
            ->where('booking_id', $bookingId)
            ->lockForUpdate()
            ->get();

        $reconciled = [];
        foreach ($services as $service) {
            $row = (array) $service;
            if (! $this->serviceIsActive($row)) continue;

            $serviceId = (int) ($row['id'] ?? 0);
            $productServiceId = (int) ($row['product_service_id'] ?? 0);
            if ($serviceId <= 0) continue;

            if ($productServiceId === self::AIR_PRODUCT_SERVICE_ID) {
                if (! $this->hasNativeAirRows($serviceId)) continue;
                $reconciled[$serviceId] = $this->synchronizeAir($bookingId, $serviceId, false);
                continue;
            }

            if ($productServiceId === self::HOTEL_PRODUCT_SERVICE_ID) {
                $reconciled[$serviceId] = $this->synchronizeBookingWide(
                    $bookingId,
                    $serviceId,
                    self::HOTEL_PRODUCT_SERVICE_ID,
                    'Hotel',
                );
                continue;
            }

            if ($productServiceId === self::TRANSPORT_PRODUCT_SERVICE_ID) {
                $reconciled[$serviceId] = $this->synchronizeBookingWide(
                    $bookingId,
                    $serviceId,
                    self::TRANSPORT_PRODUCT_SERVICE_ID,
                    'Transport',
                );
            }
        }

        return $reconciled;
    }

    /** @return list<array<string,mixed>> */
    public function auditActiveServices(int $bookingId): array
    {
        if (! Schema::hasTable('booking_services')) return [];

        $columns = Schema::getColumnListing('booking_services');
        if (! in_array('booking_id', $columns, true)) return [];

        $query = DB::table('booking_services')->where('booking_id', $bookingId);
        if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
        if (in_array('is_active', $columns, true)) $query->where('is_active', true);

        $rows = [];
        foreach ($query->orderBy(in_array('id', $columns, true) ? 'id' : 'booking_id')->get() as $service) {
            $row = (array) $service;
            if (! $this->serviceIsActive($row)) continue;
            $serviceId = (int) ($row['id'] ?? 0);
            $rows[] = [
                'booking_service_id' => $serviceId,
                'product_service_id' => $row['product_service_id'] ?? null,
                'description' => $row['description'] ?? $row['service_name'] ?? $row['name'] ?? null,
                'passenger_link_mode_snapshot' => $row['passenger_link_mode_snapshot'] ?? $row['passenger_link_mode'] ?? null,
                'pricing_basis_snapshot' => $row['pricing_basis_snapshot'] ?? $row['pricing_basis'] ?? null,
                'generic_linked_passenger_count' => $serviceId > 0 && $this->genericSchemaAvailable()
                    ? count($this->genericPassengerIds($serviceId))
                    : null,
                'native_air_passenger_count' => $serviceId > 0 && $this->hasNativeAirRows($serviceId)
                    ? count($this->nativeAirPassengerIds($serviceId))
                    : 0,
            ];
        }

        return $rows;
    }

    /** @return list<int> */
    private function synchronizeAir(int $bookingId, int $serviceId, bool $requireNativeRows): array
    {
        $service = $this->serviceForBooking($bookingId, $serviceId);
        if ((int) ($service['product_service_id'] ?? 0) !== self::AIR_PRODUCT_SERVICE_ID) {
            $this->fail('Native Air passenger links cannot be applied to a non-Air booking service.');
        }

        $nativeIds = $this->nativeAirPassengerIds($serviceId);
        if ($requireNativeRows && $nativeIds === []) {
            $this->fail('Native Air passenger links are missing; generic service links were not changed.');
        }
        if ($nativeIds === []) return [];

        $this->assertPassengerOwnership($bookingId, $nativeIds);
        return $this->synchronizeExact($bookingId, $serviceId, $nativeIds);
    }

    /** @return list<int> */
    private function synchronizeBookingWide(
        int $bookingId,
        int $serviceId,
        int $expectedProductServiceId,
        string $product,
    ): array {
        $service = $this->serviceForBooking($bookingId, $serviceId);
        $this->assertBookingWideContract($service, $expectedProductServiceId, $product);
        $passengerIds = $this->activeBookingPassengerIds($bookingId);
        if ($passengerIds === []) {
            $this->fail($product.' booking-wide passenger synchronization requires at least one active booking passenger.');
        }

        return $this->synchronizeExact($bookingId, $serviceId, $passengerIds);
    }

    /** @param list<int> $passengerIds @return list<int> */
    private function synchronizeExact(int $bookingId, int $serviceId, array $passengerIds): array
    {
        $schema = $this->assertGenericSchema();
        $this->assertPassengerOwnership($bookingId, $passengerIds);
        $existing = $this->genericRows($serviceId, $schema['service_column']);
        $this->removeDuplicatesAndStaleRows($serviceId, $passengerIds, $existing, $schema);

        $existingIds = $this->genericPassengerIds($serviceId);
        foreach (array_values(array_diff($passengerIds, $existingIds)) as $passengerId) {
            $row = [
                $schema['service_column'] => $serviceId,
                $schema['passenger_column'] => $passengerId,
            ];
            if (in_array('created_at', $schema['columns'], true)) $row['created_at'] = now();
            if (in_array('updated_at', $schema['columns'], true)) $row['updated_at'] = now();
            DB::table(self::TABLE)->insert($row);
        }

        $after = $this->genericPassengerIds($serviceId);
        if ($after !== $passengerIds) {
            $this->fail('Generic service passenger synchronization did not persist the exact authoritative passenger set.');
        }

        return $after;
    }

    /** @param array<string,mixed> $service */
    private function assertBookingWideContract(array $service, int $expectedProductServiceId, string $product): void
    {
        if ((int) ($service['product_service_id'] ?? 0) !== $expectedProductServiceId) {
            $this->fail($product.' booking-wide passenger policy cannot be applied to another Product/Service master.');
        }
        if (! $this->serviceIsActive($service)) {
            $this->fail($product.' booking-wide passenger policy cannot be applied to an inactive booking service.');
        }

        $linkMode = strtoupper(trim((string) (
            $service['passenger_link_mode_snapshot']
            ?? $service['passenger_link_mode']
            ?? ''
        )));
        $pricingBasis = strtoupper(trim((string) (
            $service['pricing_basis_snapshot']
            ?? $service['pricing_basis']
            ?? ''
        )));
        if (! in_array($linkMode, ['REQUIRED', 'MULTIPLE'], true) || $pricingBasis !== 'PER_SERVICE') {
            $this->fail(
                $product.' booking-wide passenger policy requires the native REQUIRED/MULTIPLE + PER_SERVICE contract.'
            );
        }
    }

    /** @return list<int> */
    private function activeBookingPassengerIds(int $bookingId): array
    {
        if (! Schema::hasTable('booking_passengers')) {
            $this->fail('Booking passengers are unavailable; generic service links were not changed.');
        }
        $columns = Schema::getColumnListing('booking_passengers');
        if (! in_array('id', $columns, true) || ! in_array('booking_id', $columns, true)) {
            $this->fail('Booking passengers do not expose the required native identity fields.');
        }

        $query = DB::table('booking_passengers')->where('booking_id', $bookingId);
        if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
        if (in_array('is_active', $columns, true)) $query->where('is_active', true);
        if (in_array('active', $columns, true)) $query->where('active', true);

        $ids = [];
        foreach ($query->lockForUpdate()->get() as $passenger) {
            $row = (array) $passenger;
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (in_array($status, ['inactive', 'deleted', 'removed', 'cancelled', 'canceled'], true)) continue;
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @return array{columns:list<string>,service_column:string,passenger_column:string,id_column:?string} */
    private function assertGenericSchema(): array
    {
        if (! $this->genericSchemaAvailable()) {
            $this->fail('The host generic booking-service passenger link table is unavailable.');
        }

        $columns = Schema::getColumnListing(self::TABLE);
        $serviceColumn = in_array('booking_service_id', $columns, true) ? 'booking_service_id' : null;
        $passengerColumn = in_array('booking_passenger_id', $columns, true) ? 'booking_passenger_id' : null;
        if (! $serviceColumn || ! $passengerColumn) {
            $this->fail('The host generic service passenger relation does not expose booking_service_id and booking_passenger_id.');
        }

        // Do not guess values for host-specific mandatory pivot attributes.
        // The normal two-key/timestamp pivot is writable; any additional
        // required field must be supplied by the actual native relation rather
        // than fabricated by this overlay.
        try {
            foreach (Schema::getColumns(self::TABLE) as $meta) {
                $name = (string) ($meta['name'] ?? $meta['column_name'] ?? '');
                if ($name === '' || in_array($name, [
                    $serviceColumn, $passengerColumn, 'id', 'created_at', 'updated_at', 'deleted_at',
                ], true)) {
                    continue;
                }
                $nullableRaw = $meta['nullable'] ?? $meta['is_nullable'] ?? false;
                $nullable = $nullableRaw === true || in_array(strtoupper((string) $nullableRaw), ['YES', 'Y', 'TRUE', '1'], true);
                $defaultExists = array_key_exists('default', $meta) && $meta['default'] !== null;
                $autoIncrement = (bool) ($meta['auto_increment'] ?? false);
                if (! $nullable && ! $defaultExists && ! $autoIncrement) {
                    $this->fail('The host generic service passenger relation requires unsupported pivot field '.$name.'. No links were changed.');
                }
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // The insert/readback remains the database authority where a host
            // driver cannot expose detailed column metadata.
        }

        return [
            'columns' => $columns,
            'service_column' => $serviceColumn,
            'passenger_column' => $passengerColumn,
            'id_column' => in_array('id', $columns, true) ? 'id' : null,
        ];
    }

    private function genericSchemaAvailable(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function serviceForBooking(int $bookingId, int $serviceId): array
    {
        $row = DB::table('booking_services')
            ->where('id', $serviceId)
            ->where('booking_id', $bookingId)
            ->lockForUpdate()
            ->first();
        if (! $row) $this->fail('The booking service no longer belongs to this booking.');

        return (array) $row;
    }

    /** @param array<string,mixed> $service */
    private function serviceIsActive(array $service): bool
    {
        if (! empty($service['deleted_at'])) return false;
        if (array_key_exists('is_active', $service) && ! (bool) $service['is_active']) return false;
        if (array_key_exists('active', $service) && ! (bool) $service['active']) return false;
        $status = strtolower(trim((string) ($service['status'] ?? '')));

        return ! in_array($status, ['inactive', 'deleted', 'removed', 'cancelled', 'canceled'], true);
    }

    /** @param list<int> $passengerIds */
    private function assertPassengerOwnership(int $bookingId, array $passengerIds): void
    {
        if (count($passengerIds) !== count(array_unique($passengerIds))) {
            $this->fail('The authoritative passenger set is ambiguous; generic service links were not changed.');
        }
        if (! Schema::hasTable('booking_passengers')) {
            $this->fail('Booking passengers are unavailable; generic service links were not changed.');
        }

        $owned = DB::table('booking_passengers')
            ->where('booking_id', $bookingId)
            ->whereIn('id', $passengerIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        sort($owned);
        if ($owned !== $passengerIds) {
            $this->fail('The authoritative passenger set includes a passenger outside this booking; generic service links were not changed.');
        }
    }

    private function hasNativeAirRows(int $serviceId): bool
    {
        try {
            return Schema::hasTable('air_ticket_details')
                && in_array('booking_service_id', Schema::getColumnListing('air_ticket_details'), true)
                && DB::table('air_ticket_details')->where('booking_service_id', $serviceId)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<int> */
    private function nativeAirPassengerIds(int $serviceId): array
    {
        if (! $this->hasNativeAirRows($serviceId)) return [];
        $columns = Schema::getColumnListing('air_ticket_details');
        $column = in_array('booking_passenger_id', $columns, true) ? 'booking_passenger_id' : null;
        if (! $column) $this->fail('Native Air ticket rows do not expose booking_passenger_id.');

        $query = DB::table('air_ticket_details')->where('booking_service_id', $serviceId);
        if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');
        $ids = $query->pluck($column)->map(static fn ($id): int => (int) $id)->filter()->values()->all();
        sort($ids);
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            $this->fail('Native Air passenger links are incomplete or duplicated; generic service links were not changed.');
        }

        return $ids;
    }

    /** @return list<array<string,mixed>> */
    private function genericRows(int $serviceId, string $serviceColumn): array
    {
        return DB::table(self::TABLE)
            ->where($serviceColumn, $serviceId)
            ->lockForUpdate()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return list<int> */
    private function genericPassengerIds(int $serviceId): array
    {
        $schema = $this->assertGenericSchema();
        $ids = DB::table(self::TABLE)
            ->where($schema['service_column'], $serviceId)
            ->pluck($schema['passenger_column'])
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        sort($ids);
        return $ids;
    }

    /** @param list<int> $nativeIds @param list<array<string,mixed>> $existing @param array{columns:list<string>,service_column:string,passenger_column:string,id_column:?string} $schema */
    private function removeDuplicatesAndStaleRows(int $serviceId, array $nativeIds, array $existing, array $schema): void
    {
        $seen = [];
        $deleteIds = [];
        foreach ($existing as $row) {
            $passengerId = (int) ($row[$schema['passenger_column']] ?? 0);
            $rowId = $schema['id_column'] ? (int) ($row[$schema['id_column']] ?? 0) : 0;
            if ($passengerId <= 0 || ! in_array($passengerId, $nativeIds, true) || isset($seen[$passengerId])) {
                if ($rowId <= 0) {
                    $this->fail('The host generic passenger link table has an unsupported duplicate/stale row without a primary key.');
                }
                $deleteIds[] = $rowId;
                continue;
            }
            $seen[$passengerId] = true;
        }
        if ($deleteIds !== []) {
            DB::table(self::TABLE)->whereIn($schema['id_column'], $deleteIds)->delete();
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['air' => $message]);
    }

    private function rethrowForProduct(ValidationException $exception, string $key): never
    {
        $message = collect($exception->errors())->flatten()->first()
            ?? 'Booking-service passenger links could not be synchronized safely.';
        throw ValidationException::withMessages([$key => $message]);
    }
}
