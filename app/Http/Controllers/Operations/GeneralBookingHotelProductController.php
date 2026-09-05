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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ERP-11.3.134
 *
 * Same-page Hotel Data bridge for GENERAL / MULTI-SERVICE bookings.
 * Reuses the installed ERP's native booking_services and native hotel-stay
 * store. City / Hotel master additions are written into the existing master
 * tables discovered on the installation. No parallel product table and no
 * migration are introduced.
 */
final class GeneralBookingHotelProductController extends Controller
{
    public function show(Request $request, int $booking): JsonResponse
    {
        $bookingRow = $this->assertBooking($booking);
        $service = $this->findHotelService($booking);
        $stayTable = $this->resolveHotelStayTable();
        $stays = $stayTable ? $this->stayRows($booking, (int) ($service['id'] ?? 0), $stayTable) : [];
        $serviceRow = (array) ($service['row'] ?? []);
        $stays = $this->overlayHotelServiceSnapshot(
            $stays,
            $this->hotelServiceSnapshotFromRow($serviceRow)
        );
        $stays = $this->overlayHotelServiceVendorContext($stays, $serviceRow);

        return response()->json([
            'ok' => true,
            'booking_id' => $booking,
            'booking' => [
                'currency' => $this->bookingCurrency($bookingRow),
            ],
            'suppliers' => $this->supplierOptions(),
            'cities' => $this->cityOptions(),
            'hotels' => $this->hotelOptions(),
            'room_types' => $this->roomTypeOptions(),
            'boards' => $this->boardOptions(),
            'stays' => $stays,
            'summary' => $this->summary($stays),
            'capabilities' => [
                'booking_services' => Schema::hasTable('booking_services'),
                'hotel_stay_table' => $stayTable,
                'runtime_city_master' => $this->resolveCityMasterTable(),
                'runtime_hotel_master' => $this->resolveHotelMasterTable(),
            ],
        ]);
    }

