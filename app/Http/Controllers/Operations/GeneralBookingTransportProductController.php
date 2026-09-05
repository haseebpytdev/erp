<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use App\Services\Operations\BookingCommercialCompletenessResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ERP-11.3.141
 *
 * GENERAL / MULTI-SERVICE Transport product workspace.
 *
 * Transport stays compact and service-based: one row per movement with Route,
 * Vehicle, Qty, Driver, Cell, Plate, Company, BRN, Notes and one row-level
 * Sale in PKR plus master-linked foreign-currency supplier Cost Rate, DB Exchange
 * Rate and converted PKR vendor commercial. The installed booking_transport_segments table is used
 * when available; booking_services carries a lossless compatibility snapshot so
 * fields not present in older transport schemas still survive save/reload.
 * No parallel product table and no database migration are introduced.
 */
final class GeneralBookingTransportProductController extends Controller
{
    /** @var array<string,float|null> */
    private array $exchangeRateToPkrCache = [];

    public function show(Request $request, int $booking): JsonResponse
    {
        $bookingRow = $this->assertBooking($booking);
        $service = $this->findTransportService($booking);
        $serviceRow = (array) ($service['row'] ?? []);
        $table = $this->resolveTransportTable();
        $rows = $table ? $this->transportRows($booking, (int) ($service['id'] ?? 0), $table) : [];
        $rows = $this->overlaySnapshot($rows, $this->snapshotFromServiceRow($serviceRow));
        $routes = $this->routeOptions();
        $rows = $this->hydrateForeignCostCommercials($rows, $routes);

        return response()->json([
            'ok' => true,
            'booking_id' => $booking,
            'booking' => ['currency' => 'PKR', 'native_currency' => $this->bookingCurrency($bookingRow)],
            'routes' => $routes,
            'vehicles' => $this->vehicleOptions(),
            'suppliers' => $this->supplierOptions(),
            'transports' => $rows,
            'summary' => $this->summary($rows),
            'capabilities' => [
                'booking_services' => Schema::hasTable('booking_services'),
                'transport_table' => $table,
                'snapshot_carrier' => $serviceRow ? $this->snapshotCarrierAvailable('booking_services', array_keys($serviceRow)) : false,
            ],
        ]);
    }

