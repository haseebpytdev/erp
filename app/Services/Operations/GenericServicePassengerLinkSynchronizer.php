<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Keeps the host-native generic service->passengers relation consistent with
 * a product's already-persisted passenger authority.  It never invents a
 * passenger assignment: Air is eligible only when its native ticket rows are
 * non-null, unique, and all belong to the booking being reconciled.
 */
final class GenericServicePassengerLinkSynchronizer
{
    private const TABLE = 'booking_service_passengers';

    /**
     * Normal Air-save authority. This is called inside the caller's existing
     * transaction after air_ticket_details has been written and read back.
     *
     * @return list<int>
     */
    public function syncAirFromNative(int $bookingId, int $serviceId): array
    {
        return $this->synchronize($bookingId, $serviceId, true);
    }

    /**
     * Reconciles only complete, already-native Air links before the host
     * invoice call. The caller is inside NativeSalesInvoiceRuntimeBridge's
     * transaction, so a native invoice failure rolls this reconciliation back.
     *
     * @return array<int,list<int>> service id => linked booking passenger ids
     */
    public function reconcileCompleteAirServicesForInvoice(int $bookingId): array
    {
        $this->assertGenericSchema();
        if (! Schema::hasTable('booking_services') || ! Schema::hasTable('air_ticket_details')) {
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
            $serviceId = (int) ($service->id ?? 0);
            if ($serviceId <= 0 || ! $this->hasNativeAirRows($serviceId)) {
                continue;
            }

            $nativeIds = $this->nativeAirPassengerIds($serviceId);
            $genericIds = $this->genericPassengerIds($serviceId);
            if ($nativeIds !== $genericIds) {
                $reconciled[$serviceId] = $this->synchronize($bookingId, $serviceId, false);
            } else {
                $reconciled[$serviceId] = $nativeIds;
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
    private function synchronize(int $bookingId, int $serviceId, bool $requireNativeRows): array
    {
        $schema = $this->assertGenericSchema();
        $this->assertServiceBelongsToBooking($bookingId, $serviceId);

        $nativeIds = $this->nativeAirPassengerIds($serviceId);
        if ($requireNativeRows && $nativeIds === []) {
            $this->fail('Native Air passenger links are missing; generic service links were not changed.');
        }
        if ($nativeIds === []) return [];

        $this->assertPassengerOwnership($bookingId, $nativeIds);
        $existing = $this->genericRows($serviceId, $schema['service_column']);
        $this->removeDuplicatesAndStaleRows($serviceId, $nativeIds, $existing, $schema);

        $existingIds = $this->genericPassengerIds($serviceId);
        foreach (array_values(array_diff($nativeIds, $existingIds)) as $passengerId) {
            $row = [
                $schema['service_column'] => $serviceId,
                $schema['passenger_column'] => $passengerId,
            ];
            if (in_array('created_at', $schema['columns'], true)) $row['created_at'] = now();
            if (in_array('updated_at', $schema['columns'], true)) $row['updated_at'] = now();
            DB::table(self::TABLE)->insert($row);
        }

        $after = $this->genericPassengerIds($serviceId);
        if ($after !== $nativeIds) {
            $this->fail('Generic service passenger synchronization did not persist the exact validated native Air passenger set.');
        }

        return $after;
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

    private function assertServiceBelongsToBooking(int $bookingId, int $serviceId): void
    {
        $row = DB::table('booking_services')
            ->where('id', $serviceId)
            ->where('booking_id', $bookingId)
            ->lockForUpdate()
            ->first();
        if (! $row) $this->fail('The Air booking service no longer belongs to this booking.');
    }

    /** @param list<int> $passengerIds */
    private function assertPassengerOwnership(int $bookingId, array $passengerIds): void
    {
        if (count($passengerIds) !== count(array_unique($passengerIds))) {
            $this->fail('Native Air passenger links are ambiguous; generic service links were not changed.');
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
            $this->fail('Native Air passenger links include a passenger outside this booking; generic service links were not changed.');
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
}
