<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Materializes the host-native Visa booking service from the authoritative
 * per-passenger Visa rows. Callers deliberately own the surrounding database
 * transaction so service, commercial and passenger changes commit together.
 */
final class VisaBookingServiceSynchronizer
{
    private const CHILD_TABLE = 'booking_visa_services';
    private const SERVICE_TABLE = 'booking_services';
    private const CUSTOMER_TOTAL_FIELDS = [
        'line_total', 'selling_total', 'customer_total', 'sale_total',
        'total_sale', 'customer_amount', 'selling_amount',
    ];

    public function __construct(
        private readonly GenericServicePassengerLinkSynchronizer $passengerLinks,
    ) {}

    /** @return array<string,mixed> */
    public function synchronize(int $bookingId, bool $retireWhenEmpty = false): array
    {
        if (DB::transactionLevel() < 1) {
            $this->fail('Visa booking-service synchronization must run inside a database transaction.');
        }
        $this->assertSchema();

        $booking = (array) (DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first() ?? []);
        if (! $booking) $this->fail('The booking could not be resolved for Visa synchronization.');

        $children = DB::table(self::CHILD_TABLE)
            ->where('booking_id', $bookingId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        if ($children === [] && ! $retireWhenEmpty) {
            return [
                'product_service_id' => null,
                'product_service_table' => null,
                'product_name' => null,
                'revenue_mapping_key' => null,
                'passenger_link_mode' => null,
                'pricing_basis' => null,
                'booking_service_id' => null,
                'linked_passenger_ids' => [],
                'customer_total' => 0.0,
                'vendor_total' => 0.0,
            ];
        }

        $master = $this->resolveVisaMaster();
        $services = $this->visaServices($bookingId, (int) $master['id']);
        $active = array_values(array_filter($services, fn (array $row): bool => $this->isActive($row)));
        if (count($active) > 1) {
            $this->fail('More than one active native Visa booking service exists; no service was selected by guesswork.');
        }

        if ($children === []) {
            if ($active !== []) {
                $serviceId = (int) ($active[0]['id'] ?? 0);
                $this->passengerLinks->syncVisaFromNative($bookingId, $serviceId, (int) $master['id'], []);
                $this->deactivate($serviceId);
            }
            return $this->result($master, null, [], 0.0, 0.0);
        }

        [$passengerIds, $customerTotal, $vendorTotal, $vendorId] = $this->childAuthority($bookingId, $children);
        $contract = $this->masterContract($master);
        $quantity = $this->quantity($contract['pricing_basis'], count($passengerIds));
        $unitPrice = round($customerTotal / $quantity, 2);
        if (abs(($quantity * $unitPrice) - $customerTotal) > 0.005) {
            $this->fail('The authoritative Visa total cannot be represented exactly by the native quantity and unit-price contract.');
        }

        $service = $active[0] ?? $this->latestInactive($services);
        $serviceId = $this->writeService(
            $bookingId,
            $booking,
            $master,
            $contract,
            $quantity,
            $unitPrice,
            $customerTotal,
            $vendorTotal,
            $vendorId,
            $service,
        );

        $linked = [];
        if (in_array($contract['passenger_link_mode'], ['REQUIRED', 'MULTIPLE', 'SINGLE'], true)) {
            if ($contract['passenger_link_mode'] === 'SINGLE' && count($passengerIds) !== 1) {
                $this->fail('The native Visa SINGLE passenger contract conflicts with multiple Visa child passengers.');
            }
            $linked = $this->passengerLinks->syncVisaFromNative(
                $bookingId,
                $serviceId,
                (int) $master['id'],
                $passengerIds,
            );
        }

        $this->verify($bookingId, (int) $master['id'], $serviceId, $quantity, $unitPrice, $customerTotal);
        return $this->result($master, $serviceId, $linked, $customerTotal, $vendorTotal);
    }

    private function assertSchema(): void
    {
        foreach (['bookings', 'booking_passengers', self::CHILD_TABLE, self::SERVICE_TABLE] as $table) {
            if (! Schema::hasTable($table)) $this->fail('Required native Visa integration table '.$table.' is unavailable.');
        }
        foreach ([
            self::CHILD_TABLE => ['id', 'booking_id', 'booking_passenger_id', 'sale_pkr', 'vendor_cost_pkr'],
            self::SERVICE_TABLE => ['id', 'booking_id', 'product_service_id'],
        ] as $table => $required) {
            $columns = Schema::getColumnListing($table);
            $missing = array_values(array_diff($required, $columns));
            if ($missing !== []) $this->fail($table.' is missing required field(s): '.implode(', ', $missing).'.');
        }
    }

    /** @param list<array<string,mixed>> $rows @return array{0:list<int>,1:float,2:float,3:?int} */
    private function childAuthority(int $bookingId, array $rows): array
    {
        $ids = [];
        $customer = 0.0;
        $vendor = 0.0;
        $vendorIds = [];
        $missingVendor = false;
        foreach ($rows as $row) {
            $id = (int) ($row['booking_passenger_id'] ?? 0);
            if ($id <= 0 || in_array($id, $ids, true)) {
                $this->fail('Visa child rows have a missing or duplicated booking passenger.');
            }
            if (! is_numeric($row['sale_pkr'] ?? null) || ! is_numeric($row['vendor_cost_pkr'] ?? null)) {
                $this->fail('Visa child rows have incomplete customer or vendor commercial values.');
            }
            $ids[] = $id;
            $customer += (float) $row['sale_pkr'];
            $vendor += (float) $row['vendor_cost_pkr'];
            $vendorId = (int) ($row['vendor_id'] ?? 0);
            if ($vendorId > 0) {
                $vendorIds[] = $vendorId;
            } else {
                $missingVendor = true;
            }
        }
        sort($ids);

        $owned = DB::table('booking_passengers')->where('booking_id', $bookingId)
            ->whereIn('id', $ids)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        sort($owned);
        if ($owned !== $ids) $this->fail('A Visa child passenger does not belong to this booking.');

        $vendorIds = array_values(array_unique($vendorIds));
        if (count($vendorIds) > 1 || ($vendorIds !== [] && $missingVendor)) {
            $this->fail('Visa child rows do not resolve to one unambiguous Vendor ID.');
        }

        return [$ids, round($customer, 2), round($vendor, 2), $vendorIds[0] ?? null];
    }

    /** @return array{id:int,table:string,row:array<string,mixed>} */
    private function resolveVisaMaster(): array
    {
        $tables = [];
        try {
            foreach (Schema::getForeignKeys(self::SERVICE_TABLE) as $foreign) {
                $locals = (array) ($foreign['columns'] ?? $foreign['local_columns'] ?? []);
                if (! in_array('product_service_id', $locals, true)) continue;
                $table = (string) ($foreign['foreign_table'] ?? $foreign['foreign_table_name'] ?? $foreign['table'] ?? '');
                if ($table !== '' && Schema::hasTable($table)) $tables[] = $table;
            }
        } catch (Throwable) {
        }
        if ($tables === []) {
            foreach (['product_services', 'product_service_master', 'product_service_masters', 'travel_product_services', 'service_products'] as $table) {
                if (Schema::hasTable($table)) $tables[] = $table;
            }
        }

        $matches = [];
        foreach (array_values(array_unique($tables)) as $table) {
            $columns = Schema::getColumnListing($table);
            $idColumn = $this->firstColumn($columns, ['id', 'product_service_id']);
            if (! $idColumn) continue;
            $semantic = array_values(array_intersect($columns, [
                'name', 'service_name', 'title', 'label', 'description',
                'code', 'service_code', 'product_code', 'slug',
                'type', 'service_type', 'product_type', 'category',
            ]));
            if ($semantic === []) continue;

            foreach (DB::table($table)->get() as $object) {
                $row = (array) $object;
                if (! $this->isActive($row)) continue;
                $haystack = strtolower(implode(' ', array_map(
                    static fn (string $field): string => trim((string) ($row[$field] ?? '')),
                    $semantic,
                )));
                if (! preg_match('/(^|[^a-z0-9])visa([^a-z0-9]|$)/i', $haystack)) continue;
                $id = (int) ($row[$idColumn] ?? 0);
                if ($id > 0) $matches[$table.':'.$id] = ['id' => $id, 'table' => $table, 'row' => $row];
            }
        }

        if (count($matches) !== 1) {
            $this->fail(count($matches) === 0
                ? 'The active native Visa Product/Service master could not be resolved.'
                : 'Multiple active native Visa Product/Service masters matched; no product identity was guessed.');
        }
        return array_values($matches)[0];
    }

    /** @param array{id:int,table:string,row:array<string,mixed>} $master @return array{name:string,code:string,revenue_mapping_key:string,passenger_link_mode:string,pricing_basis:string} */
    private function masterContract(array $master): array
    {
        $row = $master['row'];
        $name = $this->firstValue($row, ['name', 'service_name', 'title', 'label', 'description']);
        $code = $this->firstValue($row, ['code', 'service_code', 'product_code', 'slug']);
        $revenue = $this->firstValue($row, [
            'revenue_mapping_key', 'accounting_mapping_key', 'mapping_key',
            'revenue_key', 'income_mapping_key', 'sales_mapping_key',
        ]);
        $linkMode = strtoupper($this->firstValue($row, ['passenger_link_mode', 'passenger_link_mode_snapshot']));
        $pricing = strtoupper($this->firstValue($row, ['pricing_basis', 'pricing_basis_snapshot']));
        if ($name === '' || $linkMode === '' || $pricing === '') {
            $this->fail('The native Visa master does not expose its required name, passenger-link mode and pricing basis contract.');
        }
        return [
            'name' => $name,
            'code' => $code,
            'revenue_mapping_key' => $revenue,
            'passenger_link_mode' => $linkMode,
            'pricing_basis' => $pricing,
        ];
    }

    private function quantity(string $pricingBasis, int $passengerCount): int
    {
        return match ($pricingBasis) {
            'PER_PERSON', 'PER_PASSENGER', 'PER_PAX' => $passengerCount,
            'PER_SERVICE', 'FLAT', 'FIXED' => 1,
            default => throw ValidationException::withMessages([
                'visa' => 'Unsupported native Visa pricing basis '.$pricingBasis.'; quantity was not guessed.',
            ]),
        };
    }

    /** @return list<array<string,mixed>> */
    private function visaServices(int $bookingId, int $masterId): array
    {
        return DB::table(self::SERVICE_TABLE)->where('booking_id', $bookingId)
            ->where('product_service_id', $masterId)->orderByDesc('id')->lockForUpdate()->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    /** @param list<array<string,mixed>> $services @return array<string,mixed>|null */
    private function latestInactive(array $services): ?array
    {
        foreach ($services as $service) if (! $this->isActive($service)) return $service;
        return null;
    }

    /** @param array<string,mixed> $booking @param array{id:int,table:string,row:array<string,mixed>} $master @param array<string,string> $contract @param array<string,mixed>|null $existing */
    private function writeService(int $bookingId, array $booking, array $master, array $contract, int $quantity, float $unitPrice, float $customerTotal, float $vendorTotal, ?int $vendorId, ?array $existing): int
    {
        $columns = Schema::getColumnListing(self::SERVICE_TABLE);
        $row = ['booking_id' => $bookingId, 'product_service_id' => (int) $master['id']];
        foreach (['company_id', 'branch_id', 'customer_id', 'agent_id', 'salesperson_id', 'currency_id', 'tenant_id', 'office_id'] as $field) {
            if (in_array($field, $columns, true) && array_key_exists($field, $booking)) $row[$field] = $booking[$field];
        }
        $this->putFirst($row, $columns, ['service_name', 'name', 'title', 'description'], $contract['name']);
        $this->putFirst($row, $columns, ['service_code', 'product_code', 'code'], $contract['code'] ?: null);
        $this->putFirst($row, $columns, [
            'revenue_mapping_key', 'revenue_mapping_key_snapshot',
            'accounting_mapping_key', 'accounting_mapping_key_snapshot',
            'mapping_key', 'mapping_key_snapshot', 'revenue_key',
        ], $contract['revenue_mapping_key'] ?: null);
        foreach ([
            'revenue_account_id', 'income_account_id', 'sales_account_id',
            'service_revenue_account_id', 'account_id', 'ledger_account_id',
        ] as $field) {
            if (in_array($field, $columns, true) && ! empty($master['row'][$field])) {
                $row[$field] = (int) $master['row'][$field];
            }
        }
        $this->putContract($row, $columns, ['passenger_link_mode_snapshot', 'passenger_link_mode'], $contract['passenger_link_mode']);
        $this->putContract($row, $columns, ['pricing_basis_snapshot', 'pricing_basis'], $contract['pricing_basis']);
        $this->putAll($row, $columns, ['quantity', 'qty'], $quantity);
        $this->putAll($row, $columns, ['unit_price'], $unitPrice);
        $this->putAll($row, $columns, self::CUSTOMER_TOTAL_FIELDS, $customerTotal);
        $this->putAll($row, $columns, ['net_supplier_cost', 'supplier_total', 'vendor_total', 'cost_total', 'total_cost', 'supplier_amount', 'vendor_amount'], $vendorTotal);
        $this->putAll($row, $columns, ['margin', 'gross_margin', 'net_margin', 'profit'], round($customerTotal - $vendorTotal, 2));
        $this->putAll($row, $columns, ['currency_code'], 'PKR');
        if (in_array('vendor_id', $columns, true)) $row['vendor_id'] = $vendorId;
        if (in_array('is_active', $columns, true)) $row['is_active'] = 1;
        if (in_array('active', $columns, true)) $row['active'] = 1;
        if (in_array('status', $columns, true) && (! $existing || ! $this->isActive($existing))) {
            $row['status'] = $this->activeStatusValue($master['row']);
        }
        if (in_array('deleted_at', $columns, true)) $row['deleted_at'] = null;
        if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();

        $totalFields = array_values(array_intersect($columns, self::CUSTOMER_TOTAL_FIELDS));
        if ($totalFields === []) $this->fail('booking_services has no supported native customer-total field for Visa.');

        if ($existing) {
            $id = (int) ($existing['id'] ?? 0);
            if ($id <= 0) $this->fail('The existing native Visa booking service has no usable identity.');
            DB::table(self::SERVICE_TABLE)->where('id', $id)->update(array_diff_key($row, ['booking_id' => true, 'product_service_id' => true]));
            return $id;
        }

        if (in_array('created_at', $columns, true)) $row['created_at'] = now();
        $this->assertNoUnsupportedRequiredColumns($row);
        return (int) DB::table(self::SERVICE_TABLE)->insertGetId($row);
    }

    /** @param array<string,mixed> $row */
    private function assertNoUnsupportedRequiredColumns(array $row): void
    {
        try {
            $missing = [];
            foreach (Schema::getColumns(self::SERVICE_TABLE) as $meta) {
                $name = (string) ($meta['name'] ?? $meta['column_name'] ?? '');
                if ($name === '' || $name === 'id') continue;
                if (array_key_exists($name, $row) && $row[$name] !== null && $row[$name] !== '') continue;
                $nullableRaw = $meta['nullable'] ?? $meta['is_nullable'] ?? false;
                $nullable = $nullableRaw === true || in_array(strtoupper((string) $nullableRaw), ['YES', 'Y', 'TRUE', '1'], true);
                $hasDefault = array_key_exists('default', $meta) && $meta['default'] !== null;
                $auto = (bool) ($meta['auto_increment'] ?? false) || str_contains(strtolower((string) ($meta['extra'] ?? '')), 'auto_increment');
                if (! $nullable && ! $hasDefault && ! $auto) $missing[] = $name;
            }
            if ($missing !== []) $this->fail('Native Visa service requires unsupported field(s): '.implode(', ', $missing).'.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // The database insert remains authoritative for drivers that do
            // not expose complete column metadata.
        }
    }

    private function verify(int $bookingId, int $masterId, int $serviceId, int $quantity, float $unitPrice, float $customerTotal): void
    {
        $services = $this->visaServices($bookingId, $masterId);
        $active = array_values(array_filter($services, fn (array $row): bool => $this->isActive($row)));
        if (count($active) !== 1 || (int) ($active[0]['id'] ?? 0) !== $serviceId) {
            $this->fail('Visa synchronization did not leave exactly one active native booking service.');
        }
        $actual = $this->firstNumeric($active[0], self::CUSTOMER_TOTAL_FIELDS);
        if ($actual === null || abs($actual - $customerTotal) > 0.005) {
            $this->fail('The native Visa booking service customer total did not match authoritative Visa child rows.');
        }
        if (array_key_exists('quantity', $active[0]) && (int) $active[0]['quantity'] !== $quantity) {
            $this->fail('The native Visa booking service quantity did not match its pricing contract.');
        }
        if (array_key_exists('unit_price', $active[0])) {
            $actualUnitPrice = (float) $active[0]['unit_price'];
            if (abs($actualUnitPrice - $unitPrice) > 0.005
                || abs(($quantity * $actualUnitPrice) - $customerTotal) > 0.005) {
                $this->fail('The native Visa booking service quantity and unit price do not reconcile to its authoritative line total.');
            }
        }
    }

    private function deactivate(int $serviceId): void
    {
        $columns = Schema::getColumnListing(self::SERVICE_TABLE);
        $row = [];
        if (in_array('is_active', $columns, true)) $row['is_active'] = 0;
        if (in_array('active', $columns, true)) $row['active'] = 0;
        if (in_array('status', $columns, true)) $row['status'] = $this->inactiveStatusValue();
        if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();
        if ($row === []) $this->fail('The native Visa booking service cannot be deactivated safely on this schema.');
        DB::table(self::SERVICE_TABLE)->where('id', $serviceId)->update($row);
    }

    /** @param array{id:int,table:string,row:array<string,mixed>} $master @param list<int> $linked @return array<string,mixed> */
    private function result(array $master, ?int $serviceId, array $linked, float $customer, float $vendor): array
    {
        $contract = $this->masterContract($master);
        return [
            'product_service_id' => (int) $master['id'],
            'product_service_table' => $master['table'],
            'product_name' => $contract['name'],
            'revenue_mapping_key' => $contract['revenue_mapping_key'],
            'passenger_link_mode' => $contract['passenger_link_mode'],
            'pricing_basis' => $contract['pricing_basis'],
            'booking_service_id' => $serviceId,
            'linked_passenger_ids' => $linked,
            'customer_total' => $customer,
            'vendor_total' => $vendor,
        ];
    }

    /** @param array<string,mixed> $row */
    private function isActive(array $row): bool
    {
        if (! empty($row['deleted_at'])) return false;
        if (array_key_exists('is_active', $row) && ! (bool) $row['is_active']) return false;
        if (array_key_exists('active', $row) && ! (bool) $row['active']) return false;
        return ! in_array(strtolower(trim((string) ($row['status'] ?? ''))), ['inactive', 'deleted', 'removed', 'cancelled', 'canceled'], true);
    }

    /** @param array<string,mixed> $row @param list<string> $fields */
    private function firstValue(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    /** @param array<string,mixed> $row @param list<string> $fields */
    private function firstNumeric(array $row, array $fields): ?float
    {
        foreach ($fields as $field) if (isset($row[$field]) && is_numeric($row[$field])) return (float) $row[$field];
        return null;
    }

    /** @param array<string,mixed> $master */
    private function activeStatusValue(array $master): string
    {
        $masterStatus = trim((string) ($master['status'] ?? ''));
        $candidates = array_values(array_filter([$masterStatus, 'active', 'ACTIVE', 'booked', 'BOOKED', 'confirmed', 'CONFIRMED']));
        return $this->enumCompatibleValue('status', $candidates, 'active');
    }

    private function inactiveStatusValue(): string
    {
        return $this->enumCompatibleValue('status', ['inactive', 'INACTIVE', 'removed', 'REMOVED', 'cancelled', 'CANCELLED'], 'inactive');
    }

    /** @param list<string> $candidates */
    private function enumCompatibleValue(string $field, array $candidates, string $fallback): string
    {
        try {
            foreach (Schema::getColumns(self::SERVICE_TABLE) as $meta) {
                $name = (string) ($meta['name'] ?? $meta['column_name'] ?? '');
                if ($name !== $field) continue;
                $type = (string) ($meta['type'] ?? $meta['type_name'] ?? '');
                if (! preg_match('/^enum\((.*)\)$/i', $type, $match)) return $fallback;
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $match[1], $values);
                $allowed = array_map(static fn (string $value): string => stripcslashes($value), $values[1] ?? []);
                foreach ($candidates as $candidate) {
                    foreach ($allowed as $value) if (strcasecmp($candidate, $value) === 0) return $value;
                }
                $this->fail('The native booking-service '.$field.' contract has no compatible lifecycle value.');
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
        }
        return $fallback;
    }

    /** @param list<string> $columns @param list<string> $fields */
    private function putFirst(array &$row, array $columns, array $fields, mixed $value): void
    {
        foreach ($fields as $field) if (in_array($field, $columns, true)) { $row[$field] = $value; return; }
    }

    /** @param list<string> $columns @param list<string> $fields */
    private function putContract(array &$row, array $columns, array $fields, string $value): void
    {
        foreach ($fields as $field) {
            if (! in_array($field, $columns, true)) continue;
            $row[$field] = $this->enumCompatibleValue($field, [$value], $value);
            return;
        }
    }

    /** @param list<string> $columns @param list<string> $fields */
    private function putAll(array &$row, array $columns, array $fields, mixed $value): void
    {
        foreach ($fields as $field) if (in_array($field, $columns, true)) $row[$field] = $value;
    }

    /** @param list<string> $columns @param list<string> $candidates */
    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate;
        return null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['visa' => $message]);
    }
}
