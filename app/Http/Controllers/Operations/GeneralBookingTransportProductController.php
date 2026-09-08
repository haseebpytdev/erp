<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use App\Services\Operations\BookingCommercialCompletenessResolver;
use App\Services\Operations\GenericServicePassengerLinkSynchronizer;
use App\Services\Operations\HotelTransportBookingServiceCommercialSynchronizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
    /** Native Product/Service Master authority for GENERAL Transport. */
    private const TRANSPORT_PRODUCT_SERVICE_ID = 4;

    public function __construct(
        private readonly GenericServicePassengerLinkSynchronizer $passengerLinks,
        private readonly HotelTransportBookingServiceCommercialSynchronizer $serviceCommercials,
    ) {}

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
        $rateCompanyId = $this->transportCompanyId($rows, $serviceRow);
        // Company is a display value derived from the selected rate card. It
        // must never become a free-text rate-card filter: normalized cards use
        // transport_company_id and may not carry the display name themselves.
        $routes = $this->routeOptions('', $this->transportEffectiveDate($bookingRow), $rateCompanyId);
        $rows = $this->hydrateForeignCostCommercials($rows, $routes);

        return response()->json([
            'ok' => true,
            'booking_id' => $booking,
            'booking' => ['currency' => 'PKR', 'native_currency' => $this->bookingCurrency($bookingRow)],
            'routes' => $routes,
            'vehicles' => $this->vehicleOptions($routes),
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

    /**
     * The single server-side authority for whether a booking currently has an
     * active Transport product.  Workspace and operational-summary readers use
     * this rather than independently text-matching retired booking services.
     */
    public function activeServiceId(int $booking): ?int
    {
        $service = $this->findTransportService($booking);

        return $service ? (int) $service['id'] : null;
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

        $activeService = $this->findTransportService($booking);
        $serviceRow = (array) ($activeService['row'] ?? []);
        $serviceCompanyId = $this->transportCompanyId([], $serviceRow);
        $serviceVendorId = $this->bookingVendorId([], $serviceRow);
        $routeOptions = $this->routeOptions('', $this->transportEffectiveDate($bookingRow), $serviceCompanyId);
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
            // A legacy Transport detail may be missing its vendor FK while the
            // active booking-service still has the native company authority.
            // Reuse it only when it is a recognised supplier; otherwise it is
            // used solely to resolve the current rate-card matrix, never written
            // as an invented vendor reference.
            // A booking Vendor/Party ID is not the Transport Company namespace.
            // Rate selection is driven by the selected matrix row/card, while
            // company_name remains display-only.
            $rateCompanyId = $serviceCompanyId;
            if ($vendorId <= 0 && $serviceVendorId !== null && isset($supplierMap[$serviceVendorId])) {
                $vendorId = $serviceVendorId;
            }
            if ($vendorId > 0 && ! isset($supplierMap[$vendorId])) {
                throw ValidationException::withMessages(["transports.$index.vendor_id" => 'Select a valid Transport Company / Vendor from the existing supplier authority.']);
            }
            $company = trim((string) ($raw['company_name'] ?? ''));
            if ($vendorId > 0 && isset($supplierMap[$vendorId]) && $company === '') $company = $supplierMap[$vendorId];
            $sale = round((float) ($raw['sale_amount'] ?? 0), 2);
            $quantity = max(1, (int) ($raw['quantity'] ?? 1));
            $routeSource = trim((string) ($raw['route_source_table'] ?? ''));
            $routeMasterId = max(0, (int) ($raw['route_master_id'] ?? 0));
            $companyRoutes = $rateCompanyId !== null
                ? $this->routeOptions('', $this->transportEffectiveDate($bookingRow), $rateCompanyId)
                : $routeOptions;
            $companyMatrix = collect($companyRoutes)
                ->flatMap(static fn (array $route): array => (array) ($route['rate_matrix'] ?? [$route]))
                ->values()
                ->all();
            $companyRouteMap = [];
            foreach ($companyMatrix as $routeOption) {
                $source = trim((string) ($routeOption['source_table'] ?? ''));
                $id = (int) ($routeOption['id'] ?? 0);
                if ($source !== '' && $id > 0) $companyRouteMap[strtolower($source).':'.$id] = $routeOption;
            }
            $master = $companyRouteMap[strtolower($routeSource).':'.$routeMasterId] ?? null;
            if (! is_array($master)) {
                $wantedRoute = strtolower(trim((string) ($raw['route_name'] ?? '')));
                $wantedVehicle = strtolower(trim((string) ($raw['vehicle_type'] ?? '')));
                foreach ($companyMatrix as $candidate) {
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
            $resolvedRouteMasterId = is_array($master) && (int) ($master['id'] ?? 0) > 0
                ? (int) $master['id']
                : $routeMasterId;
            $resolvedRouteSource = is_array($master) && trim((string) ($master['source_table'] ?? '')) !== ''
                ? trim((string) $master['source_table'])
                : $routeSource;
            $submittedCostRate = array_key_exists('cost_rate', $raw)
                ? round((float) ($raw['cost_rate'] ?? 0), 2)
                : round(((float) ($raw['cost_amount'] ?? 0)) / max(1, $quantity), 2);
            // A non-zero saved booking cost is historical commercial data. Only
            // an empty/zero booking cost may inherit the current master matrix.
            $costRate = $submittedCostRate > 0 ? $submittedCostRate : ($masterRate ?? 0.0);
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
                'route_master_id' => $resolvedRouteMasterId,
                'route_source_table' => $resolvedRouteSource,
                'rate_card_id' => max(0, (int) ($master['rate_card_id'] ?? (str_contains(strtolower($resolvedRouteSource), 'transport_rate') ? $resolvedRouteMasterId : 0))),
                'transport_company_id' => max(0, (int) ($master['company_id'] ?? $serviceCompanyId ?? 0)),
                'transport_route_id' => max(0, (int) ($master['transport_route_id'] ?? 0)),
                'transport_vehicle_type_id' => max(0, (int) ($master['transport_vehicle_type_id'] ?? 0)),
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
                $this->passengerLinks->syncTransportBookingWide($booking, (int) $service['id']);

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

        $responseCompany = trim((string) ($result['transports'][0]['company_name'] ?? ''));
        $responseRoutes = $this->routeOptions($responseCompany, $this->transportEffectiveDate($bookingRow));
        return response()->json([
            'ok' => true,
            'message' => 'Transport Data saved.',
            'transports' => $result['transports'],
            'summary' => $result['summary'],
            'routes' => $responseRoutes,
            'vehicles' => $this->vehicleOptions($responseRoutes),
            'suppliers' => $this->supplierOptions(),
        ]);
    }

    /**
     * Select Transport for a GENERAL booking before any row data exists.
     * This is intentionally narrower than the Transport store: it creates or
     * resolves only one active native booking-service row and writes no
     * transport commercial or operational data.
     */
    public function activate(Request $request, int $booking): JsonResponse|RedirectResponse
    {
        $bookingRow = $this->assertBooking($booking);
        if (! Schema::hasTable('booking_services')) {
            throw ValidationException::withMessages(['transport' => 'The native booking service store is not available on this ERP installation.']);
        }

        $service = DB::transaction(fn (): array => $this->ensureTransportService($booking, $bookingRow));

        if (! $request->expectsJson()) {
            return redirect()->to($this->bookingWorkspaceUrl($booking, true))->with('success', 'Transport product added.');
        }

        return response()->json([
            'ok' => true,
            'booking_id' => $booking,
            'service_id' => (int) $service['id'],
            'message' => 'Transport selected.',
        ]);
    }

    /** Retire only the active Transport service; child data remains historical. */
    public function retire(Request $request, int $booking): JsonResponse|RedirectResponse
    {
        $this->assertBooking($booking);
        $service = $this->findTransportService($booking);
        if (! $service) {
            if (! $request->expectsJson()) return redirect()->to($this->bookingWorkspaceUrl($booking))->with('success', 'Transport product removed.');
            return response()->json(['ok' => true, 'booking_id' => $booking, 'message' => 'Transport is already removed.']);
        }

        $columns = $this->physicalColumnListing('booking_services');
        $update = [];
        if (in_array('is_active', $columns, true)) $update['is_active'] = 0;
        if (in_array('active', $columns, true)) $update['active'] = 0;
        if (in_array('status', $columns, true)) {
            $enum = $this->enumValues($this->columnMetadata('booking_services')['status'] ?? []);
            $retired = collect(['inactive', 'removed', 'cancelled', 'canceled', 'deleted'])
                ->first(static fn (string $value): bool => ! $enum || in_array($value, $enum, true));
            if ($retired !== null) $update['status'] = $retired;
        }
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if (! $update || (! array_key_exists('is_active', $update) && ! array_key_exists('active', $update) && ! array_key_exists('status', $update))) {
            throw ValidationException::withMessages(['transport' => 'This native booking-service schema has no safe Transport retirement authority.']);
        }
        DB::table('booking_services')->where('id', (int) $service['id'])->update($update);

        if (! $request->expectsJson()) {
            return redirect()->to($this->bookingWorkspaceUrl($booking))->with('success', 'Transport product removed.');
        }

        return response()->json(['ok' => true, 'booking_id' => $booking, 'message' => 'Transport removed.']);
    }

    private function assertBooking(int $booking): object
    {
        abort_unless(Schema::hasTable('bookings'), 404);
        $row = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function bookingWorkspaceUrl(int $booking, bool $transportSelected = false): string
    {
        $url = url('/operations/bookings/'.$booking);
        return $transportSelected ? $url.'?selected_products=transport' : $url;
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

    private function transportEffectiveDate(object $booking): ?string
    {
        $data = (array) $booking;
        foreach (['travel_date', 'departure_date', 'start_date', 'booking_date', 'date'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) return substr($value, 0, 10);
        }

        return null;
    }

    /** @param list<array<string,mixed>> $rows */
    private function transportCompanyId(array $rows, array $serviceRow): ?int
    {
        foreach ($rows as $row) {
            $id = (int) ($row['transport_company_id'] ?? 0);
            if ($id > 0) return $id;
        }
        $direct = (int) ($serviceRow['transport_company_id'] ?? 0);
        if ($direct > 0) return $direct;

        // Production Transport Company authority is travel_voucher_partners,
        // not booking_services.vendor_id. Resolve it from the actual Transport
        // segment's company identity only; never borrow a Hotel service vendor.
        $companyName = '';
        foreach ($rows as $row) {
            $companyName = trim((string) ($row['company_name'] ?? ''));
            if ($companyName !== '') break;
        }
        if ($companyName === '' || ! Schema::hasTable('travel_voucher_partners')) return null;
        try {
            $columns = $this->physicalColumnListing('travel_voucher_partners');
            $idColumn = $this->firstColumn($columns, ['id', 'partner_id']);
            $nameColumn = $this->firstColumn($columns, ['name', 'partner_name', 'company_name', 'display_name']);
            $typeColumn = $this->firstColumn($columns, ['partner_type', 'type', 'category']);
            if (! $idColumn || ! $nameColumn) return null;
            $wanted = preg_replace('/[^a-z0-9]+/', '', strtolower($companyName));
            foreach (DB::table('travel_voucher_partners')->get() as $object) {
                $partner = (array) $object;
                if ($typeColumn && ! str_contains(strtolower((string) ($partner[$typeColumn] ?? '')), 'transport')) continue;
                $candidate = preg_replace('/[^a-z0-9]+/', '', strtolower((string) ($partner[$nameColumn] ?? '')));
                if ($wanted !== '' && ($candidate === $wanted || str_contains($candidate, $wanted) || str_contains($wanted, $candidate))) {
                    $id = (int) ($partner[$idColumn] ?? 0);
                    if ($id > 0) return $id;
                }
            }
        } catch (Throwable) {}
        return null;
    }

    /** @param list<array<string,mixed>> $rows */
    private function bookingVendorId(array $rows, array $serviceRow): ?int
    {
        foreach ($rows as $row) {
            $id = (int) ($row['vendor_id'] ?? 0);
            if ($id > 0) return $id;
        }
        foreach (['vendor_id', 'supplier_id', 'service_provider_id'] as $field) {
            $id = (int) ($serviceRow[$field] ?? 0);
            if ($id > 0) return $id;
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function routeOptions(string $companyName = '', ?string $effectiveDate = null, ?int $companyId = null): array
    {
        try {
            $source = app(UnifiedGroupPackageDataSource::class);
            $effective = $source->effectiveTransportRateRoutes($companyName, $effectiveDate, $companyId);
            // A legacy booking service can carry a native vendor key that is
            // not the rate-card company key. Do not turn that stale FK into a
            // zero-rate result: fall back to the uniquely matching active
            // route/vehicle matrix while preserving normal company filtering.
            if ($effective->isEmpty() && $companyId !== null && $companyId > 0) {
                $effective = $source->effectiveTransportRateRoutes($companyName, $effectiveDate);
            }
            $matrix = $effective->map(function (array $row): array {
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
                    'rate_card_id' => (int) ($row['rate_card_id'] ?? 0),
                    'transport_route_id' => (int) ($row['transport_route_id'] ?? 0),
                    'transport_vehicle_type_id' => (int) ($row['transport_vehicle_type_id'] ?? 0),
                    'key' => trim((string) ($row['key'] ?? '')),
                    'name' => $name,
                    'display' => implode(' · ', array_filter($parts, static fn ($value): bool => trim((string) $value) !== '')),
                    'vehicle_type' => $vehicle,
                    'company_name' => $company,
                    'contact_number' => trim((string) ($row['contact_number'] ?? '')),
                    'brn_number' => trim((string) ($row['brn_number'] ?? '')),
                    'rate_amount' => $rate,
                    'rate_field' => trim((string) ($row['rate_field'] ?? '')),
                    'rate_raw_value' => $row['rate_raw_value'] ?? null,
                    'rate_currency' => $currency,
                    'exchange_rate_to_pkr' => $fx !== null ? round($fx, 8) : null,
                    'rate_pkr' => ($rate !== null && $fx !== null) ? round($rate * $fx, 2) : null,
                ];
            })->filter(static fn (array $row): bool => $row['name'] !== '')->values();

            // The Route select contains one route. Vehicle-specific matrix rows
            // remain attached so their rate can be resolved independently.
            return $matrix->groupBy(static fn (array $row): string => strtolower($row['company_name'].'|'.$row['name']))
                ->map(function ($group): array {
                    $route = (array) $group->first();
                    $route['rate_matrix'] = $group->values()->all();
                    return $route;
                })->values()->all();
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
        $matrix = collect($routes)->flatMap(static fn (array $route): array => (array) ($route['rate_matrix'] ?? [$route]))->values()->all();
        foreach ($matrix as $route) {
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
                foreach ($matrix as $candidate) {
                    if (strtolower(trim((string) ($candidate['name'] ?? ''))) !== strtolower(trim((string) ($row['route_name'] ?? '')))) continue;
                    $candidateVehicle = strtolower(trim((string) ($candidate['vehicle_type'] ?? '')));
                    $rowVehicle = strtolower(trim((string) ($row['vehicle_type'] ?? '')));
                    if ($candidateVehicle !== '' && $rowVehicle !== '' && $candidateVehicle !== $rowVehicle) continue;
                    $master = $candidate;
                    break;
                }
            }

            // Legacy malformed rows may contain the Vehicle label in the Route
            // slot. Once the exact native rate-card row is found, repair that
            // display-only collision from its stored origin/destination route.
            if (is_array($master) && strtolower(trim((string) ($row['route_name'] ?? ''))) === strtolower(trim((string) ($row['vehicle_type'] ?? '')))) {
                $row['route_name'] = trim((string) ($master['name'] ?? $row['route_name']));
            }

            // The rate matrix already resolved the native Transport Partner
            // authority. Carry that authority into the workspace row instead
            // of leaving the repaired booking-service with an empty company
            // label (which the client renders as “Transport company”).
            $resolvedRateCardId = is_array($master) ? (int) ($master['rate_card_id'] ?? 0) : 0;
            $resolvedCompanyId = is_array($master) ? (int) ($master['company_id'] ?? 0) : 0;
            if ($resolvedCompanyId <= 0 && $resolvedRateCardId > 0) {
                $resolvedCompanyId = $this->activeTransportCompanyIdForRateCard($resolvedRateCardId);
            }
            if ($resolvedCompanyId > 0) {
                if ($resolvedRateCardId > 0) $row['rate_card_id'] = $resolvedRateCardId;
                $row['transport_company_id'] = $resolvedCompanyId;
                $row['company_id'] = $resolvedCompanyId;
                $resolvedCompanyName = $this->transportCompanyPartnerName($resolvedCompanyId);
                if ($resolvedCompanyName !== '' && $this->isUnresolvedTransportCompanyName((string) ($row['company_name'] ?? ''))) {
                    $row['company_name'] = $resolvedCompanyName;
                }
                if ($resolvedCompanyName !== '') $row['transport_company_name'] = $resolvedCompanyName;
            }

            $usesMasterRate = (! array_key_exists('cost_rate', $row) || (float) ($row['cost_rate'] ?? 0) <= 0)
                && is_array($master)
                && is_numeric($master['rate_amount'] ?? null)
                && (float) $master['rate_amount'] > 0;
            if ($usesMasterRate) {
                $row['cost_rate'] = round((float) $master['rate_amount'], 2);
            }
            // Keep the result of the single server-side resolver explicit in
            // the response contract. The workspace must never re-select a
            // legacy persisted zero over this current effective master rate.
            // A non-zero historical booking cost remains the displayed value.
            $row['resolved_cost_rate'] = round((float) ($row['cost_rate'] ?? 0), 2);
            $row['cost_rate_authority'] = $usesMasterRate
                ? 'effective_transport_rate'
                : ((float) ($row['cost_rate'] ?? 0) > 0 ? 'saved_historical_cost' : 'unresolved');
            $currency = $this->normalizeCurrencyCode((string) ($row['cost_currency'] ?? ''));
            if ($usesMasterRate && is_array($master)) $currency = $this->normalizeCurrencyCode((string) ($master['rate_currency'] ?? ''));
            if ($currency === '' && is_array($master)) $currency = $this->normalizeCurrencyCode((string) ($master['rate_currency'] ?? ''));
            if ($currency === '') $currency = (str_contains($source, 'transport_rate') ? 'SAR' : 'PKR');
            $row['cost_currency'] = $currency;

            $fx = $usesMasterRate ? 0.0 : (float) ($row['exchange_rate'] ?? 0);
            if ($fx <= 0 && is_array($master) && is_numeric($master['exchange_rate_to_pkr'] ?? null)) $fx = (float) $master['exchange_rate_to_pkr'];
            if ($fx <= 0) $fx = (float) ($this->exchangeRateToPkr($currency) ?? 0);
            if ($fx <= 0 && $currency === 'PKR') $fx = 1.0;
            $row['exchange_rate'] = round($fx, 8);
            // A physical vendor total is already in PKR.  When a legacy row has
            // no stored unit rate, derive the source-currency rate only after
            // its FX is known; dividing merely by quantity made a valid total
            // look like a rate and then inflated/overwrote it on reload.
            if ((float) ($row['cost_rate'] ?? 0) <= 0 && (float) ($row['cost_amount'] ?? 0) > 0 && $fx > 0) {
                $row['cost_rate'] = round((float) $row['cost_amount'] / ($quantity * $fx), 2);
            }
            $row['resolved_cost_rate'] = round((float) ($row['cost_rate'] ?? 0), 2);
            if ($fx > 0) $row['cost_amount'] = round((float) ($row['cost_rate'] ?? 0) * $quantity * $fx, 2);
            $row['margin'] = round((float) ($row['sale_amount'] ?? 0) - (float) ($row['cost_amount'] ?? 0), 2);
        }
        unset($row);
        return $rows;
    }

    private function transportCompanyPartnerName(int $companyId): string
    {
        if ($companyId <= 0 || ! Schema::hasTable('travel_voucher_partners')) return '';
        try {
            $columns = $this->physicalColumnListing('travel_voucher_partners');
            $idColumn = $this->firstColumn($columns, ['id', 'partner_id']);
            $nameColumn = $this->firstColumn($columns, ['name', 'partner_name', 'company_name', 'display_name']);
            $typeColumn = $this->firstColumn($columns, ['partner_type', 'type', 'category']);
            if (! $idColumn || ! $nameColumn) return '';
            $partner = (array) (DB::table('travel_voucher_partners')->where($idColumn, $companyId)->first() ?? (object) []);
            if ($typeColumn && ! str_contains(strtolower((string) ($partner[$typeColumn] ?? '')), 'transport')) return '';
            return trim((string) ($partner[$nameColumn] ?? ''));
        } catch (Throwable) {
            return '';
        }
    }

    private function activeTransportCompanyIdForRateCard(int $rateCardId): int
    {
        if ($rateCardId <= 0 || ! Schema::hasTable('transport_rate_cards')) return 0;
        try {
            $columns = $this->physicalColumnListing('transport_rate_cards');
            $idColumn = $this->firstColumn($columns, ['id', 'rate_card_id']);
            $companyColumn = $this->firstColumn($columns, ['transport_company_id', 'company_id']);
            if (! $idColumn || ! $companyColumn) return 0;
            $card = (array) (DB::table('transport_rate_cards')->where($idColumn, $rateCardId)->first() ?? (object) []);
            if (! $card) return 0;
            if (array_key_exists('is_active', $card) && ! (bool) $card['is_active']) return 0;
            if (array_key_exists('active', $card) && ! (bool) $card['active']) return 0;
            if (array_key_exists('status', $card) && strtolower(trim((string) $card['status'])) !== 'active') return 0;
            return max(0, (int) ($card[$companyColumn] ?? 0));
        } catch (Throwable) {
            return 0;
        }
    }

    private function isUnresolvedTransportCompanyName(string $name): bool
    {
        return in_array(strtolower(trim($name)), ['', 'transport company', 'select transport company'], true);
    }

    /** @return list<array<string,mixed>> */
    private function vehicleOptions(array $routes): array
    {
        return collect($routes)->flatMap(static fn (array $row): array => (array) ($row['rate_matrix'] ?? [$row]))->map(static fn (array $row): array => [
            'id' => 0,
            'source_table' => (string) ($row['source_table'] ?? 'transport_rate_cards'),
            'key' => (string) ($row['source_table'] ?? 'transport_rate_cards').':vehicle:'.strtolower((string) ($row['vehicle_type'] ?? '')),
            'name' => trim((string) ($row['vehicle_type'] ?? '')),
        ])->filter(static fn (array $row): bool => $row['name'] !== '')
            ->unique(static fn (array $row): string => strtolower($row['name']))->values()->all();
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
        // Product/Service is the sole booking-service discriminator. In
        // particular, never inspect notes, descriptions or arbitrary scalar
        // fields here: a legacy ETERP_TRANSPORT_ROWS marker on another product
        // would otherwise make that product permanently masquerade as Transport.
        $masterId = self::TRANSPORT_PRODUCT_SERVICE_ID;
        try {
            foreach (DB::table('booking_services')->where('booking_id', $booking)->orderByDesc('id')->get() as $object) {
                $row = (array) $object;
                // A removed native service must not be treated as the active
                // Transport authority on a later Add. Otherwise
                // ensureTransportService returns the retired row and the
                // product cannot be recreated.
                if (! empty($row['deleted_at'])) continue;
                if (array_key_exists('is_active', $row) && ! (bool) $row['is_active']) continue;
                if (array_key_exists('active', $row) && ! (bool) $row['active']) continue;
                $status = strtolower(trim((string) ($row['status'] ?? '')));
                if (in_array($status, ['deleted', 'removed', 'inactive', 'cancelled', 'canceled'], true)) continue;
                if ((int) ($row['product_service_id'] ?? 0) === $masterId) return ['id' => (int) ($row['id'] ?? 0), 'row' => $row];
            }
        } catch (Throwable) {}
        return null;
    }

    /** @return array{id:int,row:array<string,mixed>} */
    private function ensureTransportService(int $booking, object $bookingRow): array
    {
        $existing = $this->findTransportService($booking);
        if ($existing) return $this->repairLegacyTransportOwnership($booking, $existing);

        $master = $this->resolveTransportProductService();
        if (! $master || (int) ($master['id'] ?? 0) !== self::TRANSPORT_PRODUCT_SERVICE_ID) {
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
        $this->putNativeEnum($row, $table, $columns, ['passenger_link_mode_snapshot','passenger_link_mode'], 'MULTIPLE', ['multiple']);
        $this->putNativeEnum($row, $table, $columns, ['pricing_basis_snapshot','pricing_basis'], 'PER_SERVICE', ['per_service']);
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
            'passenger_link_mode_snapshot' => 'MULTIPLE',
            'passenger_link_mode' => 'MULTIPLE',
            'pricing_basis_snapshot' => 'PER_SERVICE',
            'pricing_basis' => 'PER_SERVICE',
        ]);
        $this->assertRequiredContract($table, $row, 'Transport service');
        $id = (int) DB::table($table)->insertGetId($row);
        return $this->repairLegacyTransportOwnership($booking, ['id' => $id, 'row' => $row + ['id' => $id]]);
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
                    $row = (array) $object; $id = (int) ($row[$idColumn] ?? 0); if ($id !== self::TRANSPORT_PRODUCT_SERVICE_ID) continue;
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

    /**
     * Correct only the known, unambiguous legacy corruption pattern: native
     * Transport rows attached to a non-Transport booking service. The caller
     * runs inside the activation/save transaction, so a failed repair cannot
     * leave a half-moved segment or snapshot behind.
     *
     * @param array{id:int,row:array<string,mixed>} $transportService
     * @return array{id:int,row:array<string,mixed>}
     */
    private function repairLegacyTransportOwnership(int $booking, array $transportService): array
    {
        $transportServiceId = (int) ($transportService['id'] ?? 0);
        if ($transportServiceId <= 0 || ! Schema::hasTable('booking_services')) return $transportService;

        $table = $this->resolveTransportTable();
        if (! $table) return $transportService;
        $columns = $this->physicalColumnListing($table);
        $serviceColumn = $this->resolveBookingServiceLinkColumn($table, $columns);
        if (! $serviceColumn || ! in_array('booking_id', $columns, true)) return $transportService;

        $wrongRows = DB::table($table)->where('booking_id', $booking)
            ->where($serviceColumn, '<>', $transportServiceId)->get();
        if ($wrongRows->isEmpty()) return $transportService;

        foreach ($wrongRows as $object) {
            $segment = (array) $object;
            $wrongServiceId = (int) ($segment[$serviceColumn] ?? 0);
            if ($wrongServiceId <= 0) continue;
            $wrongService = (array) (DB::table('booking_services')->where('id', $wrongServiceId)->first() ?? (object) []);
            if (! $wrongService || (int) ($wrongService['product_service_id'] ?? 0) === self::TRANSPORT_PRODUCT_SERVICE_ID) continue;

            // This table is the installed native Transport row store. A row in
            // it linked to a non-Transport service is the required unambiguous
            // corruption signal; do not apply this repair to generic tables.
            DB::table($table)->where('id', (int) ($segment['id'] ?? 0))->update([$serviceColumn => $transportServiceId]);

            $legacySnapshot = $this->snapshotFromServiceRow($wrongService);
            $canonicalSnapshot = $this->snapshotFromServiceRow((array) (DB::table('booking_services')->where('id', $transportServiceId)->first() ?? (object) []));
            if ($legacySnapshot && ! $canonicalSnapshot) $this->syncServiceSnapshot($transportServiceId, $legacySnapshot);
            $this->removeTransportSnapshot($wrongServiceId);
        }

        $fresh = (array) (DB::table('booking_services')->where('id', $transportServiceId)->first() ?? (object) []);
        return ['id' => $transportServiceId, 'row' => $fresh ?: $transportService['row']];
    }

    /** Remove only the Transport compatibility payload; retain all other notes and JSON keys. */
    private function removeTransportSnapshot(int $serviceId): void
    {
        if ($serviceId <= 0 || ! Schema::hasTable('booking_services')) return;
        $table = 'booking_services';
        $columns = $this->physicalColumnListing($table);
        $current = (array) (DB::table($table)->where('id', $serviceId)->first() ?? (object) []);
        if (! $current) return;
        $update = [];

        foreach ($this->jsonCarrierFields($table, $columns) as $field) {
            $raw = $current[$field] ?? null;
            $decoded = is_array($raw) ? $raw : json_decode((string) ($raw ?? ''), true);
            if (! is_array($decoded) || ! array_key_exists('et_erp_transport_rows', $decoded)) continue;
            unset($decoded['et_erp_transport_rows']);
            $update[$field] = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        foreach ($this->taggedTextCarrierFields($table, $columns) as $field) {
            $text = (string) ($current[$field] ?? '');
            $clean = $this->removeTaggedPayload($text, 'ETERP_TRANSPORT_ROWS');
            if ($clean !== $text) $update[$field] = $clean;
        }
        if ($update) {
            if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
            DB::table($table)->where('id', $serviceId)->update($update);
        }
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
                $rateCardId = (int) ($this->valueFrom($row, $columns, ['rate_card_id']) ?? 0);
                $routeMasterId = (int) ($this->valueFrom($row, $columns, ['route_master_id','route_id']) ?? 0);
                $sale = $this->numberFromMeaningful($row, $columns, ['sale_amount','selling_total','customer_total','sale_total','total_sale','customer_amount','selling_amount','customer_price','sale_price','selling_price']);
                // Modern host rows retain the source-currency vendor price and
                // its converted PKR commercial independently. Prefer the PKR
                // total for margin calculations; supplier_amount then remains
                // the source vendor price (for example SAR 100.00).
                $cost = $this->numberFromMeaningful($row, $columns, ['supplier_amount_pkr','vendor_total_pkr','cost_amount_pkr','supplier_total_pkr','supplier_amount','vendor_total','cost_total','total_cost','supplier_cost','vendor_cost','cost_amount','purchase_price','cost_price']);
                $costRate = $this->numberFromMeaningful($row, $columns, ['supplier_rate','vendor_rate','cost_rate','unit_cost','supplier_unit_cost','vendor_unit_cost']);
                if ($costRate <= 0 && in_array('supplier_amount_pkr', $columns, true)) {
                    $costRate = $this->numberFromMeaningful($row, $columns, ['supplier_amount']);
                }
                $costCurrency = strtoupper(trim((string) ($this->valueFrom($row, $columns, ['cost_rate_currency_code','rate_currency','source_currency_code','cost_currency','cost_currency_code','supplier_currency_code','vendor_currency_code']) ?? '')));
                $exchangeRate = $this->numberFromMeaningful($row, $columns, ['exchange_rate','supplier_exchange_rate','vendor_exchange_rate','cost_exchange_rate']);
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'route_master_id' => $routeMasterId > 0 ? $routeMasterId : $rateCardId,
                    // A physical rate_card_id is an exact native master key;
                    // retain it on reload so the rate lookup never falls back
                    // to a generic vehicle label.
                    'route_source_table' => $rateCardId > 0 ? 'transport_rate_cards' : '',
                    'rate_card_id' => $rateCardId,
                    'route_name' => trim((string) ($this->valueFrom($row, $columns, ['route_label','route_name','route']) ?? '')) ?: $this->composeRoute($row, $columns),
                    'vehicle_master_id' => (int) ($this->valueFrom($row, $columns, ['vehicle_master_id','vehicle_id','vehicle_type_id']) ?? 0),
                    'vehicle_source_table' => '',
                    'vehicle_type' => trim((string) ($this->valueFrom($row, $columns, ['vehicle_type','vehicle_name','vehicle']) ?? '')),
                    'quantity' => max(1, (int) ($this->valueFrom($row, $columns, ['vehicle_qty','vehicle_quantity','quantity','qty']) ?? 1)),
                    'driver_name' => trim((string) ($this->valueFrom($row, $columns, ['driver_name','driver','chauffeur_name']) ?? '')),
                    'driver_cell' => trim((string) ($this->valueFrom($row, $columns, ['driver_cell','driver_contact','contact_number','provider_contact','phone','mobile','cell_number']) ?? '')),
                    'plate_number' => trim((string) ($this->valueFrom($row, $columns, ['plate_number','plate_no','vehicle_plate','registration_number','registration_no']) ?? '')),
                    'vendor_id' => (int) ($this->valueFrom($row, $columns, ['vendor_id','supplier_id','service_provider_id']) ?? 0),
                    'transport_company_id' => (int) ($this->valueFrom($row, $columns, ['transport_company_id']) ?? 0),
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
            $resolvedRateCardId = (int) ($transport['rate_card_id'] ?? 0);
            if ($resolvedRateCardId <= 0 && ($transport['route_source_table'] ?? '') === 'transport_rate_cards') {
                $resolvedRateCardId = (int) ($transport['route_master_id'] ?? 0);
            }
            if ($resolvedRateCardId > 0) $this->put($row, $columns, ['rate_card_id'], $resolvedRateCardId);
            $this->put($row, $columns, ['transport_company_id'], (int) ($transport['transport_company_id'] ?? 0) ?: null);
            $this->put($row, $columns, ['transport_route_id'], (int) ($transport['transport_route_id'] ?? 0) ?: null);
            $this->put($row, $columns, ['transport_vehicle_type_id'], (int) ($transport['transport_vehicle_type_id'] ?? 0) ?: null);
            // A discovered matrix-detail ID is not automatically a native Route
            // master FK. Persist the rate-card header where supported and retain
            // the textual origin/destination unless the selected source itself
            // is the native rate-card route authority.
            if (($transport['route_source_table'] ?? '') === 'transport_rate_cards') {
                $this->put($row, $columns, ['route_master_id','route_id'], (int) ($transport['route_master_id'] ?? 0) ?: null);
            }
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
            $separateSourceAndPkr = in_array('supplier_amount_pkr', $columns, true);
            if ($separateSourceAndPkr) {
                $this->put($row, $columns, ['supplier_amount'], $transport['cost_rate']);
                $this->putAll($row, $columns, ['supplier_amount_pkr','vendor_total_pkr','cost_amount_pkr','supplier_total_pkr'], $transport['cost_amount']);
                $this->putAll($row, $columns, ['vendor_total','cost_total','total_cost','supplier_cost','vendor_cost','cost_amount','purchase_price','cost_price'], $transport['cost_amount']);
            } else {
                $this->putAll($row, $columns, ['supplier_amount','vendor_total','cost_total','total_cost','supplier_cost','vendor_cost','cost_amount','purchase_price','cost_price'], $transport['cost_amount']);
            }
            $this->putAll($row, $columns, ['margin','gross_margin','net_margin','profit'], $transport['margin']);
            $this->putAll($row, $columns, ['voucher_notes','notes','remarks','description'], $transport['notes'] ?: null);
            $this->put($row, $columns, ['sort_order','sequence','sequence_no'], ($index + 1) * 10);
            $this->putNativeEnum($row, $table, $columns, ['service_mode'], 'general', ['GENERAL','booking','BOOKING','service','SERVICE','package','PACKAGE']);
            $this->putNativeEnum($row, $table, $columns, ['status'], 'requested', ['REQUESTED','booked','BOOKED','confirmed','CONFIRMED','active','ACTIVE']);
            if (in_array('commercial_locked', $columns, true)) $row['commercial_locked'] = 0;
            if (in_array('sale_currency_code', $columns, true)) $row['sale_currency_code'] = 'PKR';
            // When the host exposes a separate source amount and PKR total,
            // supplier currency describes the source vendor price. Older
            // one-amount schemas retain their native PKR accounting meaning.
            foreach (['supplier_currency_code','vendor_currency_code'] as $field) if (in_array($field, $columns, true)) $row[$field] = $separateSourceAndPkr ? $transport['cost_currency'] : 'PKR';
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
            foreach (['sale_amount','cost_rate','cost_amount'] as $field) {
                if ((float) ($saved[$field] ?? 0) > 0) $row[$field] = round((float) $saved[$field], 2);
            }
            if ((float) ($saved['exchange_rate'] ?? 0) > 0) $row['exchange_rate'] = round((float) $saved['exchange_rate'], 8);
            $row['margin'] = round((float) ($row['sale_amount'] ?? 0) - (float) ($row['cost_amount'] ?? 0), 2);
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
        $this->serviceCommercials->syncTransportSummary($serviceId, (float) $summary['customer_total']);
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
        $clean = $this->removeTaggedPayload($text, $tag);
        $clean = trim($clean); return $clean === '' ? $marker : $clean."\n".$marker;
    }

    private function removeTaggedPayload(string $text, string $tag): string
    {
        return preg_replace('/\s*\[\['.preg_quote($tag, '/').':[A-Za-z0-9+\/=]+\]\]\s*/', '', $text) ?? $text;
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