    public function store(Request $request, int $booking): JsonResponse
    {
        $bookingRow = $this->assertBooking($booking);
        $data = $request->validate([
            'transports' => ['required', 'array', 'min:1', 'max:50'],
            'transports.*.route_master_id' => ['nullable', 'integer', 'min:0'],
            'transports.*.route_source_table' => ['nullable', 'string', 'max:120'],
            'transports.*.route_name' => ['required', 'string', 'max:255'],
            'transports.*.vehicle_master_id' => ['nullable', 'integer', 'min:0'],
            'transports.*.vehicle_source_table' => ['nullable', 'string', 'max:120'],
            'transports.*.vehicle_type' => ['required', 'string', 'max:120'],
            'transports.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'transports.*.driver_name' => ['nullable', 'string', 'max:180'],
            'transports.*.driver_cell' => ['nullable', 'string', 'max:100'],
            'transports.*.plate_number' => ['nullable', 'string', 'max:100'],
            'transports.*.vendor_id' => ['nullable', 'integer', 'min:0'],
            'transports.*.company_name' => ['nullable', 'string', 'max:180'],
            'transports.*.brn_number' => ['nullable', 'string', 'max:120'],
            'transports.*.sale_amount' => ['required', 'numeric', 'min:0'],
            'transports.*.cost_rate' => ['nullable', 'numeric', 'min:0'],
            'transports.*.cost_currency' => ['nullable', 'string', 'max:12'],
            'transports.*.exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'transports.*.cost_amount' => ['nullable', 'numeric', 'min:0'],
            'transports.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! Schema::hasTable('booking_services')) {
            throw ValidationException::withMessages([
                'transport' => 'The native booking service store is not available on this ERP installation.',
            ]);
        }

        $supplierMap = [];
        foreach ($this->supplierOptions() as $supplier) {
            $supplierMap[(int) ($supplier['id'] ?? 0)] = trim((string) ($supplier['name'] ?? ''));
        }

        $routeOptions = $this->routeOptions();
        $routeMap = [];
        foreach ($routeOptions as $routeOption) {
            $source = trim((string) ($routeOption['source_table'] ?? ''));
            $id = (int) ($routeOption['id'] ?? 0);
            if ($source !== '' && $id > 0) $routeMap[strtolower($source).':'.$id] = $routeOption;
        }

        $normalized = [];
        foreach ((array) ($data['transports'] ?? []) as $index => $raw) {
            $route = trim((string) ($raw['route_name'] ?? ''));
            $vehicle = trim((string) ($raw['vehicle_type'] ?? ''));
            if ($route === '') {
                throw ValidationException::withMessages(["transports.$index.route_name" => 'Select a Route.']);
            }
            if ($vehicle === '') {
                throw ValidationException::withMessages(["transports.$index.vehicle_type" => 'Select a Vehicle Type.']);
            }
            $vendorId = max(0, (int) ($raw['vendor_id'] ?? 0));
            if ($vendorId > 0 && ! isset($supplierMap[$vendorId])) {
                throw ValidationException::withMessages(["transports.$index.vendor_id" => 'Select a valid Transport Company / Vendor from the existing supplier authority.']);
            }
            $company = trim((string) ($raw['company_name'] ?? ''));
            if ($vendorId > 0 && isset($supplierMap[$vendorId]) && $company === '') $company = $supplierMap[$vendorId];
            $sale = round((float) ($raw['sale_amount'] ?? 0), 2);
            $quantity = max(1, (int) ($raw['quantity'] ?? 1));
            $routeSource = trim((string) ($raw['route_source_table'] ?? ''));
            $routeMasterId = max(0, (int) ($raw['route_master_id'] ?? 0));
            $master = $routeMap[strtolower($routeSource).':'.$routeMasterId] ?? null;
            if (! is_array($master)) {
                $wantedRoute = strtolower(trim((string) ($raw['route_name'] ?? '')));
                $wantedVehicle = strtolower(trim((string) ($raw['vehicle_type'] ?? '')));
                foreach ($routeOptions as $candidate) {
                    if (strtolower(trim((string) ($candidate['name'] ?? ''))) !== $wantedRoute) continue;
                    $candidateVehicle = strtolower(trim((string) ($candidate['vehicle_type'] ?? '')));
                    if ($wantedVehicle !== '' && $candidateVehicle !== '' && $candidateVehicle !== $wantedVehicle) continue;
                    $master = $candidate;
                    break;
                }
            }

            $masterRate = is_array($master) && is_numeric($master['rate_amount'] ?? null)
                ? round((float) $master['rate_amount'], 2)
                : null;
            $costRate = $masterRate ?? (array_key_exists('cost_rate', $raw) ? round((float) ($raw['cost_rate'] ?? 0), 2) : round(((float) ($raw['cost_amount'] ?? 0)) / max(1, $quantity), 2));
            $costCurrency = $this->normalizeCurrencyCode((string) (is_array($master) ? ($master['rate_currency'] ?? '') : ''));
            if ($costCurrency === '') $costCurrency = $this->normalizeCurrencyCode((string) ($raw['cost_currency'] ?? ''));
            if ($costCurrency === '') $costCurrency = (str_contains(strtolower($routeSource), 'transport_rate') ? 'SAR' : 'PKR');

            $exchangeRate = $this->exchangeRateToPkr($costCurrency);
            if ($exchangeRate === null || $exchangeRate <= 0) {
                throw ValidationException::withMessages([
                    "transports.$index.exchange_rate" => 'No active '.$costCurrency.' to PKR exchange rate could be resolved from Currency Rates. Update Currency Rates before saving Transport.',
                ]);
            }
            $cost = round($costRate * $quantity * $exchangeRate, 2);
            $normalized[] = [
                'route_master_id' => $routeMasterId,
                'route_source_table' => $routeSource,
                'route_name' => $route,
                'vehicle_master_id' => max(0, (int) ($raw['vehicle_master_id'] ?? 0)),
                'vehicle_source_table' => trim((string) ($raw['vehicle_source_table'] ?? '')),
                'vehicle_type' => $vehicle,
                'quantity' => $quantity,
                'driver_name' => trim((string) ($raw['driver_name'] ?? '')),
                'driver_cell' => trim((string) ($raw['driver_cell'] ?? '')),
                'plate_number' => trim((string) ($raw['plate_number'] ?? '')),
                'vendor_id' => $vendorId,
                'company_name' => $company,
                'brn_number' => trim((string) ($raw['brn_number'] ?? '')),
                // Sale is the customer total in PKR. Supplier Cost Rate comes from
                // the Transport Rate master in its native currency; Qty and the DB
                // exchange rate produce the authoritative PKR vendor total.
                'sale_amount' => $sale,
                'cost_rate' => $costRate,
                'cost_currency' => $costCurrency,
                'exchange_rate' => round($exchangeRate, 8),
                'cost_amount' => $cost,
                'margin' => round($sale - $cost, 2),
                'notes' => trim((string) ($raw['notes'] ?? '')),
            ];
        }

        $vendorErrors = app(BookingCommercialCompletenessResolver::class)->transportVendorErrors($normalized);
        if ($vendorErrors) {
            throw ValidationException::withMessages(['transport_vendor' => $vendorErrors]);
        }

        $stage = 'Transport service';
        try {
            $result = DB::transaction(function () use ($booking, $bookingRow, $normalized, &$stage): array {
                $stage = 'Transport service';
                $service = $this->ensureTransportService($booking, $bookingRow);

                $stage = 'Transport rows';
                $table = $this->resolveTransportTable();
                if ($table) {
                    $this->syncNativeTransportRows($booking, (int) $service['id'], $bookingRow, $table, $normalized);
                }

                $stage = 'Transport snapshot';
                $this->syncServiceSnapshot((int) $service['id'], $normalized);

                $stage = 'Transport commercials';
                $summary = $this->summary($normalized);
                $this->syncServiceTotals((int) $service['id'], $summary);
                $this->syncServiceVendorContext((int) $service['id'], $normalized);

                $freshService = (array) (DB::table('booking_services')->where('id', (int) $service['id'])->first() ?? (object) []);
                $fresh = $table ? $this->transportRows($booking, (int) $service['id'], $table) : [];
                $fresh = $this->overlaySnapshot($fresh, $this->snapshotFromServiceRow($freshService));
                $this->assertPersisted($normalized, $fresh);

                return ['transports' => $fresh, 'summary' => $this->summary($fresh)];
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            report($e);
            throw ValidationException::withMessages([
                'transport' => 'Transport Data could not be saved while writing '.$stage.'. '.$this->shortDatabaseMessage($e->getMessage()),
            ]);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'transport' => 'Transport Data could not be saved while writing '.$stage.'. Please check the server log for the native-store error.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Transport Data saved.',
            'transports' => $result['transports'],
            'summary' => $result['summary'],
            'routes' => $this->routeOptions(),
            'vehicles' => $this->vehicleOptions(),
            'suppliers' => $this->supplierOptions(),
        ]);
    }

    private function assertBooking(int $booking): object
    {
        abort_unless(Schema::hasTable('bookings'), 404);
        $row = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function bookingCurrency(object $booking): string
    {
        $row = (array) $booking;
        foreach (['currency_code', 'currency', 'booking_currency'] as $field) {
            $value = strtoupper(trim((string) ($row[$field] ?? '')));
            if ($value !== '') return $value;
        }
        if (! empty($row['currency_id']) && Schema::hasTable('currencies')) {
            try {
                $columns = $this->physicalColumnListing('currencies');
                $code = $this->firstColumn($columns, ['code', 'currency_code', 'iso_code']);
                if ($code) {
                    $value = strtoupper(trim((string) DB::table('currencies')->where('id', (int) $row['currency_id'])->value($code)));
                    if ($value !== '') return $value;
                }
            } catch (Throwable) {}
        }
        return 'PKR';
    }

    /** @return list<array<string,mixed>> */
    private function routeOptions(): array
    {
        try {
            return app(UnifiedGroupPackageDataSource::class)->transportRoutes()->map(function (array $row): array {
                $source = trim((string) ($row['source_table'] ?? ''));
                $currency = $this->normalizeCurrencyCode((string) ($row['rate_currency'] ?? ''));
                if ($currency === '' && str_contains(strtolower($source), 'transport_rate')) $currency = 'SAR';
                $rate = is_numeric($row['rate_amount'] ?? null) ? round((float) $row['rate_amount'], 2) : null;
                $fx = $this->exchangeRateToPkr($currency !== '' ? $currency : 'PKR');
                $vehicle = trim((string) ($row['vehicle_type'] ?? ''));
                $company = trim((string) ($row['company_name'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                $parts = [$name];
                if ($vehicle !== '') $parts[] = $vehicle;
                if ($company !== '') $parts[] = $company;
                if ($rate !== null) $parts[] = ($currency !== '' ? $currency.' ' : '').number_format($rate, 2);
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'source_table' => $source,
                    'key' => trim((string) ($row['key'] ?? '')),
                    'name' => $name,
                    'display' => implode(' · ', array_filter($parts, static fn ($value): bool => trim((string) $value) !== '')),
                    'vehicle_type' => $vehicle,
                    'company_name' => $company,
                    'contact_number' => trim((string) ($row['contact_number'] ?? '')),
                    'brn_number' => trim((string) ($row['brn_number'] ?? '')),
                    'rate_amount' => $rate,
                    'rate_currency' => $currency,
                    'exchange_rate_to_pkr' => $fx !== null ? round($fx, 8) : null,
                    'rate_pkr' => ($rate !== null && $fx !== null) ? round($rate * $fx, 2) : null,
                ];
            })->filter(static fn (array $row): bool => $row['name'] !== '')->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function normalizeCurrencyCode(string $value): string
    {
        $value = strtoupper(trim($value));
        return match ($value) {
            'SR', 'SAR.', 'RIYAL', 'RIYALS', 'SAUDI RIYAL', 'SAUDI RIYALS' => 'SAR',
            default => $value,
        };
    }

    private function exchangeRateToPkr(string $sourceCurrency): ?float
    {
        $sourceCurrency = $this->normalizeCurrencyCode($sourceCurrency);
        if ($sourceCurrency === '') return null;
        if ($sourceCurrency === 'PKR') return 1.0;
        if (array_key_exists($sourceCurrency, $this->exchangeRateToPkrCache)) {
            return $this->exchangeRateToPkrCache[$sourceCurrency];
        }

        $candidateTables = ['currency_rates', 'currency_exchange_rates', 'exchange_rates', 'fx_rates', 'foreign_exchange_rates'];
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                $lower = strtolower($table);
                if ($table !== '' && (str_contains($lower, 'rate')) && (str_contains($lower, 'currency') || str_contains($lower, 'exchange') || str_contains($lower, 'fx'))) {
                    $candidateTables[] = $table;
                }
            }
        } catch (Throwable) {}

        $sourceCodes = $sourceCurrency === 'SAR' ? ['SAR', 'SR'] : [$sourceCurrency];
        $sourceId = $this->currencyIdByCode($sourceCurrency);
        $pkrId = $this->currencyIdByCode('PKR');

        foreach (array_values(array_unique($candidateTables)) as $table) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! Schema::hasTable($table)) continue;
            try {
                $columns = $this->physicalColumnListing($table);
                $rateCol = $this->firstColumn($columns, ['exchange_rate','conversion_rate','rate','rate_value','value','selling_rate','sell_rate','buying_rate','buy_rate']);
                if (! $rateCol) continue;
                $dateCol = $this->firstColumn($columns, ['effective_date','rate_date','date','valid_from','as_of_date','created_at','updated_at']);
                $idCol = $this->firstColumn($columns, ['id']);

                $fromCode = $this->firstColumn($columns, ['from_currency_code','source_currency_code','base_currency_code','currency_from_code','from_currency']);
                $toCode = $this->firstColumn($columns, ['to_currency_code','target_currency_code','quote_currency_code','currency_to_code','to_currency']);
                if ($fromCode && $toCode) {
                    foreach ($sourceCodes as $sourceCode) {
                        $direct = $this->latestRateValue($table, $rateCol, $dateCol, $idCol, [[$fromCode, $sourceCode], [$toCode, 'PKR']]);
                        if ($direct !== null && $direct > 0) return $this->exchangeRateToPkrCache[$sourceCurrency] = $direct;
                        $inverse = $this->latestRateValue($table, $rateCol, $dateCol, $idCol, [[$fromCode, 'PKR'], [$toCode, $sourceCode]]);
                        if ($inverse !== null && $inverse > 0) return $this->exchangeRateToPkrCache[$sourceCurrency] = (1 / $inverse);
                    }
                }

                $fromId = $this->firstColumn($columns, ['from_currency_id','source_currency_id','base_currency_id']);
                $toId = $this->firstColumn($columns, ['to_currency_id','target_currency_id','quote_currency_id']);
                if ($fromId && $toId && $sourceId && $pkrId) {
                    $direct = $this->latestRateValue($table, $rateCol, $dateCol, $idCol, [[$fromId, $sourceId], [$toId, $pkrId]]);
                    if ($direct !== null && $direct > 0) return $this->exchangeRateToPkrCache[$sourceCurrency] = $direct;
                    $inverse = $this->latestRateValue($table, $rateCol, $dateCol, $idCol, [[$fromId, $pkrId], [$toId, $sourceId]]);
                    if ($inverse !== null && $inverse > 0) return $this->exchangeRateToPkrCache[$sourceCurrency] = (1 / $inverse);
                }

                // Common ERP contract: one foreign currency per row, with rate expressed in base/local PKR.
                $currencyCode = $this->firstColumn($columns, ['currency_code','code','iso_code','foreign_currency_code']);
                if ($currencyCode) {
                    foreach ($sourceCodes as $sourceCode) {
                        $value = $this->latestRateValue($table, $rateCol, $dateCol, $idCol, [[$currencyCode, $sourceCode]]);
                        if ($value !== null && $value > 0) return $this->exchangeRateToPkrCache[$sourceCurrency] = $value;
                    }
                }
                $currencyId = $this->firstColumn($columns, ['currency_id','foreign_currency_id']);
                if ($currencyId && $sourceId) {
                    $value = $this->latestRateValue($table, $rateCol, $dateCol, $idCol, [[$currencyId, $sourceId]]);
                    if ($value !== null && $value > 0) return $this->exchangeRateToPkrCache[$sourceCurrency] = $value;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $this->exchangeRateToPkrCache[$sourceCurrency] = null;
    }

    /** @param list<array{0:string,1:mixed}> $where */
    private function latestRateValue(string $table, string $rateCol, ?string $dateCol, ?string $idCol, array $where): ?float
    {
        try {
            $query = DB::table($table);
            foreach ($where as [$column, $value]) $query->where($column, $value);
            if ($dateCol) $query->orderByDesc($dateCol);
            if ($idCol && $idCol !== $dateCol) $query->orderByDesc($idCol);
            $raw = $query->value($rateCol);
            if ($raw !== null && $raw !== '' && is_numeric($raw)) return (float) $raw;
        } catch (Throwable) {}
        return null;
    }

    private function currencyIdByCode(string $code): ?int
    {
        $code = $this->normalizeCurrencyCode($code);
        foreach (['currencies','currency_master','currency_masters','travel_currencies'] as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = $this->physicalColumnListing($table);
                $id = $this->firstColumn($columns, ['id','currency_id']);
                $codeCol = $this->firstColumn($columns, ['code','currency_code','iso_code']);
                if (! $id || ! $codeCol) continue;
                $codes = $code === 'SAR' ? ['SAR', 'SR'] : [$code];
                foreach ($codes as $candidateCode) {
                    $value = DB::table($table)->where($codeCol, $candidateCode)->value($id);
                    if ($value !== null && (int) $value > 0) return (int) $value;
                }
            } catch (Throwable) {}
        }
        return null;
    }

    /** @param list<array<string,mixed>> $rows @param list<array<string,mixed>> $routes */
    private function hydrateForeignCostCommercials(array $rows, array $routes): array
    {
        $byKey = [];
        foreach ($routes as $route) {
            $source = strtolower(trim((string) ($route['source_table'] ?? '')));
            $id = (int) ($route['id'] ?? 0);
            if ($source !== '' && $id > 0) $byKey[$source.':'.$id] = $route;
        }
        foreach ($rows as &$row) {
            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $source = strtolower(trim((string) ($row['route_source_table'] ?? '')));
            $id = (int) ($row['route_master_id'] ?? 0);
            $master = $byKey[$source.':'.$id] ?? null;
            if (! is_array($master)) {
                foreach ($routes as $candidate) {
                    if (strtolower(trim((string) ($candidate['name'] ?? ''))) !== strtolower(trim((string) ($row['route_name'] ?? '')))) continue;
                    $candidateVehicle = strtolower(trim((string) ($candidate['vehicle_type'] ?? '')));
                    $rowVehicle = strtolower(trim((string) ($row['vehicle_type'] ?? '')));
                    if ($candidateVehicle !== '' && $rowVehicle !== '' && $candidateVehicle !== $rowVehicle) continue;
                    $master = $candidate;
                    break;
                }
            }

            if (! array_key_exists('cost_rate', $row) || (float) ($row['cost_rate'] ?? 0) <= 0) {
                if (is_array($master) && is_numeric($master['rate_amount'] ?? null)) $row['cost_rate'] = round((float) $master['rate_amount'], 2);
                else $row['cost_rate'] = round(((float) ($row['cost_amount'] ?? 0)) / max(1, $quantity), 2);
            }
            $currency = $this->normalizeCurrencyCode((string) ($row['cost_currency'] ?? ''));
            if ($currency === '' && is_array($master)) $currency = $this->normalizeCurrencyCode((string) ($master['rate_currency'] ?? ''));
            if ($currency === '') $currency = (str_contains($source, 'transport_rate') ? 'SAR' : 'PKR');
            $row['cost_currency'] = $currency;

            $fx = (float) ($row['exchange_rate'] ?? 0);
            if ($fx <= 0 && is_array($master) && is_numeric($master['exchange_rate_to_pkr'] ?? null)) $fx = (float) $master['exchange_rate_to_pkr'];
            if ($fx <= 0) $fx = (float) ($this->exchangeRateToPkr($currency) ?? 0);
            if ($fx <= 0 && $currency === 'PKR') $fx = 1.0;
            $row['exchange_rate'] = round($fx, 8);
            if ($fx > 0) $row['cost_amount'] = round((float) ($row['cost_rate'] ?? 0) * $quantity * $fx, 2);
            $row['margin'] = round((float) ($row['sale_amount'] ?? 0) - (float) ($row['cost_amount'] ?? 0), 2);
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function vehicleOptions(): array
    {
        try {
            return app(UnifiedGroupPackageDataSource::class)->transportVehicles()->map(static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'source_table' => trim((string) ($row['source_table'] ?? '')),
                'key' => trim((string) ($row['key'] ?? '')),
                'name' => trim((string) ($row['name'] ?? '')),
            ])->filter(static fn (array $row): bool => $row['name'] !== '')->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array{id:int,name:string}> */
    private function supplierOptions(): array
    {
        try {
            $vendors = app(UnifiedGroupPackageDataSource::class)->vendors();
            if ($vendors->isNotEmpty()) {
                return $vendors->map(static fn (array $row): array => [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => trim((string) ($row['name'] ?? '')),
                ])->filter(static fn (array $row): bool => $row['id'] > 0 && $row['name'] !== '')
                    ->unique('id')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
            }
        } catch (Throwable) {}
        return [];
    }

    private function resolveTransportTable(): ?string
    {
        foreach (['booking_transport_segments', 'booking_transports', 'transport_booking_details', 'booking_transport_details'] as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = $this->physicalColumnListing($table);
            if (in_array('booking_id', $columns, true) && $this->firstColumn($columns, ['route_label','route_name','route','pickup_location','from_location'])) return $table;
        }
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                if ($table === '' || ! str_starts_with(strtolower($table), 'booking_') || ! str_contains(strtolower($table), 'transport')) continue;
                $columns = $this->physicalColumnListing($table);
                if (in_array('booking_id', $columns, true)) return $table;
            }
        } catch (Throwable) {}
        return null;
    }

    /** @return array{id:int,row:array<string,mixed>}|null */
    private function findTransportService(int $booking): ?array
    {
        if (! Schema::hasTable('booking_services')) return null;
        $columns = $this->physicalColumnListing('booking_services');
        if (! in_array('booking_id', $columns, true) || ! in_array('id', $columns, true)) return null;
        try {
            foreach (DB::table('booking_services')->where('booking_id', $booking)->orderByDesc('id')->get() as $object) {
                $row = (array) $object;
                $text = strtolower(implode(' ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $row)));
                if (str_contains($text, 'transport') || str_contains($text, 'transfer')) return ['id' => (int) ($row['id'] ?? 0), 'row' => $row];
            }
        } catch (Throwable) {}
        return null;
    }

    /** @return array{id:int,row:array<string,mixed>} */
    private function ensureTransportService(int $booking, object $bookingRow): array
    {
        $existing = $this->findTransportService($booking);
        if ($existing) return $existing;

        $master = $this->resolveTransportProductService();
        if (! $master) {
            throw ValidationException::withMessages([
                'transport' => 'The Transport Product Service master could not be resolved. Confirm that Transport exists in Product/Service Master.',
            ]);
        }

        $table = 'booking_services';
        $columns = $this->physicalColumnListing($table);
        $prototype = null;
        if (in_array('product_service_id', $columns, true)) {
            $prototype = DB::table($table)->where('product_service_id', (int) $master['id'])->orderByDesc('id')->first();
        }
        if (! $prototype) $prototype = DB::table($table)->orderByDesc('id')->first();

        $row = ['booking_id' => $booking];
        if (in_array('product_service_id', $columns, true)) $row['product_service_id'] = (int) $master['id'];
        $bookingData = (array) $bookingRow;
        foreach (['company_id','branch_id','customer_id','agent_id','salesperson_id','currency_id','tenant_id','office_id'] as $field) {
            if (in_array($field, $columns, true) && array_key_exists($field, $bookingData)) $row[$field] = $bookingData[$field];
        }
        $masterRow = (array) ($master['row'] ?? []);
        $name = $this->firstNonEmpty($masterRow, ['name','service_name','title','label','description']) ?: 'Transport';
        $code = $this->firstNonEmpty($masterRow, ['code','service_code','product_code','slug']);
        $this->put($row, $columns, ['service_name','name','title'], $name);
        $this->put($row, $columns, ['service_code','product_code','code'], $code ?: null);
        $this->put($row, $columns, ['quantity','qty'], 1);
        $this->putNativeEnum($row, $table, $columns, ['status'], 'active', ['ACTIVE','booked','BOOKED','requested','REQUESTED']);
        foreach (['is_active' => 1, 'active' => 1] as $field => $value) if (in_array($field, $columns, true)) $row[$field] = $value;
        foreach (['created_by','created_by_id','updated_by','updated_by_id','user_id'] as $field) if (in_array($field, $columns, true) && Auth::id()) $row[$field] = Auth::id();
        if (in_array('created_at', $columns, true)) $row['created_at'] = now();
        if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();

        $row = $this->fillRequiredByPrototype($table, $row, $prototype ? (array) $prototype : [], [
            'booking_id' => $booking,
            'product_service_id' => (int) $master['id'],
            'service_name' => $name,
            'name' => $name,
            'title' => $name,
            'status' => 'active',
            'quantity' => 1,
            'qty' => 1,
        ]);
        $this->assertRequiredContract($table, $row, 'Transport service');
        $id = (int) DB::table($table)->insertGetId($row);
        return ['id' => $id, 'row' => $row + ['id' => $id]];
    }

    /** @return array<string,mixed>|null */
    private function resolveTransportProductService(): ?array
    {
        if (! Schema::hasTable('booking_services')) return null;
        $tables = [];
        try {
            foreach (Schema::getForeignKeys('booking_services') as $foreign) {
                $locals = (array) ($foreign['columns'] ?? $foreign['local_columns'] ?? []);
                if (! in_array('product_service_id', $locals, true)) continue;
                $table = (string) ($foreign['foreign_table'] ?? $foreign['foreign_table_name'] ?? $foreign['table'] ?? '');
                if ($table !== '' && Schema::hasTable($table)) $tables[] = $table;
            }
        } catch (Throwable) {}
        foreach (['product_services','product_service_master','product_service_masters','travel_product_services','service_products'] as $table) if (Schema::hasTable($table)) $tables[] = $table;
        $best = null; $bestScore = 0;
        foreach (array_values(array_unique($tables)) as $table) {
            try {
                $columns = $this->physicalColumnListing($table);
                $idColumn = $this->firstColumn($columns, ['id','product_service_id']);
                if (! $idColumn) continue;
                foreach (DB::table($table)->limit(4000)->get() as $object) {
                    $row = (array) $object; $id = (int) ($row[$idColumn] ?? 0); if ($id <= 0) continue;
                    $text = strtolower(implode(' ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $row)));
                    $score = 0;
                    if (str_contains($text, 'transport')) $score += 10000;
                    if (str_contains($text, 'transfer')) $score += 5000;
                    if (str_contains($text, 'vehicle')) $score += 1200;
                    if (str_contains($text, 'hotel')) $score -= 7000;
                    if (str_contains($text, 'air ticket')) $score -= 7000;
                    if (str_contains($text, 'visa')) $score -= 5000;
                    if ($score > $bestScore) { $bestScore = $score; $best = ['id' => $id, 'table' => $table, 'row' => $row]; }
                }
            } catch (Throwable) {}
        }
        return $bestScore >= 3000 ? $best : null;
    }

    private function resolveBookingServiceLinkColumn(string $table, array $columns): ?string
    {
        if (in_array('booking_service_id', $columns, true)) return 'booking_service_id';
        try {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $foreignTable = strtolower((string) ($foreign['foreign_table'] ?? $foreign['foreign_table_name'] ?? $foreign['table'] ?? ''));
                if ($foreignTable !== 'booking_services') continue;
                foreach ((array) ($foreign['columns'] ?? $foreign['local_columns'] ?? []) as $local) {
                    $local = (string) $local;
                    if ($local !== '' && in_array($local, $columns, true)) return $local;
                }
            }
        } catch (Throwable) {}
        return in_array('booking_service_id', $columns, true) ? 'booking_service_id' : null;
    }

    /** @return list<array<string,mixed>> */
    private function transportRows(int $booking, int $serviceId, string $table): array
    {
        $columns = $this->physicalColumnListing($table);
        $query = DB::table($table)->where('booking_id', $booking);
        $serviceCol = $this->resolveBookingServiceLinkColumn($table, $columns);
        if ($serviceId > 0 && $serviceCol) $query->where($serviceCol, $serviceId);
        $order = $this->firstColumn($columns, ['sort_order','sequence','sequence_no','id']);
        try {
            $records = $order ? $query->orderBy($order)->get() : $query->get();
            return $records->map(function (object $object) use ($columns): array {
                $row = (array) $object;
                $sale = $this->numberFromMeaningful($row, $columns, ['sale_amount','selling_total','customer_total','sale_total','total_sale','customer_amount','selling_amount','customer_price','sale_price','selling_price']);
                $cost = $this->numberFromMeaningful($row, $columns, ['supplier_amount','vendor_total','cost_total','total_cost','supplier_cost','vendor_cost','cost_amount','purchase_price','cost_price']);
                $costRate = $this->numberFromMeaningful($row, $columns, ['supplier_rate','vendor_rate','cost_rate','unit_cost','supplier_unit_cost','vendor_unit_cost']);
                $costCurrency = strtoupper(trim((string) ($this->valueFrom($row, $columns, ['cost_rate_currency_code','rate_currency','source_currency_code','cost_currency','cost_currency_code','supplier_currency_code','vendor_currency_code']) ?? '')));
                $exchangeRate = $this->numberFromMeaningful($row, $columns, ['exchange_rate','supplier_exchange_rate','vendor_exchange_rate','cost_exchange_rate']);
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'route_master_id' => (int) ($this->valueFrom($row, $columns, ['rate_card_id','route_master_id','route_id']) ?? 0),
                    'route_source_table' => '',
                    'route_name' => trim((string) ($this->valueFrom($row, $columns, ['route_label','route_name','route']) ?? '')) ?: $this->composeRoute($row, $columns),
                    'vehicle_master_id' => (int) ($this->valueFrom($row, $columns, ['vehicle_master_id','vehicle_id','vehicle_type_id']) ?? 0),
                    'vehicle_source_table' => '',
                    'vehicle_type' => trim((string) ($this->valueFrom($row, $columns, ['vehicle_type','vehicle_name','vehicle']) ?? '')),
                    'quantity' => max(1, (int) ($this->valueFrom($row, $columns, ['vehicle_qty','vehicle_quantity','quantity','qty']) ?? 1)),
                    'driver_name' => trim((string) ($this->valueFrom($row, $columns, ['driver_name','driver','chauffeur_name']) ?? '')),
                    'driver_cell' => trim((string) ($this->valueFrom($row, $columns, ['driver_cell','driver_contact','contact_number','provider_contact','phone','mobile','cell_number']) ?? '')),
                    'plate_number' => trim((string) ($this->valueFrom($row, $columns, ['plate_number','plate_no','vehicle_plate','registration_number','registration_no']) ?? '')),
                    'vendor_id' => (int) ($this->valueFrom($row, $columns, ['vendor_id','supplier_id','service_provider_id']) ?? 0),
                    'company_name' => trim((string) ($this->valueFrom($row, $columns, ['company_name','provider_name','transport_company','vendor_name','supplier_name']) ?? '')),
                    'brn_number' => trim((string) ($this->valueFrom($row, $columns, ['brn_number','brn','provider_reference','booking_reference','reference']) ?? '')),
                    'sale_amount' => round($sale, 2),
                    'cost_rate' => round($costRate, 2),
                    'cost_currency' => $costCurrency,
                    'exchange_rate' => round($exchangeRate, 8),
                    'cost_amount' => round($cost, 2),
                    'margin' => round($sale - $cost, 2),
                    'notes' => trim((string) ($this->valueFrom($row, $columns, ['voucher_notes','notes','remarks','description']) ?? '')),
                ];
            })->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function composeRoute(array $row, array $columns): string
    {
        $from = trim((string) ($this->valueFrom($row, $columns, ['pickup_location','from_location','origin','from_city']) ?? ''));
        $to = trim((string) ($this->valueFrom($row, $columns, ['dropoff_location','to_location','destination','to_city']) ?? ''));
        return trim($from.(($from !== '' && $to !== '') ? ' → ' : '').$to);
    }

    /** @param list<array<string,mixed>> $rows */
    private function syncNativeTransportRows(int $booking, int $serviceId, object $bookingRow, string $table, array $rows): void
    {
        $columns = $this->physicalColumnListing($table);
        $serviceCol = $this->resolveBookingServiceLinkColumn($table, $columns);
        $delete = DB::table($table)->where('booking_id', $booking);
        if ($serviceId > 0 && $serviceCol) $delete->where($serviceCol, $serviceId);
        $delete->delete();

        $prototype = null;
        try { $prototype = DB::table($table)->orderByDesc($this->firstColumn($columns, ['id','sort_order','sequence']) ?: 'booking_id')->first(); } catch (Throwable) {}
        $bookingData = (array) $bookingRow;

        foreach ($rows as $index => $transport) {
            $row = [];
            $this->put($row, $columns, ['booking_id'], $booking);
            if ($serviceCol) $row[$serviceCol] = $serviceId;
            foreach (['company_id','branch_id','customer_id','agent_id','salesperson_id','currency_id','tenant_id','office_id'] as $field) {
                if (in_array($field, $columns, true) && array_key_exists($field, $bookingData)) $row[$field] = $bookingData[$field];
            }
            if (($transport['route_source_table'] ?? '') === 'transport_rate_cards') $this->put($row, $columns, ['rate_card_id'], (int) ($transport['route_master_id'] ?? 0) ?: null);
            $this->put($row, $columns, ['route_master_id','route_id'], (int) ($transport['route_master_id'] ?? 0) ?: null);
            $this->put($row, $columns, ['route_label','route_name','route'], $transport['route_name']);
            [$from, $to] = $this->splitRoute((string) $transport['route_name']);
            $this->put($row, $columns, ['pickup_location','from_location','origin','from_city'], $from ?: null);
            $this->put($row, $columns, ['dropoff_location','to_location','destination','to_city'], $to ?: null);
            $this->put($row, $columns, ['vehicle_master_id','vehicle_id','vehicle_type_id'], (int) ($transport['vehicle_master_id'] ?? 0) ?: null);
            $this->put($row, $columns, ['vehicle_type','vehicle_name','vehicle'], $transport['vehicle_type']);
            $this->putAll($row, $columns, ['vehicle_qty','vehicle_quantity','quantity','qty'], (int) $transport['quantity']);
            $this->putAll($row, $columns, ['driver_name','driver','chauffeur_name'], $transport['driver_name'] ?: null);
            $this->putAll($row, $columns, ['driver_cell','driver_contact','contact_number','provider_contact','cell_number'], $transport['driver_cell'] ?: null);
            $this->putAll($row, $columns, ['plate_number','plate_no','vehicle_plate','registration_number','registration_no'], $transport['plate_number'] ?: null);
            $this->put($row, $columns, ['vendor_id','supplier_id','service_provider_id'], (int) ($transport['vendor_id'] ?? 0) ?: null);
            $this->putAll($row, $columns, ['company_name','provider_name','transport_company','vendor_name','supplier_name'], $transport['company_name'] ?: null);
            $this->putAll($row, $columns, ['brn_number','brn','provider_reference','booking_reference','reference'], $transport['brn_number'] ?: null);
            $this->putAll($row, $columns, ['sale_amount','selling_total','customer_total','sale_total','total_sale','customer_amount','selling_amount','customer_price','sale_price','selling_price'], $transport['sale_amount']);
            $this->putAll($row, $columns, ['supplier_rate','vendor_rate','cost_rate','unit_cost','supplier_unit_cost','vendor_unit_cost'], $transport['cost_rate']);
            $this->putAll($row, $columns, ['exchange_rate','supplier_exchange_rate','vendor_exchange_rate','cost_exchange_rate'], $transport['exchange_rate']);
            $this->putAll($row, $columns, ['supplier_amount','vendor_total','cost_total','total_cost','supplier_cost','vendor_cost','cost_amount','purchase_price','cost_price'], $transport['cost_amount']);
            $this->putAll($row, $columns, ['margin','gross_margin','net_margin','profit'], $transport['margin']);
            $this->putAll($row, $columns, ['voucher_notes','notes','remarks','description'], $transport['notes'] ?: null);
            $this->put($row, $columns, ['sort_order','sequence','sequence_no'], ($index + 1) * 10);
            $this->putNativeEnum($row, $table, $columns, ['service_mode'], 'general', ['GENERAL','booking','BOOKING','service','SERVICE','package','PACKAGE']);
            $this->putNativeEnum($row, $table, $columns, ['status'], 'requested', ['REQUESTED','booked','BOOKED','confirmed','CONFIRMED','active','ACTIVE']);
            if (in_array('commercial_locked', $columns, true)) $row['commercial_locked'] = 0;
            if (in_array('sale_currency_code', $columns, true)) $row['sale_currency_code'] = 'PKR';
            // Native monetary totals remain PKR for accounting compatibility. Source
            // currency is carried separately (and losslessly in the Transport snapshot).
            foreach (['supplier_currency_code','vendor_currency_code'] as $field) if (in_array($field, $columns, true)) $row[$field] = 'PKR';
            foreach (['cost_rate_currency_code','rate_currency','source_currency_code','cost_currency'] as $field) if (in_array($field, $columns, true)) $row[$field] = $transport['cost_currency'];
            foreach (['created_by','created_by_id','updated_by','updated_by_id','user_id'] as $field) if (in_array($field, $columns, true) && Auth::id()) $row[$field] = Auth::id();
            if (in_array('created_at', $columns, true)) $row['created_at'] = now();
            if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();

            $known = [
                'booking_id' => $booking,
                'booking_service_id' => $serviceId,
                'route_name' => $transport['route_name'],
                'vehicle_type' => $transport['vehicle_type'],
                'company_name' => $transport['company_name'],
                'driver_name' => $transport['driver_name'],
                'driver_cell' => $transport['driver_cell'],
                'plate_number' => $transport['plate_number'],
                'brn_number' => $transport['brn_number'],
                'status' => 'requested',
                'quantity' => $transport['quantity'],
            ];
            $row = $this->fillRequiredByPrototype($table, $row, $prototype ? (array) $prototype : [], $known);
            $this->assertRequiredContract($table, $row, 'Transport row '.($index + 1));
            DB::table($table)->insert($row);
        }
    }

    /** @return array{0:string,1:string} */
    private function splitRoute(string $route): array
    {
        foreach (['→','->','–','—'] as $delimiter) {
            if (str_contains($route, $delimiter)) {
                $parts = array_map('trim', explode($delimiter, $route, 2));
                return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
            }
        }
        return [$route, ''];
    }

    /** @param list<array<string,mixed>> $rows */
    private function syncServiceSnapshot(int $serviceId, array $rows): void
    {
        if ($serviceId <= 0 || ! Schema::hasTable('booking_services')) return;
        $table = 'booking_services';
        $columns = $this->physicalColumnListing($table);
        $current = (array) (DB::table($table)->where('id', $serviceId)->first() ?? (object) []);
        $payload = ['version' => 'ERP-11.3.141', 'transports' => array_values($rows)];
        $update = [];
        foreach ($this->jsonCarrierFields($table, $columns) as $field) {
            $existing = [];
            $raw = $current[$field] ?? null;
            if (is_array($raw)) $existing = $raw;
            elseif ($raw !== null && $raw !== '') {
                $decoded = json_decode((string) $raw, true);
                if (! is_array($decoded)) continue;
                $existing = $decoded;
            }
            $existing['et_erp_transport_rows'] = $payload;
            $update[$field] = json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;
        }
        if (! $update) {
            foreach ($this->taggedTextCarrierFields($table, $columns) as $field) {
                $update[$field] = $this->writeTaggedPayload((string) ($current[$field] ?? ''), 'ETERP_TRANSPORT_ROWS', $payload);
                break;
            }
        }
        if (! $update && ! $this->resolveTransportTable()) {
            throw ValidationException::withMessages([
                'transport' => 'Transport Data has no compatible native row table or booking-service snapshot carrier on this installation.',
            ]);
        }
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if ($update) DB::table($table)->where('id', $serviceId)->update($update);
    }

    /** @return list<array<string,mixed>> */
    private function snapshotFromServiceRow(array $row): array
    {
        $table = 'booking_services'; $columns = array_keys($row);
        foreach ($this->jsonCarrierFields($table, $columns) as $field) {
            if (! array_key_exists($field, $row)) continue;
            $raw = $row[$field]; $decoded = is_array($raw) ? $raw : json_decode((string) ($raw ?? ''), true);
            if (! is_array($decoded)) continue;
            $snapshot = $decoded['et_erp_transport_rows']['transports'] ?? null;
            if (is_array($snapshot)) return array_values(array_filter($snapshot, 'is_array'));
        }
        foreach ($this->taggedTextCarrierFields($table, $columns) as $field) {
            if (! array_key_exists($field, $row)) continue;
            $payload = $this->readTaggedPayload((string) ($row[$field] ?? ''), 'ETERP_TRANSPORT_ROWS');
            $snapshot = is_array($payload) ? ($payload['transports'] ?? null) : null;
            if (is_array($snapshot)) return array_values(array_filter($snapshot, 'is_array'));
        }
        return [];
    }

    /** @param list<array<string,mixed>> $rows @param list<array<string,mixed>> $snapshot */
    private function overlaySnapshot(array $rows, array $snapshot): array
    {
        if (! $snapshot) return $rows;
        while (count($rows) < count($snapshot)) {
            $saved = $snapshot[count($rows)] ?? null;
            if (! is_array($saved)) break;
            $rows[] = $this->normalizeSnapshotRow($saved);
        }
        foreach ($rows as $index => &$row) {
            $saved = $snapshot[$index] ?? null;
            if (! is_array($saved)) continue;
            $saved = $this->normalizeSnapshotRow($saved);
            foreach (['route_master_id','vehicle_master_id','quantity','vendor_id'] as $field) {
                if ((int) ($saved[$field] ?? 0) > 0) $row[$field] = (int) $saved[$field];
            }
            foreach (['route_source_table','route_name','vehicle_source_table','vehicle_type','driver_name','driver_cell','plate_number','company_name','brn_number','cost_currency','notes'] as $field) {
                if (trim((string) ($saved[$field] ?? '')) !== '') $row[$field] = trim((string) $saved[$field]);
            }
            foreach (['sale_amount','cost_rate','cost_amount','margin'] as $field) $row[$field] = round((float) ($saved[$field] ?? 0), 2);
            $row['exchange_rate'] = round((float) ($saved['exchange_rate'] ?? 0), 8);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function normalizeSnapshotRow(array $row): array
    {
        $sale = round((float) ($row['sale_amount'] ?? 0), 2);
        $cost = round((float) ($row['cost_amount'] ?? 0), 2);
        $costRate = round((float) ($row['cost_rate'] ?? $cost), 2);
        $costCurrency = $this->normalizeCurrencyCode((string) ($row['cost_currency'] ?? ''));
        if ($costCurrency === '') $costCurrency = 'PKR';
        $exchangeRate = round((float) ($row['exchange_rate'] ?? ($costCurrency === 'PKR' ? 1 : 0)), 8);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'route_master_id' => max(0, (int) ($row['route_master_id'] ?? 0)),
            'route_source_table' => trim((string) ($row['route_source_table'] ?? '')),
            'route_name' => trim((string) ($row['route_name'] ?? '')),
            'vehicle_master_id' => max(0, (int) ($row['vehicle_master_id'] ?? 0)),
            'vehicle_source_table' => trim((string) ($row['vehicle_source_table'] ?? '')),
            'vehicle_type' => trim((string) ($row['vehicle_type'] ?? '')),
            'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
            'driver_name' => trim((string) ($row['driver_name'] ?? '')),
            'driver_cell' => trim((string) ($row['driver_cell'] ?? '')),
            'plate_number' => trim((string) ($row['plate_number'] ?? '')),
            'vendor_id' => max(0, (int) ($row['vendor_id'] ?? 0)),
            'company_name' => trim((string) ($row['company_name'] ?? '')),
            'brn_number' => trim((string) ($row['brn_number'] ?? '')),
            'sale_amount' => $sale,
            'cost_rate' => $costRate,
            'cost_currency' => $costCurrency,
            'exchange_rate' => $exchangeRate,
            'cost_amount' => $cost,
            'margin' => round($sale - $cost, 2),
            'notes' => trim((string) ($row['notes'] ?? '')),
        ];
    }

    private function syncServiceTotals(int $serviceId, array $summary): void
    {
        if ($serviceId <= 0 || ! Schema::hasTable('booking_services')) return;
        $columns = $this->physicalColumnListing('booking_services'); $update = [];
        $this->putAll($update, $columns, ['selling_total','customer_total','sale_total','total_sale','customer_amount','selling_amount'], $summary['customer_total']);
        $this->putAll($update, $columns, ['net_supplier_cost','supplier_total','vendor_total','cost_total','total_cost','supplier_amount','vendor_amount'], $summary['vendor_total']);
        $this->putAll($update, $columns, ['margin','gross_margin','net_margin','profit'], $summary['margin']);
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if ($update) DB::table('booking_services')->where('id', $serviceId)->update($update);
    }

    /** @param list<array<string,mixed>> $rows */
    private function syncServiceVendorContext(int $serviceId, array $rows): void
    {
        if ($serviceId <= 0 || ! Schema::hasTable('booking_services')) return;
        $unique = [];
        foreach ($rows as $row) {
            $id = (int) ($row['vendor_id'] ?? 0); $name = trim((string) ($row['company_name'] ?? ''));
            if ($id > 0 || $name !== '') $unique[($id > 0 ? 'id:'.$id : 'name:'.strtolower($name))] = ['id' => $id, 'name' => $name];
        }
        if (count($unique) !== 1) return;
        $vendor = array_values($unique)[0]; $columns = $this->physicalColumnListing('booking_services'); $update = [];
        if ((int) $vendor['id'] > 0) $this->put($update, $columns, ['supplier_id','vendor_id','service_provider_id'], (int) $vendor['id']);
        $this->putAll($update, $columns, ['supplier_name','vendor_name','provider_name'], (string) $vendor['name']);
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if ($update) DB::table('booking_services')->where('id', $serviceId)->update($update);
    }

    /** @return array{customer_total:float,vendor_total:float,margin:float,transport_count:int,vehicle_count:int} */
    private function summary(array $rows): array
    {
        $customer = 0.0; $vendor = 0.0; $vehicles = 0;
        foreach ($rows as $row) {
            $customer += (float) ($row['sale_amount'] ?? 0);
            $vendor += (float) ($row['cost_amount'] ?? 0);
            $vehicles += max(1, (int) ($row['quantity'] ?? 1));
        }
        return [
            'customer_total' => round($customer, 2),
            'vendor_total' => round($vendor, 2),
            'margin' => round($customer - $vendor, 2),
            'transport_count' => count($rows),
            'vehicle_count' => $vehicles,
        ];
    }

    private function assertPersisted(array $expected, array $actual): void
    {
        if (count($actual) < count($expected)) {
            throw ValidationException::withMessages(['transport' => 'Transport save verification failed: not all rows could be reloaded.']);
        }
        foreach ($expected as $index => $row) {
            $saved = $actual[$index] ?? [];
            foreach (['route_name','vehicle_type','driver_name','driver_cell','plate_number','company_name','brn_number'] as $field) {
                if (trim((string) ($row[$field] ?? '')) !== trim((string) ($saved[$field] ?? ''))) {
                    throw ValidationException::withMessages(['transport' => 'Transport save verification failed on row '.($index + 1).' field '.$field.'.']);
                }
            }
            foreach (['sale_amount','cost_rate','cost_amount'] as $field) {
                if (abs((float) ($row[$field] ?? 0) - (float) ($saved[$field] ?? 0)) > 0.01) {
                    throw ValidationException::withMessages(['transport' => 'Transport commercial save verification failed on row '.($index + 1).'.']);
                }
            }
            if (abs((float) ($row['exchange_rate'] ?? 0) - (float) ($saved['exchange_rate'] ?? 0)) > 0.000001) {
                throw ValidationException::withMessages(['transport' => 'Transport exchange-rate save verification failed on row '.($index + 1).'.']);
            }
        }
    }

    private function snapshotCarrierAvailable(string $table, array $columns): bool
    {
        return count($this->jsonCarrierFields($table, $columns)) > 0 || count($this->taggedTextCarrierFields($table, $columns)) > 0;
    }

    /** @return list<string> */
    private function jsonCarrierFields(string $table, array $columns): array
    {
        $preferred = ['meta','metadata','extra_data','details_json','attributes']; $result = [];
        foreach ($preferred as $field) if (in_array($field, $columns, true)) $result[] = $field;
        $metadata = $this->columnMetadata($table);
        foreach ($columns as $field) {
            if (in_array($field, $result, true)) continue;
            $type = $this->metaType($metadata[$field] ?? []);
            if ($type === 'json' || (in_array($type, ['text','tinytext','mediumtext','longtext'], true) && preg_match('/(meta|json|data|details|attributes)/i', $field))) $result[] = $field;
        }
        return $result;
    }

    /** @return list<string> */
    private function taggedTextCarrierFields(string $table, array $columns): array
    {
        $metadata = $this->columnMetadata($table); $result = [];
        foreach (['notes','remarks','internal_notes','description','details','other_details','comment','comments'] as $field) {
            if (! in_array($field, $columns, true)) continue;
            if (in_array($this->metaType($metadata[$field] ?? []), ['char','varchar','text','tinytext','mediumtext','longtext'], true)) $result[] = $field;
        }
        return $result;
    }

    private function readTaggedPayload(string $text, string $tag): ?array
    {
        if ($text === '' || ! preg_match('/\[\['.preg_quote($tag, '/').':([A-Za-z0-9+\/=]+)\]\]/', $text, $match)) return null;
        $json = base64_decode((string) ($match[1] ?? ''), true); if ($json === false) return null;
        $decoded = json_decode($json, true); return is_array($decoded) ? $decoded : null;
    }

    private function writeTaggedPayload(string $text, string $tag, array $payload): string
    {
        $encoded = base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $marker = '[['.$tag.':'.$encoded.']]';
        $clean = preg_replace('/\s*\[\['.preg_quote($tag, '/').':[A-Za-z0-9+\/=]+\]\]\s*/', '', $text) ?? $text;
        $clean = trim($clean); return $clean === '' ? $marker : $clean."\n".$marker;
    }

    private function shortDatabaseMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message));
        return strlen($message) > 260 ? substr($message, 0, 257).'...' : $message;
    }

    private function fillRequiredByPrototype(string $table, array $row, array $prototype, array $known): array
    {
        $metadata = $this->columnMetadata($table);
        foreach ($metadata as $field => $meta) {
            if ($this->columnCanBeOmitted($field, $meta)) continue;
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') continue;
            if (array_key_exists($field, $known) && $known[$field] !== null && $known[$field] !== '') { $row[$field] = $known[$field]; continue; }
            $generated = $this->generatedRequiredValue($field, $known); if ($generated !== null) { $row[$field] = $generated; continue; }
            if (array_key_exists($field, $prototype) && $prototype[$field] !== null && $prototype[$field] !== '') { $row[$field] = $prototype[$field]; continue; }
            $fallback = $this->safeRequiredFallback($field, $meta, $known); if ($fallback !== null) $row[$field] = $fallback;
        }
        return array_intersect_key($row, array_flip($metadata ? array_keys($metadata) : $this->physicalColumnListing($table)));
    }

    private function generatedRequiredValue(string $field, array $known): mixed
    {
        $lower = strtolower($field);
        if ($lower === 'uuid' || str_ends_with($lower, '_uuid')) return (string) Str::uuid();
        if ($lower === 'public_id' || str_ends_with($lower, '_public_id')) return strtoupper(Str::random(16));
        if ($lower === 'slug') return Str::slug((string) ($known['route_name'] ?? $known['name'] ?? 'transport')).'-'.strtolower(Str::random(5));
        return null;
    }

    private function safeRequiredFallback(string $field, array $meta, array $known): mixed
    {
        $lower = strtolower($field); $type = $this->metaType($meta);
        if (str_contains($lower, 'status')) {
            $enum = $this->enumValues($meta);
            foreach (['requested','REQUESTED','booked','BOOKED','active','ACTIVE','pending','PENDING','draft','DRAFT'] as $candidate) if (! $enum || in_array($candidate, $enum, true)) return $candidate;
        }
        if (str_ends_with($lower, '_id')) return null;
        if ($type === 'enum') { $enum = $this->enumValues($meta); if ($enum) return $enum[0]; }
        if (in_array($type, ['int','integer','bigint','smallint','tinyint','mediumint','decimal','numeric','float','double','real'], true)) return 0;
        if ($type === 'date') return date('Y-m-d');
        if (in_array($type, ['datetime','timestamp'], true)) return now();
        if (in_array($type, ['char','varchar','text','tinytext','mediumtext','longtext','enum'], true)) {
            if (str_contains($lower, 'route')) return (string) ($known['route_name'] ?? 'Transport');
            if (str_contains($lower, 'vehicle')) return (string) ($known['vehicle_type'] ?? 'Transport');
            if (str_contains($lower, 'driver')) return (string) ($known['driver_name'] ?? 'N/A');
            if (str_contains($lower, 'plate') || str_contains($lower, 'registration')) return (string) ($known['plate_number'] ?? 'N/A');
            if (str_contains($lower, 'company') || str_contains($lower, 'provider') || str_contains($lower, 'vendor') || str_contains($lower, 'supplier')) return (string) ($known['company_name'] ?? 'N/A');
            if (str_contains($lower, 'reference') || str_contains($lower, 'brn') || str_ends_with($lower, '_no')) return (string) ($known['brn_number'] ?? 'N/A');
            return 'N/A';
        }
        return null;
    }

    private function assertRequiredContract(string $table, array $row, string $stage): void
    {
        $metadata = $this->columnMetadata($table); if (! $metadata) return; $missing = [];
        foreach ($metadata as $field => $meta) {
            if ($this->columnCanBeOmitted($field, $meta)) continue;
            if (! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') $missing[] = $field;
        }
        if ($missing) throw ValidationException::withMessages(['transport' => 'Native Transport preflight found unresolved required field(s) in '.$stage.': '.implode(', ', array_slice($missing, 0, 20)).'. No Transport data was written.']);
    }

    /** @return array<string,array<string,mixed>> */
    private function columnMetadata(string $table): array
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
        $result = [];
        try {
            foreach (DB::select('SHOW FULL COLUMNS FROM `'.$table.'`') as $column) {
                $raw = (array) $column; $name = (string) ($raw['Field'] ?? ''); if ($name === '') continue;
                $result[$name] = [
                    'name' => $name,
                    'type' => strtolower((string) ($raw['Type'] ?? '')),
                    'type_name' => strtolower((string) ($raw['Type'] ?? '')),
                    'nullable' => strtoupper((string) ($raw['Null'] ?? 'NO')) === 'YES',
                    'default' => $raw['Default'] ?? null,
                    'extra' => strtolower((string) ($raw['Extra'] ?? '')),
                    'auto_increment' => str_contains(strtolower((string) ($raw['Extra'] ?? '')), 'auto_increment'),
                ];
            }
        } catch (Throwable) {}
        if ($result) return $result;
        try {
            foreach (Schema::getColumns($table) as $column) {
                if (! is_array($column)) continue; $name = (string) ($column['name'] ?? $column['column_name'] ?? ''); if ($name !== '') $result[$name] = $column;
            }
        } catch (Throwable) {}
        return $result;
    }

    /** @return list<string> */
    private function physicalColumnListing(string $table): array { return array_values(array_keys($this->columnMetadata($table))); }

    private function columnCanBeOmitted(string $field, array $meta): bool
    {
        if ($field === 'id') return true;
        if ((bool) ($meta['nullable'] ?? false)) return true;
        if (array_key_exists('default', $meta) && $meta['default'] !== null) return true;
        if ((bool) ($meta['auto_increment'] ?? false)) return true;
        return str_contains(strtolower((string) ($meta['extra'] ?? '')), 'auto_increment');
    }

    private function metaType(array $meta): string
    {
        $type = strtolower(trim((string) ($meta['type_name'] ?? $meta['type'] ?? '')));
        return preg_match('/^([a-z]+)/', $type, $match) ? (string) $match[1] : $type;
    }

    /** @return list<string> */
    private function enumValues(array $meta): array
    {
        $type = (string) ($meta['type'] ?? $meta['type_name'] ?? '');
        if (! preg_match('/^enum\((.*)\)$/i', $type, $match)) return [];
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $match[1], $values);
        return array_map(static fn (string $value): string => stripcslashes($value), $values[1] ?? []);
    }

    private function putNativeEnum(array &$row, string $table, array $columns, array $fields, string $preferred, array $fallbacks = []): void
    {
        foreach ($fields as $field) {
            if (! in_array($field, $columns, true)) continue;
            $enum = $this->enumValues($this->columnMetadata($table)[$field] ?? []); $value = $preferred;
            if ($enum && ! in_array($value, $enum, true)) {
                foreach ($fallbacks as $candidate) if (in_array($candidate, $enum, true)) { $value = $candidate; break; }
                if (! in_array($value, $enum, true)) $value = $enum[0] ?? $preferred;
            }
            $row[$field] = $value; return;
        }
    }

    private function put(array &$row, array $columns, array $fields, mixed $value): void
    {
        foreach ($fields as $field) if (in_array($field, $columns, true)) { $row[$field] = $value; return; }
    }

    private function putAll(array &$row, array $columns, array $fields, mixed $value): void
    {
        foreach ($fields as $field) if (in_array($field, $columns, true)) $row[$field] = $value;
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate;
        return null;
    }

    private function firstNonEmpty(array $row, array $fields): string
    {
        foreach ($fields as $field) { $value = trim((string) ($row[$field] ?? '')); if ($value !== '') return $value; }
        return '';
    }

    private function valueFrom(array $row, array $columns, array $fields): mixed
    {
        foreach ($fields as $field) if (in_array($field, $columns, true) && array_key_exists($field, $row)) return $row[$field];
        return null;
    }

    private function numberFromMeaningful(array $row, array $columns, array $fields): float
    {
        $fallback = 0.0;
        foreach ($fields as $field) {
            if (! in_array($field, $columns, true) || ! array_key_exists($field, $row) || ! is_numeric($row[$field])) continue;
            $value = (float) $row[$field]; if (abs($value) > 0.000001) return $value; $fallback = $value;
        }
        return $fallback;
    }
}