    public function store(Request $request, int $booking): JsonResponse
    {
        $bookingRow = $this->assertBooking($booking);
        $data = $request->validate([
            'stays' => ['required', 'array', 'min:1', 'max:30'],
            'stays.*.vendor_id' => ['nullable', 'integer', 'min:0'],
            'stays.*.vendor_name' => ['nullable', 'string', 'max:255'],
            'stays.*.city_id' => ['nullable', 'integer', 'min:1'],
            'stays.*.city' => ['required', 'string', 'max:120'],
            'stays.*.hotel_id' => ['nullable', 'integer', 'min:1'],
            'stays.*.hotel_name' => ['required', 'string', 'max:180'],
            'stays.*.confirmation_no' => ['nullable', 'string', 'max:120'],
            'stays.*.room_type' => ['required', 'string', 'max:100'],
            'stays.*.board' => ['required', Rule::in(['RO', 'BB'])],
            'stays.*.check_in' => ['required', 'date'],
            'stays.*.check_out' => ['required', 'date'],
            'stays.*.sale_rate' => ['required', 'numeric', 'min:0'],
            'stays.*.cost_rate' => ['required', 'numeric', 'min:0'],
        ]);

        $stayTable = $this->resolveHotelStayTable();
        if (! $stayTable) {
            throw ValidationException::withMessages([
                'hotel' => 'The native Hotel stay store could not be resolved on this ERP installation.',
            ]);
        }
        if (! Schema::hasTable('booking_services')) {
            throw ValidationException::withMessages([
                'hotel' => 'The native booking service store is not available on this ERP installation.',
            ]);
        }
        $vendorErrors = app(BookingCommercialCompletenessResolver::class)->hotelVendorErrors((array) $data['stays']);
        if ($vendorErrors) {
            throw ValidationException::withMessages(['hotel_vendor' => $vendorErrors]);
        }

        $stage = 'Hotel service';
        try {
            $result = DB::transaction(function () use ($booking, $bookingRow, $data, $stayTable, &$stage): array {
                $stage = 'Hotel service';
                $service = $this->ensureHotelService($booking, $bookingRow);

                $stage = 'City / Hotel Master';
                $normalized = [];
                $vendorMap = [];
                foreach ($this->supplierOptions() as $vendorOption) $vendorMap[(int) $vendorOption['id']] = (string) $vendorOption['name'];
                foreach ((array) $data['stays'] as $index => $raw) {
                    $vendorId = (int) ($raw['vendor_id'] ?? 0);
                    if ($vendorId > 0 && ! isset($vendorMap[$vendorId])) {
                        throw ValidationException::withMessages(["stays.$index.vendor_id" => 'Select a valid Vendor / Supplier for this Hotel stay.']);
                    }
                    $checkIn = new \DateTimeImmutable((string) $raw['check_in']);
                    $checkOut = new \DateTimeImmutable((string) $raw['check_out']);
                    $nights = (int) $checkIn->diff($checkOut)->days;
                    if ($nights < 1) {
                        throw ValidationException::withMessages([
                            "stays.$index.check_out" => 'Hotel Check Out must be after Check In.',
                        ]);
                    }

                    $city = $this->resolveOrCreateCity(
                        trim((string) $raw['city']),
                        (int) ($raw['city_id'] ?? 0)
                    );
                    $hotel = $this->resolveOrCreateHotel(
                        trim((string) $raw['hotel_name']),
                        (int) ($raw['hotel_id'] ?? 0),
                        $city
                    );

                    $saleRate = round((float) $raw['sale_rate'], 2);
                    $costRate = round((float) $raw['cost_rate'], 2);
                    $customerTotal = round($saleRate * $nights, 2);
                    $vendorTotal = round($costRate * $nights, 2);

                    $normalized[] = [
                        'vendor_id' => $vendorId,
                        'vendor_name' => $vendorId > 0 ? $vendorMap[$vendorId] : '',
                        'city_id' => (int) ($city['id'] ?? 0) ?: null,
                        'city' => (string) ($city['name'] ?? trim((string) $raw['city'])),
                        'hotel_id' => (int) ($hotel['id'] ?? 0) ?: null,
                        'hotel_name' => (string) ($hotel['name'] ?? trim((string) $raw['hotel_name'])),
                        'confirmation_no' => trim((string) ($raw['confirmation_no'] ?? '')),
                        'room_type' => trim((string) $raw['room_type']),
                        'board' => strtoupper(trim((string) $raw['board'])),
                        'check_in' => $checkIn->format('Y-m-d'),
                        'check_out' => $checkOut->format('Y-m-d'),
                        'nights' => $nights,
                        'sale_rate' => $saleRate,
                        'cost_rate' => $costRate,
                        'customer_total' => $customerTotal,
                        'vendor_total' => $vendorTotal,
                        'margin' => round($customerTotal - $vendorTotal, 2),
                    ];
                }

                $stage = 'Hotel stay rows';
                $this->syncStayRows(
                    $booking,
                    (int) $service['id'],
                    $bookingRow,
                    $stayTable,
                    $normalized
                );

                $stage = 'Hotel service totals';
                $summary = $this->summary($normalized);
                $this->syncServiceTotals((int) $service['id'], $summary);
                $this->syncHotelServiceVendorContext((int) $service['id'], $normalized);
                $this->syncHotelServiceSnapshot((int) $service['id'], $normalized);

                $fresh = $this->stayRows($booking, (int) $service['id'], $stayTable);
                $persistedServiceRow = (array) (DB::table('booking_services')->where('id', (int) $service['id'])->first() ?? (object) []);
                $fresh = $this->overlayHotelServiceSnapshot($fresh, $this->hotelServiceSnapshotFromRow($persistedServiceRow));
                $this->assertPersistedHotelCommercials($normalized, $fresh);

                return [
                    'service' => $service,
                    'stays' => $fresh,
                    'summary' => $this->summary($fresh),
                ];
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            report($e);
            throw ValidationException::withMessages([
                'hotel' => $this->queryFailureMessage($e, $stage),
            ]);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'hotel' => 'Hotel Data could not be saved while writing '.$stage.'. Please check the server log for the underlying native-store error.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Hotel Data saved.',
            'stays' => $result['stays'],
            'summary' => $result['summary'],
            'cities' => $this->cityOptions(),
            'hotels' => $this->hotelOptions(),
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
            if ($value !== '' && strlen($value) <= 8) return $value;
        }
        if (! empty($row['currency_id']) && Schema::hasTable('currencies')) {
            try {
                $columns = Schema::getColumnListing('currencies');
                $code = $this->firstColumn($columns, ['code', 'currency_code', 'iso_code']);
                if ($code) {
                    $value = strtoupper(trim((string) DB::table('currencies')->where('id', (int) $row['currency_id'])->value($code)));
                    if ($value !== '') return $value;
                }
            } catch (Throwable) {
            }
        }
        return 'PKR';
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
                    ->unique('id')
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all();
            }
        } catch (Throwable) {
        }

        foreach (['parties', 'suppliers', 'vendors', 'travel_suppliers', 'supplier_masters', 'vendor_masters'] as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = $this->physicalColumnListing($table);
                $idColumn = $this->firstColumn($columns, ['id', 'party_id', 'supplier_id', 'vendor_id']);
                $nameColumn = $this->firstColumn($columns, ['name', 'display_name', 'legal_name', 'supplier_name', 'vendor_name', 'company_name', 'title']);
                if (! $idColumn || ! $nameColumn) continue;
                $query = DB::table($table)->select([$idColumn, $nameColumn]);
                if ($table === 'parties') {
                    $typeColumn = $this->firstColumn($columns, ['party_type', 'type', 'category', 'role']);
                    if ($typeColumn) {
                        $query->where(function ($q) use ($typeColumn): void {
                            $q->whereRaw('LOWER('.$typeColumn.") LIKE '%vendor%'")
                                ->orWhereRaw('LOWER('.$typeColumn.") LIKE '%supplier%'")
                                ->orWhereRaw('LOWER('.$typeColumn.") LIKE '%service%'");
                        });
                    }
                }
                $statusColumn = $this->firstColumn($columns, ['is_active', 'active']);
                if ($statusColumn) $query->where($statusColumn, 1);
                $rows = $query->orderBy($nameColumn)->limit(5000)->get();
                if ($rows->isNotEmpty()) {
                    return $rows->map(static function (object $row) use ($idColumn, $nameColumn): array {
                        return ['id' => (int) $row->{$idColumn}, 'name' => trim((string) $row->{$nameColumn})];
                    })->filter(static fn (array $row): bool => $row['id'] > 0 && $row['name'] !== '')
                        ->unique('id')->values()->all();
                }
            } catch (Throwable) {
            }
        }
        return [];
    }

    /** @return list<array{id:int,name:string}> */
    private function cityOptions(): array
    {
        $rows = [];
        foreach ($this->cityMasterTables() as $table) {
            try {
                $columns = $this->physicalColumnListing($table);
                $id = $this->firstColumn($columns, ['id', 'city_id']);
                $name = $this->firstColumn($columns, ['name', 'city_name', 'title']);
                if (! $name) continue;
                foreach (DB::table($table)->orderBy($name)->limit(5000)->get() as $record) {
                    $data = (array) $record;
                    $label = trim((string) ($data[$name] ?? ''));
                    if ($label === '') continue;
                    $rows[] = ['id' => $id ? (int) ($data[$id] ?? 0) : 0, 'name' => $this->preferredCityLabel($label)];
                }
            } catch (Throwable) {
            }
        }
        foreach ($this->hotelOptions() as $hotel) {
            if (trim((string) ($hotel['city'] ?? '')) !== '') {
                $rows[] = ['id' => (int) ($hotel['city_id'] ?? 0), 'name' => $this->preferredCityLabel((string) $hotel['city'])];
            }
        }
        $rows[] = ['id' => 0, 'name' => 'Makkah'];
        $rows[] = ['id' => 0, 'name' => 'Madina'];

        $unique = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row['name']));
            if ($key === '') continue;
            if (! isset($unique[$key]) || ((int) $row['id'] > 0 && (int) $unique[$key]['id'] <= 0)) $unique[$key] = $row;
        }
        uasort($unique, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return array_values($unique);
    }

    /** @return list<array{id:int,name:string,city:string,city_id:int}> */
    private function hotelOptions(): array
    {
        $rows = [];
        foreach ($this->hotelMasterTables() as $table) {
            try {
                $columns = $this->physicalColumnListing($table);
                $id = $this->firstColumn($columns, ['id', 'hotel_id']);
                $name = $this->firstColumn($columns, ['name', 'hotel_name', 'title', 'property_name']);
                if (! $name) continue;
                $cityIdCol = $this->firstColumn($columns, ['city_id', 'travel_city_id']);
                $cityCol = $this->firstColumn($columns, ['city', 'city_name', 'location']);
                foreach (DB::table($table)->orderBy($name)->limit(10000)->get() as $record) {
                    $data = (array) $record;
                    $label = trim((string) ($data[$name] ?? ''));
                    if ($label === '') continue;
                    $cityId = $cityIdCol ? (int) ($data[$cityIdCol] ?? 0) : 0;
                    $city = $cityCol ? trim((string) ($data[$cityCol] ?? '')) : '';
                    if ($city === '' && $cityId > 0) $city = $this->cityNameById($cityId);
                    $rows[] = [
                        'id' => $id ? (int) ($data[$id] ?? 0) : 0,
                        'name' => $label,
                        'city' => $this->preferredCityLabel($city),
                        'city_id' => $cityId,
                    ];
                }
            } catch (Throwable) {
            }
        }
        $unique = [];
        foreach ($rows as $row) {
            $key = strtolower(trim($row['name']).'|'.trim($row['city']));
            if ($key === '|') continue;
            if (! isset($unique[$key]) || ((int) $row['id'] > 0 && (int) $unique[$key]['id'] <= 0)) $unique[$key] = $row;
        }
        uasort($unique, static fn (array $a, array $b): int => strnatcasecmp($a['city'].' '.$a['name'], $b['city'].' '.$b['name']));
        return array_values($unique);
    }

    /** @return list<string> */
    private function roomTypeOptions(): array
    {
        $values = [];
        foreach (['hotel_room_types', 'room_types', 'travel_room_types', 'room_type_master', 'hotel_room_type_master'] as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = $this->physicalColumnListing($table);
                $name = $this->firstColumn($columns, ['name', 'room_type', 'title', 'code']);
                if (! $name) continue;
                foreach (DB::table($table)->orderBy($name)->limit(2000)->pluck($name) as $value) {
                    $value = trim((string) $value);
                    if ($value !== '') $values[] = $value;
                }
            } catch (Throwable) {
            }
        }
        if (! $values) $values = ['Single', 'Double', 'Triple', 'Quad', 'Quint', 'Sharing', 'Deluxe', 'Executive', 'Superior'];
        $unique = [];
        foreach ($values as $value) $unique[strtolower($value)] = $value;
        natcasesort($unique);
        return array_values($unique);
    }

    /** @return list<string> */
    private function boardOptions(): array
    {
        // Product decision: Hotel Board is controlled to RO / BB for this workspace.
        return ['RO', 'BB'];
    }

    private function preferredCityLabel(string $value): string
    {
        $value = trim($value);
        if (strcasecmp($value, 'Mecca') === 0 || strcasecmp($value, 'Makkah Al Mukarramah') === 0) return 'Makkah';
        if (strcasecmp($value, 'Medina') === 0 || strcasecmp($value, 'Madinah') === 0 || strcasecmp($value, 'Al Madinah') === 0) return 'Madina';
        return $value;
    }

    private function cityNameById(int $id): string
    {
        foreach ($this->cityMasterTables() as $table) {
            try {
                $columns = $this->physicalColumnListing($table);
                $idCol = $this->firstColumn($columns, ['id', 'city_id']);
                $nameCol = $this->firstColumn($columns, ['name', 'city_name', 'title']);
                if (! $idCol || ! $nameCol) continue;
                $name = DB::table($table)->where($idCol, $id)->value($nameCol);
                if ($name !== null) return $this->preferredCityLabel((string) $name);
            } catch (Throwable) {
            }
        }
        return '';
    }

    private function hotelNameById(int $id): string
    {
        foreach ($this->hotelMasterTables() as $table) {
            try {
                $columns = $this->physicalColumnListing($table);
                $idCol = $this->firstColumn($columns, ['id', 'hotel_id']);
                $nameCol = $this->firstColumn($columns, ['name', 'hotel_name', 'title', 'property_name']);
                if (! $idCol || ! $nameCol) continue;
                $name = DB::table($table)->where($idCol, $id)->value($nameCol);
                if ($name !== null) return trim((string) $name);
            } catch (Throwable) {
            }
        }
        return '';
    }

    /** @return array{id:int,name:string} */
    private function resolveOrCreateCity(string $name, int $id = 0): array
    {
        $name = $this->preferredCityLabel($name);
        foreach ($this->cityMasterTables() as $table) {
            try {
                $columns = $this->physicalColumnListing($table);
                $idCol = $this->firstColumn($columns, ['id', 'city_id']);
                $nameCol = $this->firstColumn($columns, ['name', 'city_name', 'title']);
                if (! $nameCol) continue;
                $query = DB::table($table);
                $row = null;
                if ($id > 0 && $idCol) $row = (array) ($query->where($idCol, $id)->first() ?? []);
                if (! $row) {
                    $rowObj = DB::table($table)->whereRaw('LOWER('.$nameCol.') = ?', [strtolower($name)])->first();
                    $row = $rowObj ? (array) $rowObj : [];
                }
                if ($row) return ['id' => $idCol ? (int) ($row[$idCol] ?? 0) : 0, 'name' => $this->preferredCityLabel((string) ($row[$nameCol] ?? $name))];
            } catch (Throwable) {
            }
        }

        $table = $this->resolveCityMasterTable();
        if (! $table) return ['id' => 0, 'name' => $name];
        $columns = $this->physicalColumnListing($table);
        $idCol = $this->firstColumn($columns, ['id', 'city_id']);
        $nameCol = $this->firstColumn($columns, ['name', 'city_name', 'title']);
        if (! $nameCol) return ['id' => 0, 'name' => $name];

        $prototype = DB::table($table)->orderByDesc($idCol ?: $nameCol)->first();
        $row = [];
        $row[$nameCol] = $name;
        $this->put($row, $columns, ['code', 'city_code'], strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 8)) ?: null);
        $this->putNativeEnum($row, $table, $columns, ['status'], 'active', ['ACTIVE', 'enabled', 'ENABLED']);
        foreach (['is_active' => 1, 'active' => 1] as $field => $value) if (in_array($field, $columns, true)) $row[$field] = $value;
        if (in_array('created_at', $columns, true)) $row['created_at'] = now();
        if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();
        $row = $this->fillRequiredByPrototype($table, $row, $prototype ? (array) $prototype : [], ['name' => $name, 'city_name' => $name, 'title' => $name, 'status' => 'active']);
        $this->assertRequiredContract($table, $row, 'City Master');
        $newId = $idCol ? (int) DB::table($table)->insertGetId($row) : 0;
        if (! $idCol) DB::table($table)->insert($row);
        return ['id' => $newId, 'name' => $name];
    }

    /** @param array{id:int,name:string} $city @return array{id:int,name:string} */
    private function resolveOrCreateHotel(string $name, int $id, array $city): array
    {
        foreach ($this->hotelMasterTables() as $table) {
            try {
                $columns = $this->physicalColumnListing($table);
                $idCol = $this->firstColumn($columns, ['id', 'hotel_id']);
                $nameCol = $this->firstColumn($columns, ['name', 'hotel_name', 'title', 'property_name']);
                if (! $nameCol) continue;
                $row = [];
                $cityIdCol = $this->firstColumn($columns, ['city_id', 'travel_city_id']);
                $cityCol = $this->firstColumn($columns, ['city', 'city_name', 'location']);
                if ($id > 0 && $idCol) {
                    $found = DB::table($table)->where($idCol, $id)->first();
                    $candidate = $found ? (array) $found : [];
                    if ($candidate) {
                        $sameCity = true;
                        if ($cityIdCol && (int) ($city['id'] ?? 0) > 0) $sameCity = (int) ($candidate[$cityIdCol] ?? 0) === (int) $city['id'];
                        elseif ($cityCol && trim((string) ($city['name'] ?? '')) !== '') $sameCity = strcasecmp(trim((string) ($candidate[$cityCol] ?? '')), trim((string) $city['name'])) === 0;
                        if ($sameCity) $row = $candidate;
                    }
                }
                if (! $row) {
                    $query = DB::table($table)->whereRaw('LOWER('.$nameCol.') = ?', [strtolower($name)]);
                    if ($cityIdCol && (int) ($city['id'] ?? 0) > 0) $query->where($cityIdCol, (int) $city['id']);
                    elseif ($cityCol && trim((string) ($city['name'] ?? '')) !== '') $query->whereRaw('LOWER('.$cityCol.') = ?', [strtolower((string) $city['name'])]);
                    $found = $query->first();
                    $row = $found ? (array) $found : [];
                }
                if ($row) return ['id' => $idCol ? (int) ($row[$idCol] ?? 0) : 0, 'name' => (string) ($row[$nameCol] ?? $name)];
            } catch (Throwable) {
            }
        }

        $table = $this->resolveHotelMasterTable();
        if (! $table) return ['id' => 0, 'name' => $name];
        $columns = $this->physicalColumnListing($table);
        $idCol = $this->firstColumn($columns, ['id', 'hotel_id']);
        $nameCol = $this->firstColumn($columns, ['name', 'hotel_name', 'title', 'property_name']);
        if (! $nameCol) return ['id' => 0, 'name' => $name];

        $prototypeQuery = DB::table($table);
        $cityIdCol = $this->firstColumn($columns, ['city_id', 'travel_city_id']);
        if ($cityIdCol && (int) ($city['id'] ?? 0) > 0) $prototypeQuery->where($cityIdCol, (int) $city['id']);
        $prototype = $prototypeQuery->orderByDesc($idCol ?: $nameCol)->first();
        if (! $prototype) $prototype = DB::table($table)->orderByDesc($idCol ?: $nameCol)->first();

        $row = [$nameCol => $name];
        $this->put($row, $columns, ['code', 'hotel_code', 'property_code'], strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 12)).'-'.strtoupper(Str::random(4)));
        $this->put($row, $columns, ['city_id', 'travel_city_id'], (int) ($city['id'] ?? 0) ?: null);
        $this->put($row, $columns, ['city', 'city_name', 'location'], (string) ($city['name'] ?? ''));
        $this->putNativeEnum($row, $table, $columns, ['status'], 'active', ['ACTIVE', 'enabled', 'ENABLED']);
        foreach (['is_active' => 1, 'active' => 1] as $field => $value) if (in_array($field, $columns, true)) $row[$field] = $value;
        if (in_array('created_at', $columns, true)) $row['created_at'] = now();
        if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();
        $row = $this->fillRequiredByPrototype($table, $row, $prototype ? (array) $prototype : [], [
            'name' => $name, 'hotel_name' => $name, 'title' => $name, 'property_name' => $name,
            'city_id' => (int) ($city['id'] ?? 0) ?: null, 'city' => (string) ($city['name'] ?? ''), 'city_name' => (string) ($city['name'] ?? ''),
            'status' => 'active',
        ]);
        $this->assertRequiredContract($table, $row, 'Hotel Master');
        $newId = $idCol ? (int) DB::table($table)->insertGetId($row) : 0;
        if (! $idCol) DB::table($table)->insert($row);
        return ['id' => $newId, 'name' => $name];
    }

    private function resolveCityMasterTable(): ?string
    {
        return $this->cityMasterTables()[0] ?? null;
    }

    /** @return list<string> */
    private function cityMasterTables(): array
    {
        return $this->candidateMasterTables(['cities', 'travel_cities', 'city_master', 'city_masters', 'travel_city_master'], 'city', ['name', 'city_name', 'title']);
    }

    private function resolveHotelMasterTable(): ?string
    {
        return $this->hotelMasterTables()[0] ?? null;
    }

    /** @return list<string> */
    private function hotelMasterTables(): array
    {
        return $this->candidateMasterTables(['hotels', 'travel_hotels', 'hotel_master', 'hotel_masters', 'travel_hotel_master'], 'hotel', ['name', 'hotel_name', 'title', 'property_name']);
    }

    /** @return list<string> */
    private function candidateMasterTables(array $preferred, string $needle, array $nameColumns): array
    {
        $tables = [];
        foreach ($preferred as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = $this->physicalColumnListing($table);
            if (in_array('booking_id', $columns, true)) continue;
            if ($this->firstColumn($columns, $nameColumns)) $tables[] = $table;
        }
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                if ($table === '' || ! str_contains(strtolower($table), $needle) || str_starts_with(strtolower($table), 'booking_')) continue;
                if (! Schema::hasTable($table)) continue;
                $columns = $this->physicalColumnListing($table);
                if (in_array('booking_id', $columns, true)) continue;
                if ($this->firstColumn($columns, $nameColumns)) $tables[] = $table;
            }
        } catch (Throwable) {
        }
        return array_values(array_unique($tables));
    }

    private function resolveHotelStayTable(): ?string
    {
        $candidates = ['booking_hotel_stays', 'booking_hotels', 'hotel_stays', 'booking_hotel_details', 'booking_accommodations', 'hotel_booking_details'];
        foreach ($candidates as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = $this->physicalColumnListing($table);
            if (in_array('booking_id', $columns, true) && ($this->firstColumn($columns, ['hotel_name', 'name', 'hotel_id']) !== null)) return $table;
        }
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                if ($table === '' || ! str_contains(strtolower($table), 'hotel')) continue;
                $columns = $this->physicalColumnListing($table);
                if (! in_array('booking_id', $columns, true)) continue;
                if (! $this->firstColumn($columns, ['check_in', 'check_in_date', 'checkin_date'])) continue;
                if (! $this->firstColumn($columns, ['check_out', 'check_out_date', 'checkout_date'])) continue;
                return $table;
            }
        } catch (Throwable) {
        }
        return null;
    }

    /** @return array{id:int,row:array<string,mixed>}|null */
    private function findHotelService(int $booking): ?array
    {
        if (! Schema::hasTable('booking_services')) return null;
        $columns = $this->physicalColumnListing('booking_services');
        if (! in_array('booking_id', $columns, true) || ! in_array('id', $columns, true)) return null;
        try {
            foreach (DB::table('booking_services')->where('booking_id', $booking)->orderByDesc('id')->get() as $rowObject) {
                $row = (array) $rowObject;
                $text = strtolower(implode(' ', array_map('strval', $row)));
                if (str_contains($text, 'hotel') || str_contains($text, 'accommodation')) return ['id' => (int) ($row['id'] ?? 0), 'row' => $row];
            }
        } catch (Throwable) {
        }
        return null;
    }

    /** @return array{id:int,row:array<string,mixed>} */
    private function ensureHotelService(int $booking, object $bookingRow): array
    {
        $existing = $this->findHotelService($booking);
        if ($existing) return $existing;

        $master = $this->resolveHotelProductService();
        if (! $master) {
            throw ValidationException::withMessages([
                'hotel' => 'The Hotel Product Service master could not be resolved. Confirm that Hotel exists in Product/Service Master.',
            ]);
        }

        $table = 'booking_services';
        $columns = $this->physicalColumnListing($table);
        $prototype = null;
        if (in_array('product_service_id', $columns, true)) {
            $prototype = DB::table($table)->where('product_service_id', (int) $master['id'])->orderByDesc('id')->first();
        }
        if (! $prototype) $prototype = DB::table($table)->orderByDesc('id')->first();

        $row = [];
        $row['booking_id'] = $booking;
        if (in_array('product_service_id', $columns, true)) $row['product_service_id'] = (int) $master['id'];
        $bookingData = (array) $bookingRow;
        foreach (['company_id', 'branch_id', 'customer_id', 'agent_id', 'salesperson_id', 'currency_id', 'tenant_id', 'office_id'] as $field) {
            if (in_array($field, $columns, true) && array_key_exists($field, $bookingData)) $row[$field] = $bookingData[$field];
        }
        $masterRow = (array) ($master['row'] ?? []);
        $name = $this->firstNonEmpty($masterRow, ['name', 'service_name', 'title', 'label', 'description']) ?: 'Hotel';
        $code = $this->firstNonEmpty($masterRow, ['code', 'service_code', 'product_code', 'slug']);
        $this->put($row, $columns, ['service_name', 'name', 'title'], $name);
        $this->put($row, $columns, ['service_code', 'product_code', 'code'], $code ?: null);
        $this->put($row, $columns, ['quantity', 'qty'], 1);
        $this->putNativeEnum($row, $table, $columns, ['status'], 'active', ['ACTIVE', 'booked', 'BOOKED']);
        foreach (['is_active' => 1, 'active' => 1] as $field => $value) if (in_array($field, $columns, true)) $row[$field] = $value;
        foreach (['created_by', 'created_by_id', 'updated_by', 'updated_by_id', 'user_id'] as $field) if (in_array($field, $columns, true) && Auth::id()) $row[$field] = Auth::id();
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
        $this->assertRequiredContract($table, $row, 'Hotel service');
        $id = (int) DB::table($table)->insertGetId($row);
        return ['id' => $id, 'row' => $row + ['id' => $id]];
    }

    /** @return array<string,mixed>|null */
    private function resolveHotelProductService(): ?array
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
        } catch (Throwable) {
        }
        foreach (['product_services', 'product_service_master', 'product_service_masters', 'travel_product_services', 'service_products'] as $table) if (Schema::hasTable($table)) $tables[] = $table;
        $tables = array_values(array_unique($tables));
        $best = null;
        $bestScore = 0;
        foreach ($tables as $table) {
            try {
                $columns = $this->physicalColumnListing($table);
                $idColumn = $this->firstColumn($columns, ['id', 'product_service_id']);
                if (! $idColumn) continue;
                foreach (DB::table($table)->limit(4000)->get() as $rowObject) {
                    $row = (array) $rowObject;
                    $id = (int) ($row[$idColumn] ?? 0);
                    if ($id <= 0) continue;
                    $text = strtolower(implode(' ', array_map('strval', $row)));
                    $score = 0;
                    if (str_contains($text, 'hotel')) $score += 10000;
                    if (str_contains($text, 'accommodation')) $score += 7000;
                    if (str_contains($text, 'room')) $score += 1000;
                    if (str_contains($text, 'air ticket')) $score -= 8000;
                    if (str_contains($text, 'visa')) $score -= 4000;
                    if (str_contains($text, 'transport')) $score -= 4000;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = ['id' => $id, 'table' => $table, 'row' => $row];
                    }
                }
            } catch (Throwable) {
            }
        }
        return $bestScore >= 3000 ? $best : null;
    }

    private function resolveBookingServiceLinkColumn(string $table, array $columns): ?string
    {
        if (in_array('booking_service_id', $columns, true)) return 'booking_service_id';

        // Do not assume a generic service_id points at booking_services. On some
        // long-lived Hotel schemas service_id belongs to a service/master table.
        // Only use it when the physical FK confirms the relationship.
        try {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $foreignTable = strtolower((string) ($foreign['foreign_table'] ?? $foreign['foreign_table_name'] ?? $foreign['table'] ?? ''));
                if ($foreignTable !== 'booking_services') continue;
                foreach ((array) ($foreign['columns'] ?? $foreign['local_columns'] ?? []) as $local) {
                    $local = (string) $local;
                    if ($local !== '' && in_array($local, $columns, true)) return $local;
                }
            }
        } catch (Throwable) {
        }
        // Preserve legacy behavior only when service_id is the sole available
        // linkage column; booking_service_id / confirmed FK remains preferred.
        return in_array('service_id', $columns, true) ? 'service_id' : null;
    }

    /** @return list<array<string,mixed>> */
    private function stayRows(int $booking, int $serviceId, string $table): array
    {
        $columns = $this->physicalColumnListing($table);
        $query = DB::table($table)->where('booking_id', $booking);
        if ($serviceId > 0) {
            $serviceCol = $this->resolveBookingServiceLinkColumn($table, $columns);
            if ($serviceCol) $query->where($serviceCol, $serviceId);
        }
        $order = $this->firstColumn($columns, ['sort_order', 'sequence', 'sequence_no', 'id']);
        try {
            $records = $order ? $query->orderBy($order)->get() : $query->get();
            return $records->map(function (object $record) use ($columns, $table): array {
                $row = (array) $record;
                $checkIn = $this->dateString($this->valueFrom($row, $columns, ['check_in', 'check_in_date', 'checkin_date']));
                $checkOut = $this->dateString($this->valueFrom($row, $columns, ['check_out', 'check_out_date', 'checkout_date']));
                $nights = (int) ($this->valueFrom($row, $columns, ['nights', 'total_nights', 'night_count']) ?? 0);
                if ($nights <= 0 && $checkIn && $checkOut) {
                    try { $nights = (int) (new \DateTimeImmutable($checkIn))->diff(new \DateTimeImmutable($checkOut))->days; } catch (Throwable) {}
                }
                // ERP-11.3.134: long-lived Hotel schemas can keep several
                // commercial aliases together. Read the first meaningful numeric
                // value instead of letting an empty/zero legacy sibling hide the
                // field that actually received the rate/total.
                $saleRate = $this->numberFromMeaningful($row, $columns, ['sale_rate', 'selling_rate', 'customer_rate', 'sale_price', 'selling_price', 'customer_price', 'sale', 'sell_price', 'room_sale_rate', 'nightly_sale_rate', 'selling_price_per_night', 'customer_price_per_night']);
                if ($saleRate <= 0) $saleRate = $this->numberFromHotelCommercialSemantics($row, $table, $columns, 'sale_rate');
                $costRate = $this->numberFromMeaningful($row, $columns, ['cost_rate', 'supplier_rate', 'vendor_rate', 'cost_price', 'supplier_cost', 'vendor_cost', 'cost', 'purchase_price', 'room_cost_rate', 'nightly_cost_rate', 'cost_price_per_night', 'supplier_price_per_night', 'vendor_price_per_night']);
                if ($costRate <= 0) $costRate = $this->numberFromHotelCommercialSemantics($row, $table, $columns, 'cost_rate');
                $customerTotal = $this->numberFromMeaningful($row, $columns, ['selling_total', 'customer_total', 'sale_total', 'total_sale', 'customer_amount', 'sale_amount', 'selling_amount', 'gross_sale']);
                if ($customerTotal <= 0) $customerTotal = $this->numberFromHotelCommercialSemantics($row, $table, $columns, 'customer_total');
                $vendorTotal = $this->numberFromMeaningful($row, $columns, ['net_supplier_cost', 'supplier_total', 'vendor_total', 'cost_total', 'total_cost', 'vendor_amount', 'cost_amount', 'supplier_amount', 'gross_cost']);
                if ($vendorTotal <= 0) $vendorTotal = $this->numberFromHotelCommercialSemantics($row, $table, $columns, 'vendor_total');

                $stayMeta = $this->hotelStayMetaFromRow($row, $table, $columns);
                if ($saleRate <= 0 && array_key_exists('sale_rate', $stayMeta)) $saleRate = (float) $stayMeta['sale_rate'];
                if ($costRate <= 0 && array_key_exists('cost_rate', $stayMeta)) $costRate = (float) $stayMeta['cost_rate'];
                if ($customerTotal <= 0 && array_key_exists('customer_total', $stayMeta)) $customerTotal = (float) $stayMeta['customer_total'];
                if ($vendorTotal <= 0 && array_key_exists('vendor_total', $stayMeta)) $vendorTotal = (float) $stayMeta['vendor_total'];
                if ($customerTotal <= 0 && $saleRate > 0 && $nights > 0) $customerTotal = round($saleRate * $nights, 2);
                if ($vendorTotal <= 0 && $costRate > 0 && $nights > 0) $vendorTotal = round($costRate * $nights, 2);
                // If the native installed schema stores only line totals, recover
                // the exact per-night rate deterministically from total / nights.
                if ($saleRate <= 0 && $customerTotal > 0 && $nights > 0) $saleRate = round($customerTotal / $nights, 2);
                if ($costRate <= 0 && $vendorTotal > 0 && $nights > 0) $costRate = round($vendorTotal / $nights, 2);

                $cityId = (int) ($this->valueFrom($row, $columns, ['city_id', 'travel_city_id']) ?? 0);
                $cityName = $this->preferredCityLabel($this->stringFrom($row, $columns, ['city', 'city_name', 'location']));
                if ($cityName === '' && $cityId > 0) $cityName = $this->cityNameById($cityId);
                $hotelId = (int) ($this->valueFrom($row, $columns, ['hotel_id']) ?? 0);
                $hotelName = $this->stringFrom($row, $columns, ['hotel_name', 'name', 'property_name']);
                if ($hotelName === '' && $hotelId > 0) $hotelName = $this->hotelNameById($hotelId);
                $vendorMeta = $this->vendorMetaFromRow($row, $columns);
                $vendorId = $this->firstPositiveInt($row, ['vendor_id', 'supplier_id', 'service_provider_id']);
                $vendorName = $this->firstNonEmpty($row, ['vendor_name', 'supplier_name', 'provider_name']);
                if ($vendorId <= 0) $vendorId = (int) ($vendorMeta['id'] ?? ($stayMeta['vendor_id'] ?? 0));
                if ($vendorName === '') $vendorName = trim((string) ($vendorMeta['name'] ?? ($stayMeta['vendor_name'] ?? '')));

                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'vendor_id' => $vendorId,
                    'vendor_name' => $vendorName,
                    'city_id' => $cityId,
                    'city' => $cityName,
                    'hotel_id' => $hotelId,
                    'hotel_name' => $hotelName,
                    'confirmation_no' => $this->stringFrom($row, $columns, ['confirmation_no', 'confirmation_number', 'brn', 'brn_number', 'booking_reference', 'voucher_no', 'reference_no']),
                    'room_type' => $this->stringFrom($row, $columns, ['room_type', 'room_category', 'room_name']),
                    'board' => $this->normalizeBoard($this->stringFrom($row, $columns, ['board', 'board_basis', 'meal_plan', 'meal', 'meal_basis'])),
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'nights' => max(0, $nights),
                    'sale_rate' => round($saleRate, 2),
                    'cost_rate' => round($costRate, 2),
                    'customer_total' => round($customerTotal, 2),
                    'vendor_total' => round($vendorTotal, 2),
                    'margin' => round($customerTotal - $vendorTotal, 2),
                ];
            })->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<array<string,mixed>> $stays */
    private function syncStayRows(int $booking, int $serviceId, object $bookingRow, string $table, array $stays): void
    {
        $columns = $this->physicalColumnListing($table);
        $serviceCol = $this->resolveBookingServiceLinkColumn($table, $columns);
        $prototype = DB::table($table)->where('booking_id', $booking)->orderByDesc($this->firstColumn($columns, ['id', 'sort_order']) ?: 'id')->first();
        if (! $prototype) $prototype = DB::table($table)->orderByDesc($this->firstColumn($columns, ['id', 'sort_order']) ?: 'id')->first();

        $delete = DB::table($table)->where('booking_id', $booking);
        if ($serviceCol) $delete->where($serviceCol, $serviceId);
        $delete->delete();

        $bookingData = (array) $bookingRow;
        foreach ($stays as $index => $stay) {
            $row = [];
            $this->put($row, $columns, ['booking_id'], $booking);
            if ($serviceCol) $row[$serviceCol] = $serviceId;
            foreach (['company_id', 'branch_id', 'customer_id', 'agent_id', 'salesperson_id', 'currency_id', 'tenant_id', 'office_id'] as $field) {
                if (in_array($field, $columns, true) && array_key_exists($field, $bookingData)) $row[$field] = $bookingData[$field];
            }
            $this->put($row, $columns, ['vendor_id', 'supplier_id', 'service_provider_id'], $stay['vendor_id']);
            $this->putAll($row, $columns, ['vendor_name', 'supplier_name', 'provider_name'], $stay['vendor_name']);
            $this->writeVendorMeta($row, $columns, (int) $stay['vendor_id'], (string) $stay['vendor_name']);
            $this->writeHotelStayMeta($row, $table, $columns, $stay);
            $this->put($row, $columns, ['city_id', 'travel_city_id'], $stay['city_id']);
            $this->put($row, $columns, ['city', 'city_name', 'location'], $stay['city']);
            $this->put($row, $columns, ['hotel_id'], $stay['hotel_id']);
            $this->put($row, $columns, ['hotel_name', 'name', 'property_name'], $stay['hotel_name']);
            $this->put($row, $columns, ['confirmation_no', 'confirmation_number', 'brn', 'brn_number', 'booking_reference', 'voucher_no', 'reference_no'], $stay['confirmation_no'] ?: null);
            $this->put($row, $columns, ['room_type', 'room_category', 'room_name'], $stay['room_type']);
            $this->putBoardValues($row, $table, $columns, (string) $stay['board']);
            $this->put($row, $columns, ['check_in', 'check_in_date', 'checkin_date'], $stay['check_in']);
            $this->put($row, $columns, ['check_out', 'check_out_date', 'checkout_date'], $stay['check_out']);
            $this->put($row, $columns, ['nights', 'total_nights', 'night_count'], $stay['nights']);
            $this->put($row, $columns, ['rooms', 'room_count', 'number_of_rooms', 'quantity', 'qty'], 1);
            $this->putAll($row, $columns, ['sale_rate', 'selling_rate', 'customer_rate', 'sale_price', 'selling_price', 'customer_price', 'sale', 'sell_price', 'room_sale_rate', 'nightly_sale_rate', 'selling_price_per_night', 'customer_price_per_night'], $stay['sale_rate']);
            $this->putAll($row, $columns, ['cost_rate', 'supplier_rate', 'vendor_rate', 'cost_price', 'supplier_cost', 'vendor_cost', 'cost', 'purchase_price', 'room_cost_rate', 'nightly_cost_rate', 'cost_price_per_night', 'supplier_price_per_night', 'vendor_price_per_night'], $stay['cost_rate']);
            $this->putAll($row, $columns, ['selling_total', 'customer_total', 'sale_total', 'total_sale', 'customer_amount', 'sale_amount', 'selling_amount', 'gross_sale'], $stay['customer_total']);
            $this->putAll($row, $columns, ['net_supplier_cost', 'supplier_total', 'vendor_total', 'cost_total', 'total_cost', 'vendor_amount', 'cost_amount', 'supplier_amount', 'gross_cost'], $stay['vendor_total']);
            $this->putHotelCommercialSemantics($row, $table, $columns, $stay);
            $this->putAll($row, $columns, ['margin', 'gross_margin', 'net_margin', 'profit'], $stay['margin']);
            $this->put($row, $columns, ['sort_order', 'sequence', 'sequence_no'], ($index + 1) * 10);
            $this->putNativeEnum($row, $table, $columns, ['status'], 'booked', ['BOOKED', 'confirmed', 'CONFIRMED', 'active', 'ACTIVE', 'requested', 'REQUESTED']);
            foreach (['is_active' => 1, 'active' => 1] as $field => $value) if (in_array($field, $columns, true)) $row[$field] = $value;
            foreach (['created_by', 'created_by_id', 'updated_by', 'updated_by_id', 'user_id'] as $field) if (in_array($field, $columns, true) && Auth::id()) $row[$field] = Auth::id();
            if (in_array('created_at', $columns, true)) $row['created_at'] = now();
            if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();

            $known = $row + [
                'booking_id' => $booking,
                'booking_service_id' => $serviceId,
                'service_id' => $serviceId,
                'vendor_id' => $stay['vendor_id'],
                'supplier_id' => $stay['vendor_id'],
                'vendor_name' => $stay['vendor_name'],
                'supplier_name' => $stay['vendor_name'],
                'provider_name' => $stay['vendor_name'],
                'hotel_id' => $stay['hotel_id'],
                'city_id' => $stay['city_id'],
                'hotel_name' => $stay['hotel_name'],
                'name' => $stay['hotel_name'],
                'city' => $stay['city'],
                'city_name' => $stay['city'],
                'confirmation_no' => $stay['confirmation_no'] ?: 'N/A',
                'room_type' => $stay['room_type'],
                'board' => $stay['board'],
                'meal_plan' => $stay['board'],
                'check_in' => $stay['check_in'],
                'check_out' => $stay['check_out'],
                'nights' => $stay['nights'],
                'rooms' => 1,
                'quantity' => 1,
                'status' => 'booked',
            ];
            $row = $this->fillRequiredByPrototype($table, $row, $prototype ? (array) $prototype : [], $known);
            $this->assertRequiredContract($table, $row, 'Hotel stay rows');
            DB::table($table)->insert($row);
        }
    }

    /** @return array{id:int,name:string} */
    private function vendorMetaFromRow(array $row, array $columns): array
    {
        foreach (['meta', 'metadata', 'extra_data', 'details_json', 'attributes'] as $field) {
            if (! in_array($field, $columns, true) || ! array_key_exists($field, $row)) continue;
            $raw = $row[$field];
            $decoded = is_array($raw) ? $raw : json_decode((string) ($raw ?? ''), true);
            if (! is_array($decoded)) continue;
            $vendor = $decoded['et_erp_vendor'] ?? null;
            if (is_array($vendor)) return ['id' => (int) ($vendor['id'] ?? 0), 'name' => trim((string) ($vendor['name'] ?? ''))];
        }
        return ['id' => 0, 'name' => ''];
    }

    private function writeVendorMeta(array &$row, array $columns, int $vendorId, string $vendorName): void
    {
        if ($vendorId <= 0 && trim($vendorName) === '') return;
        foreach (['meta', 'metadata', 'extra_data', 'details_json', 'attributes'] as $field) {
            if (! in_array($field, $columns, true)) continue;
            $existing = [];
            $raw = $row[$field] ?? null;
            if (is_array($raw)) $existing = $raw;
            elseif ($raw !== null && $raw !== '') {
                $decoded = json_decode((string) $raw, true);
                if (! is_array($decoded)) continue; // try the next JSON-capable alias
                $existing = $decoded;
            }
            $existing['et_erp_vendor'] = [
                'version' => 'ERP-11.3.134',
                'id' => max(0, $vendorId),
                'name' => trim($vendorName),
            ];
            $row[$field] = json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    /** @return array<string,mixed> */
    private function hotelStayMetaFromRow(array $row, string $table, array $columns): array
    {
        foreach ($this->jsonCarrierFields($table, $columns) as $field) {
            if (! array_key_exists($field, $row)) continue;
            $raw = $row[$field];
            $decoded = is_array($raw) ? $raw : json_decode((string) ($raw ?? ''), true);
            if (! is_array($decoded)) continue;
            $stay = $decoded['et_erp_hotel_stay'] ?? null;
            if (is_array($stay)) return $stay;
        }
        foreach ($this->taggedTextCarrierFields($table, $columns) as $field) {
            if (! array_key_exists($field, $row)) continue;
            $stay = $this->readTaggedPayload((string) ($row[$field] ?? ''), 'ETERP_HOTEL_STAY');
            if (is_array($stay)) return $stay;
        }
        return [];
    }

    /** @param array<string,mixed> $stay */
    private function writeHotelStayMeta(array &$row, string $table, array $columns, array $stay): void
    {
        $payload = [
            'version' => 'ERP-11.3.134',
            'vendor_id' => (int) ($stay['vendor_id'] ?? 0),
            'vendor_name' => trim((string) ($stay['vendor_name'] ?? '')),
            'city_id' => (int) ($stay['city_id'] ?? 0),
            'city' => trim((string) ($stay['city'] ?? '')),
            'hotel_id' => (int) ($stay['hotel_id'] ?? 0),
            'hotel_name' => trim((string) ($stay['hotel_name'] ?? '')),
            'confirmation_no' => trim((string) ($stay['confirmation_no'] ?? '')),
            'room_type' => trim((string) ($stay['room_type'] ?? '')),
            'board' => strtoupper(trim((string) ($stay['board'] ?? 'RO'))),
            'check_in' => trim((string) ($stay['check_in'] ?? '')),
            'check_out' => trim((string) ($stay['check_out'] ?? '')),
            'nights' => max(0, (int) ($stay['nights'] ?? 0)),
            'sale_rate' => round((float) ($stay['sale_rate'] ?? 0), 2),
            'cost_rate' => round((float) ($stay['cost_rate'] ?? 0), 2),
            'customer_total' => round((float) ($stay['customer_total'] ?? 0), 2),
            'vendor_total' => round((float) ($stay['vendor_total'] ?? 0), 2),
        ];
        foreach ($this->jsonCarrierFields($table, $columns) as $field) {
            $existing = [];
            $raw = $row[$field] ?? null;
            if (is_array($raw)) $existing = $raw;
            elseif ($raw !== null && $raw !== '') {
                $decoded = json_decode((string) $raw, true);
                if (! is_array($decoded)) continue;
                $existing = $decoded;
            }
            $existing['et_erp_hotel_stay'] = $payload;
            $row[$field] = json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
        // Some older native Hotel tables have no JSON/meta column at all. Keep
        // the exact entered rates in a tagged native text carrier instead of
        // silently losing them after a successful save. Existing human text is
        // preserved outside the ET ERP tag.
        foreach ($this->taggedTextCarrierFields($table, $columns) as $field) {
            $row[$field] = $this->writeTaggedPayload((string) ($row[$field] ?? ''), 'ETERP_HOTEL_STAY', $payload);
            return;
        }
    }

    /** @return list<string> */
    private function jsonCarrierFields(string $table, array $columns): array
    {
        $preferred = ['meta', 'metadata', 'extra_data', 'details_json', 'attributes'];
        $result = [];
        foreach ($preferred as $field) if (in_array($field, $columns, true)) $result[] = $field;
        $metadata = $this->columnMetadata($table);
        foreach ($columns as $field) {
            if (in_array($field, $result, true)) continue;
            $meta = $metadata[$field] ?? [];
            $type = $this->metaType($meta);
            if ($type === 'json' || (in_array($type, ['text','tinytext','mediumtext','longtext'], true) && preg_match('/(meta|json|data|details|attributes)/i', $field))) $result[] = $field;
        }
        return $result;
    }

    /** @return list<string> */
    private function taggedTextCarrierFields(string $table, array $columns): array
    {
        $metadata = $this->columnMetadata($table);
        $result = [];
        foreach (['notes','remarks','internal_notes','description','details','other_details','comment','comments'] as $field) {
            if (! in_array($field, $columns, true)) continue;
            $type = $this->metaType($metadata[$field] ?? []);
            if (in_array($type, ['char','varchar','text','tinytext','mediumtext','longtext'], true)) $result[] = $field;
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function readTaggedPayload(string $text, string $tag): ?array
    {
        if ($text === '') return null;
        if (! preg_match('/\[\['.preg_quote($tag, '/').':([A-Za-z0-9+\/=]+)\]\]/', $text, $match)) return null;
        $json = base64_decode((string) ($match[1] ?? ''), true);
        if ($json === false) return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $payload */
    private function writeTaggedPayload(string $text, string $tag, array $payload): string
    {
        $encoded = base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $marker = '[['.$tag.':'.$encoded.']]';
        $clean = preg_replace('/\s*\[\['.preg_quote($tag, '/').':[A-Za-z0-9+\/=]+\]\]\s*/', '', $text) ?? $text;
        $clean = trim($clean);
        return $clean === '' ? $marker : $clean."\n".$marker;
    }

    private function firstPositiveInt(array $row, array $fields): int
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row)) continue;
            $value = (int) ($row[$field] ?? 0);
            if ($value > 0) return $value;
        }
        return 0;
    }

    /** @param list<array<string,mixed>> $stays */
    private function syncHotelServiceVendorContext(int $serviceId, array $stays): void
    {
        if ($serviceId <= 0 || ! Schema::hasTable('booking_services') || ! $stays) return;
        $unique = [];
        foreach ($stays as $stay) {
            $id = (int) ($stay['vendor_id'] ?? 0);
            $name = trim((string) ($stay['vendor_name'] ?? ''));
            if ($id > 0 || $name !== '') $unique[($id > 0 ? 'id:'.$id : 'name:'.strtolower($name))] = ['id' => $id, 'name' => $name];
        }
        if (count($unique) !== 1) return; // multiple hotel vendors belong at stay-row level
        $vendor = array_values($unique)[0];
        $columns = $this->physicalColumnListing('booking_services');
        $update = [];
        if ((int) $vendor['id'] > 0) $this->put($update, $columns, ['supplier_id', 'vendor_id', 'service_provider_id'], (int) $vendor['id']);
        $this->putAll($update, $columns, ['supplier_name', 'vendor_name', 'provider_name'], (string) $vendor['name']);
        $this->hydrateMetaFields('booking_services', $serviceId, $columns, $update);
        $this->writeVendorMeta($update, $columns, (int) $vendor['id'], (string) $vendor['name']);
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if ($update) DB::table('booking_services')->where('id', $serviceId)->update($update);
    }

    /** @param list<array<string,mixed>> $stays */
    private function syncHotelServiceSnapshot(int $serviceId, array $stays): void
    {
        if ($serviceId <= 0 || ! Schema::hasTable('booking_services')) return;
        $table = 'booking_services';
        $columns = $this->physicalColumnListing($table);
        $current = (array) (DB::table($table)->where('id', $serviceId)->first() ?? (object) []);
        $payload = [
            'version' => 'ERP-11.3.134',
            'stays' => array_values(array_map(static function (array $stay): array {
                return [
                    'vendor_id' => (int) ($stay['vendor_id'] ?? 0),
                    'vendor_name' => trim((string) ($stay['vendor_name'] ?? '')),
                    'city_id' => (int) ($stay['city_id'] ?? 0),
                    'city' => trim((string) ($stay['city'] ?? '')),
                    'hotel_id' => (int) ($stay['hotel_id'] ?? 0),
                    'hotel_name' => trim((string) ($stay['hotel_name'] ?? '')),
                    'confirmation_no' => trim((string) ($stay['confirmation_no'] ?? '')),
                    'room_type' => trim((string) ($stay['room_type'] ?? '')),
                    'board' => strtoupper(trim((string) ($stay['board'] ?? 'RO'))),
                    'check_in' => trim((string) ($stay['check_in'] ?? '')),
                    'check_out' => trim((string) ($stay['check_out'] ?? '')),
                    'nights' => max(0, (int) ($stay['nights'] ?? 0)),
                    'sale_rate' => round((float) ($stay['sale_rate'] ?? 0), 2),
                    'cost_rate' => round((float) ($stay['cost_rate'] ?? 0), 2),
                    'customer_total' => round((float) ($stay['customer_total'] ?? 0), 2),
                    'vendor_total' => round((float) ($stay['vendor_total'] ?? 0), 2),
                ];
            }, $stays)),
        ];
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
            $existing['et_erp_hotel_stays'] = $payload;
            $update[$field] = json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            break;
        }
        if (! $update) {
            foreach ($this->taggedTextCarrierFields($table, $columns) as $field) {
                $update[$field] = $this->writeTaggedPayload((string) ($current[$field] ?? ''), 'ETERP_HOTEL_STAYS', $payload);
                break;
            }
        }
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if ($update) DB::table($table)->where('id', $serviceId)->update($update);
    }

    /** @return list<array<string,mixed>> */
    private function hotelServiceSnapshotFromRow(array $row): array
    {
        $table = 'booking_services';
        $columns = array_keys($row);
        foreach ($this->jsonCarrierFields($table, $columns) as $field) {
            if (! array_key_exists($field, $row)) continue;
            $raw = $row[$field];
            $decoded = is_array($raw) ? $raw : json_decode((string) ($raw ?? ''), true);
            if (! is_array($decoded)) continue;
            $snapshot = $decoded['et_erp_hotel_stays']['stays'] ?? null;
            if (is_array($snapshot)) return array_values(array_filter($snapshot, 'is_array'));
        }
        foreach ($this->taggedTextCarrierFields($table, $columns) as $field) {
            if (! array_key_exists($field, $row)) continue;
            $payload = $this->readTaggedPayload((string) ($row[$field] ?? ''), 'ETERP_HOTEL_STAYS');
            $snapshot = is_array($payload) ? ($payload['stays'] ?? null) : null;
            if (is_array($snapshot)) return array_values(array_filter($snapshot, 'is_array'));
        }
        return [];
    }

    /** @param list<array<string,mixed>> $stays @param list<array<string,mixed>> $snapshot */
    private function overlayHotelServiceSnapshot(array $stays, array $snapshot): array
    {
        if (! $snapshot) return $stays;

        // ERP-11.3.134: the native stay rows remain the operational source of
        // truth, while the existing booking_services payload is a lossless
        // compatibility snapshot. If a legacy native Hotel table cannot expose
        // one row cleanly, seed/append from the snapshot instead of dropping the
        // entire Hotel workspace after refresh.
        while (count($stays) < count($snapshot)) {
            $saved = $snapshot[count($stays)] ?? [];
            if (! is_array($saved)) break;
            $stays[] = [
                'id' => 0,
                'vendor_id' => (int) ($saved['vendor_id'] ?? 0),
                'vendor_name' => trim((string) ($saved['vendor_name'] ?? '')),
                'city_id' => (int) ($saved['city_id'] ?? 0),
                'city' => trim((string) ($saved['city'] ?? '')),
                'hotel_id' => (int) ($saved['hotel_id'] ?? 0),
                'hotel_name' => trim((string) ($saved['hotel_name'] ?? '')),
                'confirmation_no' => trim((string) ($saved['confirmation_no'] ?? '')),
                'room_type' => trim((string) ($saved['room_type'] ?? '')),
                'board' => $this->normalizeBoard((string) ($saved['board'] ?? 'RO')),
                'check_in' => $this->dateString($saved['check_in'] ?? null),
                'check_out' => $this->dateString($saved['check_out'] ?? null),
                'nights' => max(0, (int) ($saved['nights'] ?? 0)),
                'sale_rate' => round((float) ($saved['sale_rate'] ?? 0), 2),
                'cost_rate' => round((float) ($saved['cost_rate'] ?? 0), 2),
                'customer_total' => round((float) ($saved['customer_total'] ?? 0), 2),
                'vendor_total' => round((float) ($saved['vendor_total'] ?? 0), 2),
                'margin' => round((float) ($saved['customer_total'] ?? 0) - (float) ($saved['vendor_total'] ?? 0), 2),
            ];
        }

        foreach ($stays as $index => &$stay) {
            $saved = $snapshot[$index] ?? null;
            if (! is_array($saved)) continue;
            foreach (['vendor_id','city_id','hotel_id','nights'] as $field) {
                if ((int) ($stay[$field] ?? 0) <= 0 && (int) ($saved[$field] ?? 0) > 0) $stay[$field] = (int) $saved[$field];
            }
            foreach (['vendor_name','city','hotel_name','confirmation_no','room_type'] as $field) {
                if (trim((string) ($stay[$field] ?? '')) === '' && trim((string) ($saved[$field] ?? '')) !== '') $stay[$field] = trim((string) $saved[$field]);
            }
            if (trim((string) ($stay['board'] ?? '')) === '' && trim((string) ($saved['board'] ?? '')) !== '') $stay['board'] = $this->normalizeBoard((string) $saved['board']);
            foreach (['check_in','check_out'] as $field) {
                if (empty($stay[$field]) && ! empty($saved[$field])) $stay[$field] = $this->dateString($saved[$field]);
            }
            foreach (['sale_rate','cost_rate','customer_total','vendor_total'] as $field) {
                if (array_key_exists($field, $saved)) $stay[$field] = round((float) $saved[$field], 2);
            }
            $stay['margin'] = round((float) ($stay['customer_total'] ?? 0) - (float) ($stay['vendor_total'] ?? 0), 2);
        }
        unset($stay);
        return $stays;
    }

    /** @param list<array<string,mixed>> $stays @return list<array<string,mixed>> */
    private function overlayHotelServiceVendorContext(array $stays, array $serviceRow): array
    {
        if (! $stays || ! $serviceRow) return $stays;
        $columns = array_keys($serviceRow);
        $meta = $this->vendorMetaFromRow($serviceRow, $columns);
        $vendorId = $this->firstPositiveInt($serviceRow, ['supplier_id', 'vendor_id', 'service_provider_id']);
        $vendorName = $this->firstNonEmpty($serviceRow, ['supplier_name', 'vendor_name', 'provider_name']);
        if ($vendorId <= 0) $vendorId = (int) ($meta['id'] ?? 0);
        if ($vendorName === '') $vendorName = trim((string) ($meta['name'] ?? ''));
        if ($vendorId <= 0 && $vendorName === '') return $stays;
        foreach ($stays as &$stay) {
            if ((int) ($stay['vendor_id'] ?? 0) <= 0 && $vendorId > 0) $stay['vendor_id'] = $vendorId;
            if (trim((string) ($stay['vendor_name'] ?? '')) === '' && $vendorName !== '') $stay['vendor_name'] = $vendorName;
        }
        unset($stay);
        return $stays;
    }

    private function hydrateMetaFields(string $table, int $id, array $columns, array &$target): void
    {
        if ($id <= 0) return;
        foreach (['meta', 'metadata', 'extra_data', 'details_json', 'attributes'] as $field) {
            if (! in_array($field, $columns, true)) continue;
            try {
                $value = DB::table($table)->where('id', $id)->value($field);
                if ($value !== null) $target[$field] = $value;
            } catch (Throwable) {
            }
        }
    }

    /** @param array{customer_total:float,vendor_total:float,margin:float} $summary */
    private function syncServiceTotals(int $serviceId, array $summary): void
    {
        if ($serviceId <= 0 || ! Schema::hasTable('booking_services')) return;
        $columns = $this->physicalColumnListing('booking_services');
        $update = [];
        $this->putAll($update, $columns, ['selling_total', 'customer_total', 'sale_total', 'total_sale', 'customer_amount', 'selling_amount'], $summary['customer_total']);
        $this->putAll($update, $columns, ['net_supplier_cost', 'supplier_total', 'vendor_total', 'cost_total', 'total_cost', 'supplier_amount', 'vendor_amount'], $summary['vendor_total']);
        $this->putAll($update, $columns, ['margin', 'gross_margin', 'net_margin', 'profit'], $summary['margin']);
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if ($update) DB::table('booking_services')->where('id', $serviceId)->update($update);
    }

    /** @param list<array<string,mixed>> $stays @return array{customer_total:float,vendor_total:float,margin:float,stay_count:int} */
    private function summary(array $stays): array
    {
        $customer = 0.0;
        $vendor = 0.0;
        foreach ($stays as $row) {
            $customer += (float) ($row['customer_total'] ?? ((float) ($row['sale_rate'] ?? 0) * (int) ($row['nights'] ?? 0)));
            $vendor += (float) ($row['vendor_total'] ?? ((float) ($row['cost_rate'] ?? 0) * (int) ($row['nights'] ?? 0)));
        }
        return [
            'customer_total' => round($customer, 2),
            'vendor_total' => round($vendor, 2),
            'margin' => round($customer - $vendor, 2),
            'stay_count' => count($stays),
        ];
    }

    private function normalizeBoard(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === 'BREAKFAST' || $value === 'BED & BREAKFAST' || $value === 'BED AND BREAKFAST') return 'BB';
        return $value === 'BB' ? 'BB' : 'RO';
    }

    /** @param list<array<string,mixed>> $expected @param list<array<string,mixed>> $actual */
    private function assertPersistedHotelCommercials(array $expected, array $actual): void
    {
        if (count($actual) < count($expected)) {
            throw ValidationException::withMessages([
                'hotel' => 'Hotel Data was not fully persisted to the native Hotel store. No partial Hotel save was committed.',
            ]);
        }
        foreach ($expected as $index => $wanted) {
            $saved = $actual[$index] ?? [];
            foreach (['sale_rate', 'cost_rate', 'customer_total', 'vendor_total'] as $field) {
                $a = round((float) ($wanted[$field] ?? 0), 2);
                $b = round((float) ($saved[$field] ?? 0), 2);
                if (abs($a - $b) > 0.01) {
                    throw ValidationException::withMessages([
                        'hotel' => 'Hotel commercial persistence verification failed for Hotel '.($index + 1).' field '.$field.'. No partial Hotel save was committed.',
                    ]);
                }
            }
        }
    }

    private function queryFailureMessage(QueryException $e, string $stage): string
    {
        $message = (string) $e->getMessage();
        if (preg_match("/Field '([^']+)' doesn't have a default value/i", $message, $match)) {
            return 'Native Hotel save failed while writing '.$stage.'. The live ERP requires field: '.$match[1].'.';
        }
        if (preg_match("/Column '([^']+)' cannot be null/i", $message, $match)) {
            return 'Native Hotel save failed while writing '.$stage.'. The live ERP requires field: '.$match[1].'.';
        }
        return 'Native Hotel save failed while writing '.$stage.'. '.$this->shortDatabaseMessage($message);
    }

    private function shortDatabaseMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message));
        return strlen($message) > 260 ? substr($message, 0, 257).'...' : $message;
    }

    private function resolveCityOrHotelPrototypeValue(string $field, array $known): mixed
    {
        if (array_key_exists($field, $known) && $known[$field] !== null && $known[$field] !== '') return $known[$field];
        return null;
    }

    private function fillRequiredByPrototype(string $table, array $row, array $prototype, array $known): array
    {
        $metadata = $this->columnMetadata($table);
        foreach ($metadata as $field => $meta) {
            if ($this->columnCanBeOmitted($field, $meta)) continue;
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') continue;
            $knownValue = $this->resolveCityOrHotelPrototypeValue($field, $known);
            if ($knownValue !== null && $knownValue !== '') {
                $row[$field] = $knownValue;
                continue;
            }
            $generated = $this->generatedRequiredValue($field, $known);
            if ($generated !== null) {
                $row[$field] = $generated;
                continue;
            }
            if (array_key_exists($field, $prototype) && $prototype[$field] !== null && $prototype[$field] !== '') {
                $row[$field] = $prototype[$field];
                continue;
            }
            $fallback = $this->safeRequiredFallback($field, $meta, $known);
            if ($fallback !== null) $row[$field] = $fallback;
        }
        $allowed = $metadata ? array_keys($metadata) : $this->physicalColumnListing($table);
        return array_intersect_key($row, array_flip($allowed));
    }

    private function generatedRequiredValue(string $field, array $known): mixed
    {
        $lower = strtolower($field);
        if ($lower === 'uuid' || str_ends_with($lower, '_uuid')) return (string) Str::uuid();
        if ($lower === 'public_id' || str_ends_with($lower, '_public_id')) return strtoupper(Str::random(16));
        if ($lower === 'slug') return Str::slug((string) ($known['hotel_name'] ?? $known['name'] ?? $known['city'] ?? 'record')).'-'.strtolower(Str::random(5));
        return null;
    }

    private function safeRequiredFallback(string $field, array $meta, array $known): mixed
    {
        $lower = strtolower($field);
        $type = $this->metaType($meta);
        if (str_contains($lower, 'status')) {
            $enum = $this->enumValues($meta);
            foreach (['booked', 'BOOKED', 'active', 'ACTIVE', 'pending', 'PENDING', 'draft', 'DRAFT', 'requested', 'REQUESTED'] as $candidate) {
                if (! $enum || in_array($candidate, $enum, true)) return $candidate;
            }
        }
        if (str_ends_with($lower, '_id')) {
            foreach ([$field, str_replace('_id', '', $field).'_id'] as $key) {
                if (array_key_exists($key, $known) && (int) $known[$key] > 0) return (int) $known[$key];
            }
            return null;
        }
        if ($type === 'enum') {
            $enum = $this->enumValues($meta);
            if ($enum) return $enum[0];
        }
        if (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'numeric', 'float', 'double', 'real'], true)) return 0;
        if (in_array($type, ['date'], true)) return (string) ($known['check_in'] ?? date('Y-m-d'));
        if (in_array($type, ['datetime', 'timestamp'], true)) return now();
        if (in_array($type, ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum'], true)) {
            if (str_contains($lower, 'hotel') || $lower === 'name' || str_contains($lower, 'title')) return (string) ($known['hotel_name'] ?? $known['name'] ?? 'Hotel');
            if (str_contains($lower, 'city')) return (string) ($known['city'] ?? 'N/A');
            if (str_contains($lower, 'reference') || str_contains($lower, 'confirmation') || str_contains($lower, 'voucher') || str_ends_with($lower, '_no')) return (string) ($known['confirmation_no'] ?? 'N/A');
            if (str_contains($lower, 'room')) return (string) ($known['room_type'] ?? 'N/A');
            if (str_contains($lower, 'board') || str_contains($lower, 'meal')) return (string) ($known['board'] ?? 'RO');
            return 'N/A';
        }
        return null;
    }

    private function assertRequiredContract(string $table, array $row, string $stage): void
    {
        $metadata = $this->columnMetadata($table);
        if (! $metadata) return;
        $missing = [];
        foreach ($metadata as $field => $meta) {
            if ($this->columnCanBeOmitted($field, $meta)) continue;
            if (! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') $missing[] = $field;
        }
        if ($missing) {
            throw ValidationException::withMessages([
                'hotel' => 'Native Hotel preflight found unresolved required field(s) in '.$stage.': '.implode(', ', array_slice($missing, 0, 20)).'. No Hotel data was written.',
            ]);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function columnMetadata(string $table): array
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
        $result = [];
        try {
            foreach (DB::select('SHOW FULL COLUMNS FROM `'.$table.'`') as $column) {
                $raw = (array) $column;
                $name = (string) ($raw['Field'] ?? '');
                if ($name === '') continue;
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
        } catch (Throwable) {
        }
        if ($result) return $result;
        try {
            foreach (Schema::getColumns($table) as $column) {
                if (! is_array($column)) continue;
                $name = (string) ($column['name'] ?? $column['column_name'] ?? '');
                if ($name !== '') $result[$name] = $column;
            }
        } catch (Throwable) {
        }
        return $result;
    }

    /** @return list<string> */
    private function physicalColumnListing(string $table): array
    {
        return array_values(array_keys($this->columnMetadata($table)));
    }

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
        if (preg_match('/^([a-z]+)/', $type, $match)) return (string) $match[1];
        return $type;
    }

    /** @return list<string> */
    private function enumValues(array $meta): array
    {
        $type = (string) ($meta['type'] ?? $meta['type_name'] ?? '');
        if (! preg_match('/^enum\((.*)\)$/i', $type, $match)) return [];
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $match[1], $values);
        return array_map(static fn (string $value): string => stripcslashes($value), $values[1] ?? []);
    }

    private function putBoardValues(array &$row, string $table, array $columns, string $board): void
    {
        $board = strtoupper(trim($board)) === 'BB' ? 'BB' : 'RO';
        $candidates = $board === 'BB'
            ? ['BB', 'Breakfast', 'Bed & Breakfast', 'Bed and Breakfast', 'BREAKFAST']
            : ['RO', 'Room Only', 'ROOM ONLY', 'Room only'];
        foreach (['board', 'board_basis', 'meal_plan', 'meal', 'meal_basis'] as $field) {
            if (! in_array($field, $columns, true)) continue;
            $meta = $this->columnMetadata($table)[$field] ?? [];
            $enum = $this->enumValues($meta);
            $value = $candidates[0];
            if ($enum) {
                $matched = null;
                foreach ($candidates as $candidate) {
                    foreach ($enum as $allowed) {
                        if (strcasecmp($candidate, $allowed) === 0) { $matched = $allowed; break 2; }
                    }
                }
                $value = $matched ?? ($enum[0] ?? $value);
            }
            $row[$field] = $value;
        }
    }

    private function isNumericColumnMeta(array $meta): bool
    {
        return in_array($this->metaType($meta), ['int','integer','bigint','smallint','tinyint','mediumint','decimal','numeric','float','double','real'], true);
    }

    /** @param array<string,mixed> $stay */
    private function putHotelCommercialSemantics(array &$row, string $table, array $columns, array $stay): void
    {
        $metadata = $this->columnMetadata($table);
        foreach ($columns as $field) {
            if (array_key_exists($field, $row)) continue;
            $meta = $metadata[$field] ?? [];
            if (! $this->isNumericColumnMeta($meta)) continue;
            $name = strtolower($field);
            if (preg_match('/(^|_)(id|tax|discount|commission|markup|incentive|margin|profit|other)(_|$)/', $name)) continue;
            $isTotal = str_contains($name, 'total') || str_contains($name, 'amount') || str_contains($name, 'gross') || str_contains($name, 'net');
            $isRate = str_contains($name, 'rate') || str_contains($name, 'price') || str_contains($name, 'nightly') || str_contains($name, 'per_night') || in_array($name, ['sale','cost'], true);
            $saleSide = str_contains($name, 'sale') || str_contains($name, 'sell') || str_contains($name, 'customer');
            $costSide = str_contains($name, 'cost') || str_contains($name, 'purchase') || str_contains($name, 'supplier') || str_contains($name, 'vendor');
            if ($saleSide && $isRate && ! $isTotal) { $row[$field] = $stay['sale_rate']; continue; }
            if ($costSide && $isRate && ! $isTotal) { $row[$field] = $stay['cost_rate']; continue; }
            if ($saleSide && $isTotal) { $row[$field] = $stay['customer_total']; continue; }
            if ($costSide && $isTotal) { $row[$field] = $stay['vendor_total']; continue; }
        }
    }

    private function numberFromHotelCommercialSemantics(array $row, string $table, array $columns, string $kind): float
    {
        $metadata = $this->columnMetadata($table);
        foreach ($columns as $field) {
            if (! array_key_exists($field, $row) || ! is_numeric($row[$field])) continue;
            $meta = $metadata[$field] ?? [];
            if (! $this->isNumericColumnMeta($meta)) continue;
            $name = strtolower($field);
            if (preg_match('/(^|_)(id|tax|discount|commission|markup|incentive|margin|profit|other)(_|$)/', $name)) continue;
            $isTotal = str_contains($name, 'total') || str_contains($name, 'amount') || str_contains($name, 'gross') || str_contains($name, 'net');
            $isRate = str_contains($name, 'rate') || str_contains($name, 'price') || str_contains($name, 'nightly') || str_contains($name, 'per_night') || in_array($name, ['sale','cost'], true);
            $saleSide = str_contains($name, 'sale') || str_contains($name, 'sell') || str_contains($name, 'customer');
            $costSide = str_contains($name, 'cost') || str_contains($name, 'purchase') || str_contains($name, 'supplier') || str_contains($name, 'vendor');
            $matches = match ($kind) {
                'sale_rate' => $saleSide && $isRate && ! $isTotal,
                'cost_rate' => $costSide && $isRate && ! $isTotal,
                'customer_total' => $saleSide && $isTotal,
                'vendor_total' => $costSide && $isTotal,
                default => false,
            };
            if ($matches && abs((float) $row[$field]) > 0.000001) return (float) $row[$field];
        }
        return 0.0;
    }

    private function putNativeEnum(array &$row, string $table, array $columns, array $fields, string $preferred, array $fallbacks = []): void
    {
        foreach ($fields as $field) {
            if (! in_array($field, $columns, true)) continue;
            $meta = $this->columnMetadata($table)[$field] ?? [];
            $enum = $this->enumValues($meta);
            $value = $preferred;
            if ($enum && ! in_array($value, $enum, true)) {
                foreach ($fallbacks as $candidate) if (in_array($candidate, $enum, true)) { $value = $candidate; break; }
                if (! in_array($value, $enum, true)) $value = $enum[0] ?? $preferred;
            }
            $row[$field] = $value;
            return;
        }
    }

    private function put(array &$row, array $columns, array $fields, mixed $value): void
    {
        foreach ($fields as $field) {
            if (in_array($field, $columns, true)) {
                $row[$field] = $value;
                return;
            }
        }
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
        foreach ($fields as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function valueFrom(array $row, array $columns, array $fields): mixed
    {
        foreach ($fields as $field) if (in_array($field, $columns, true) && array_key_exists($field, $row)) return $row[$field];
        return null;
    }

    private function stringFrom(array $row, array $columns, array $fields): string
    {
        return trim((string) ($this->valueFrom($row, $columns, $fields) ?? ''));
    }

    private function numberFromMeaningful(array $row, array $columns, array $fields): float
    {
        $zeroSeen = false;
        foreach ($fields as $field) {
            if (! in_array($field, $columns, true) || ! array_key_exists($field, $row)) continue;
            $value = $row[$field];
            if (! is_numeric($value)) continue;
            $number = (float) $value;
            if (abs($number) > 0.000001) return $number;
            $zeroSeen = true;
        }
        return $zeroSeen ? 0.0 : 0.0;
    }

    private function numberFrom(array $row, array $columns, array $fields): float
    {
        $value = $this->valueFrom($row, $columns, $fields);
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        try { return (new \DateTimeImmutable((string) $value))->format('Y-m-d'); } catch (Throwable) { return null; }
    }
}
