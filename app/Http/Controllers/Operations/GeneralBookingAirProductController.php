<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ERP-11.3.132
 *
 * Same-page Tickets / Flight Data bridge for GENERAL / MULTI-SERVICE bookings.
 * The bridge deliberately reuses the installed ERP's native booking_services,
 * booking_itinerary_segments and air_ticket_details stores. No parallel product
 * table and no migration are introduced.
 */
final class GeneralBookingAirProductController extends Controller
{
    public function show(Request $request, int $booking): JsonResponse
    {
        $bookingRow = $this->assertBooking($booking);
        $passengers = $this->bookingPassengers($booking);
        $service = $this->findAirService($booking);
        $tickets = $service
            ? $this->ticketRows((int) $service['id'], $passengers)
            : [];

        return response()->json([
            'ok' => true,
            'booking_id' => $booking,
            'passengers' => $passengers,
            'suppliers' => $this->supplierOptions(),
            'airlines' => $this->airlineOptions(),
            'flight_numbers' => $this->flightNumberOptions(),
            'itinerary' => $this->itineraryRows($booking),
            'common' => $this->commonSnapshot($tickets, (array) ($service['row'] ?? [])),
            'fare_commercials' => $this->fareCommercialsSnapshot($tickets),
            'tickets' => $tickets,
            'summary' => $this->summary($tickets, (int) ($service['id'] ?? 0)),
            'capabilities' => [
                'booking_services' => Schema::hasTable('booking_services'),
                'air_ticket_details' => Schema::hasTable('air_ticket_details'),
                'booking_itinerary_segments' => Schema::hasTable('booking_itinerary_segments'),
                'booking_currency' => $this->bookingCurrency($bookingRow),
            ],
        ]);
    }

    public function store(Request $request, int $booking): JsonResponse
    {
        $bookingRow = $this->assertBooking($booking);

        $data = $request->validate([
            'common' => ['nullable', 'array'],
            'common.pnr' => ['nullable', 'string', 'max:100'],
            'common.airline_pnr' => ['nullable', 'string', 'max:100'],
            'common.booking_source' => ['nullable', 'string', 'max:80'],
            'common.ticket_status' => ['nullable', Rule::in(['BOOKED', 'ISSUED', 'VOID', 'REFUNDED', 'CANCELLED', 'PENDING'])],
            'common.issue_date' => ['nullable', 'date'],
            'common.supplier_id' => ['nullable', 'integer', 'min:1'],
            'common.supplier_name' => ['nullable', 'string', 'max:255'],

            'segments' => ['nullable', 'array', 'max:12'],
            'segments.*.segment_type' => ['nullable', Rule::in(['outbound', 'return', 'connection'])],
            'segments.*.airline_id' => ['nullable', 'integer', 'min:1'],
            'segments.*.airline_code' => ['nullable', 'string', 'max:16'],
            'segments.*.airline' => ['nullable', 'string', 'max:160'],
            'segments.*.flight_number' => ['nullable', 'string', 'max:40'],
            'segments.*.from' => ['nullable', 'string', 'max:12'],
            'segments.*.to' => ['nullable', 'string', 'max:12'],
            'segments.*.departure_at' => ['nullable', 'date'],
            'segments.*.arrival_at' => ['nullable', 'date'],

            'tickets' => ['nullable', 'array', 'max:100'],
            'tickets.*.booking_passenger_id' => ['required', 'integer', 'min:1'],
            'tickets.*.ticket_number' => ['nullable', 'string', 'max:120'],
            'tickets.*.booking_class' => ['nullable', 'string', 'max:40'],
            'tickets.*.baggage' => ['nullable', 'string', 'max:80'],

            'fare_commercials' => ['nullable', 'array', 'max:3'],
            'fare_commercials.*.fare_type' => ['required', Rule::in(['ADULT', 'CHILD', 'INFANT'])],
            // ERP-11.3.108 compact one-row commercial contract.
            'fare_commercials.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'fare_commercials.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'fare_commercials.*.basic_rate' => ['nullable', 'numeric', 'min:0'],
            'fare_commercials.*.vendor_minus_type' => ['nullable', Rule::in(['FIXED', 'PERCENT'])],
            'fare_commercials.*.vendor_minus_value' => ['nullable', 'numeric', 'min:0'],
            'fare_commercials.*.customer_minus_type' => ['nullable', Rule::in(['FIXED', 'PERCENT'])],
            'fare_commercials.*.customer_minus_value' => ['nullable', 'numeric', 'min:0'],
            'fare_commercials.*.vendor_other_cost' => ['nullable', 'numeric', 'min:0'],
            // Legacy ERP-11.3.107 keys remain accepted for stale-browser/cache rollout safety.
            'fare_commercials.*.customer_total_sale_value' => ['nullable', 'numeric', 'min:0'],
            'fare_commercials.*.customer_base_fare' => ['nullable', 'numeric', 'min:0'],
            'fare_commercials.*.customer_discount_type' => ['nullable', Rule::in(['FIXED', 'PERCENT'])],
            'fare_commercials.*.customer_discount_value' => ['nullable', 'numeric', 'min:0'],
            'fare_commercials.*.supplier_other_cost' => ['nullable', 'numeric', 'min:0'],
            'fare_commercials.*.supplier_discount_type' => ['nullable', Rule::in(['FIXED', 'PERCENT'])],
            'fare_commercials.*.supplier_discount_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! Schema::hasTable('booking_services') || ! Schema::hasTable('air_ticket_details')) {
            throw ValidationException::withMessages([
                'air' => 'The native Air Ticket service store is not available on this ERP installation.',
            ]);
        }

        if (! Schema::hasTable('booking_itinerary_segments')) {
            throw ValidationException::withMessages([
                'segments' => 'The native flight itinerary store is not available on this ERP installation.',
            ]);
        }

        $passengers = $this->bookingPassengers($booking);
        $allowedPassengerIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $passengers
        );

        foreach ((array) ($data['tickets'] ?? []) as $index => $ticket) {
            if (! in_array((int) ($ticket['booking_passenger_id'] ?? 0), $allowedPassengerIds, true)) {
                throw ValidationException::withMessages([
                    "tickets.$index.booking_passenger_id" => 'The selected passenger does not belong to this booking.',
                ]);
            }
        }

        $common = (array) ($data['common'] ?? []);
        if (trim((string) ($common['pnr'] ?? '')) !== ''
            && (int) ($common['supplier_id'] ?? 0) <= 0
            && trim((string) ($common['supplier_name'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'common.supplier_id' => 'Select Vendor / Supplier before saving this PNR.',
            ]);
        }
        $fareCommercials = $this->normalizeFareCommercials((array) ($data['fare_commercials'] ?? []), $passengers);

        $nativeStage = 'Air service';
        try {
            $result = DB::transaction(function () use (
                $booking,
                $bookingRow,
                $passengers,
                $data,
                $common,
                $fareCommercials,
                &$nativeStage,
            ): array {
                $nativeStage = 'Air service';
                $service = $this->ensureAirService($booking, $bookingRow);
                $this->syncAirServiceContext((int) $service['id'], $common);

                $nativeStage = 'Flight Itinerary';
                $this->syncItinerary(
                    $booking,
                    (array) ($data['segments'] ?? []),
                    trim((string) ($common['pnr'] ?? '')),
                    strtoupper((string) ($common['ticket_status'] ?? 'BOOKED')),
                );

                $nativeStage = 'Passenger Tickets / PNR Commercials';
                $this->syncTickets(
                    (int) $service['id'],
                    $booking,
                    $bookingRow,
                    $passengers,
                    (array) ($data['tickets'] ?? []),
                    $common,
                    $fareCommercials,
                );

                $freshTickets = $this->ticketRows((int) $service['id'], $passengers);

                return [
                    'service' => $service,
                    'tickets' => $freshTickets,
                    'summary' => $this->summary($freshTickets, (int) $service['id']),
                    'fare_commercials' => $this->fareCommercialsSnapshot($freshTickets),
                ];
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            report($e);

            throw ValidationException::withMessages([
                'air' => $this->nativeQueryFailureMessage($e, $nativeStage),
            ]);
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'air' => 'Tickets / Flight Data could not be saved while writing '.$nativeStage.'. Please check the server log for the underlying native-store error.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Tickets / Flight Data saved.',
            'itinerary' => $this->itineraryRows($booking),
            'tickets' => $result['tickets'],
            'fare_commercials' => $result['fare_commercials'],
            'summary' => $result['summary'],
        ]);
    }

    private function assertBooking(int $booking): object
    {
        abort_unless(Schema::hasTable('bookings'), 404);
        $row = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($row, 404);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function bookingPassengers(int $booking): array
    {
        foreach (['booking_passengers', 'booking_travellers', 'booking_travelers'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            if (! in_array('booking_id', $columns, true) || ! in_array('id', $columns, true)) {
                continue;
            }

            $rows = DB::table($table)
                ->where('booking_id', $booking)
                ->orderBy($this->firstColumn($columns, ['passenger_index', 'sort_order', 'sequence', 'id']) ?? 'id')
                ->get();

            return $rows->map(function (object $row) use ($columns, $table): array {
                $data = (array) $row;
                $name = $this->stringFrom($data, $columns, ['name', 'passenger_name', 'full_name']);
                if ($name === '') {
                    $name = trim(implode(' ', array_filter([
                        $this->stringFrom($data, $columns, ['title', 'salutation']),
                        $this->stringFrom($data, $columns, ['first_name', 'given_name']),
                        $this->stringFrom($data, $columns, ['last_name', 'surname', 'family_name']),
                    ])));
                }

                return [
                    'id' => (int) ($data['id'] ?? 0),
                    'master_id' => (int) ($this->valueFrom($data, $columns, ['passenger_id', 'master_passenger_id', 'traveller_id', 'traveler_id']) ?? 0),
                    'source_table' => $table,
                    'name' => $name !== '' ? $name : 'Passenger '.(int) ($data['id'] ?? 0),
                    // ERP-11.3.137: effective Fare As is the first NON-EMPTY
                    // booking-passenger fare field. Long-lived schemas can carry
                    // an empty fare_as beside a populated fare_type (or vice versa).
                    'fare_type' => $this->normalizeFareType(
                        $this->firstNonEmpty($data, ['fare_as', 'fare_type', 'passenger_type', 'pax_type', 'age_type'])
                    ),
                    'status' => strtoupper($this->stringFrom($data, $columns, ['status']) ?: 'ACTIVE'),
                ];
            })->values()->all();
        }

        return [];
    }

    /** @return list<array<string,mixed>> */
    private function itineraryRows(int $booking): array
    {
        if (! Schema::hasTable('booking_itinerary_segments')) {
            return [];
        }

        $columns = Schema::getColumnListing('booking_itinerary_segments');
        if (! in_array('booking_id', $columns, true)) {
            return [];
        }

        $sort = $this->firstColumn($columns, ['sort_order', 'sequence', 'sequence_no', 'id']) ?? 'id';

        return DB::table('booking_itinerary_segments')
            ->where('booking_id', $booking)
            ->orderBy($sort)
            ->get()
            ->map(function (object $row) use ($columns): array {
                $data = (array) $row;
                return [
                    'id' => (int) ($data['id'] ?? 0),
                    'segment_type' => strtolower($this->stringFrom($data, $columns, ['segment_type', 'type']) ?: 'outbound'),
                    'airline_id' => (int) ($this->valueFrom($data, $columns, ['airline_id', 'carrier_id']) ?? 0),
                    'airline_code' => strtoupper($this->stringFrom($data, $columns, ['airline_code', 'carrier_code'])),
                    'airline' => $this->stringFrom($data, $columns, ['airline_name', 'airline', 'carrier_name', 'carrier_code']),
                    'flight_number' => $this->stringFrom($data, $columns, ['flight_number', 'flight_no']),
                    'from' => strtoupper($this->stringFrom($data, $columns, ['from_code', 'origin_code', 'from', 'origin'])),
                    'to' => strtoupper($this->stringFrom($data, $columns, ['to_code', 'destination_code', 'to', 'destination'])),
                    'departure_at' => $this->dateTimeLocal($this->valueFrom($data, $columns, ['departure_at', 'departure_datetime', 'depart_at'])),
                    'arrival_at' => $this->dateTimeLocal($this->valueFrom($data, $columns, ['arrival_at', 'arrival_datetime', 'arrive_at'])),
                ];
            })
            ->values()
            ->all();
    }

    private function syncItinerary(int $booking, array $segments, string $pnr, string $ticketStatus): void
    {
        $table = 'booking_itinerary_segments';
        $columns = Schema::getColumnListing($table);
        $derivedStatus = $this->itineraryStatusFromTicketStatus($ticketStatus);

        DB::table($table)->where('booking_id', $booking)->delete();

        foreach ($segments as $index => $segment) {
            $from = strtoupper(trim((string) ($segment['from'] ?? '')));
            $to = strtoupper(trim((string) ($segment['to'] ?? '')));
            $airline = trim((string) ($segment['airline'] ?? ''));
            $airlineCode = strtoupper(trim((string) ($segment['airline_code'] ?? '')));
            $airlineId = (int) ($segment['airline_id'] ?? 0);
            $flight = strtoupper(trim((string) ($segment['flight_number'] ?? '')));
            $departure = $segment['departure_at'] ?? null;

            if ($from === '' && $to === '' && $airline === '' && $flight === '' && ! $departure) {
                continue;
            }

            if ($from === '' || $to === '' || ! $departure) {
                throw ValidationException::withMessages([
                    "segments.$index" => 'Each flight segment needs From, To and Departure.',
                ]);
            }

            if ($airline === '' && $airlineCode === '' && $airlineId <= 0) {
                throw ValidationException::withMessages([
                    "segments.$index.airline" => 'Select an airline for each flight segment.',
                ]);
            }

            $insert = [];
            $this->put($insert, $columns, ['booking_id'], $booking);
            $this->put($insert, $columns, ['segment_type', 'type'], $this->segmentType((string) ($segment['segment_type'] ?? 'outbound')));
            $this->put($insert, $columns, ['from_code', 'origin_code', 'from', 'origin'], $from);
            $this->put($insert, $columns, ['to_code', 'destination_code', 'to', 'destination'], $to);
            if ($airlineId > 0) $this->put($insert, $columns, ['airline_id', 'carrier_id'], $airlineId);
            $this->put($insert, $columns, ['airline_code', 'carrier_code'], $airlineCode ?: null);
            $this->put($insert, $columns, ['airline_name', 'airline', 'carrier_name'], $airline ?: ($airlineCode ?: null));
            $this->put($insert, $columns, ['flight_number', 'flight_no'], $flight ?: null);
            $this->put($insert, $columns, ['departure_at', 'departure_datetime', 'depart_at'], $departure);
            $this->put($insert, $columns, ['arrival_at', 'arrival_datetime', 'arrive_at'], $segment['arrival_at'] ?? null);
            $this->put($insert, $columns, ['pnr', 'record_locator'], $pnr ?: null);
            $this->put($insert, $columns, ['status'], $derivedStatus);
            $this->put($insert, $columns, ['sort_order', 'sequence', 'sequence_no'], ($index + 1) * 10);
            if (in_array('created_at', $columns, true)) $insert['created_at'] = now();
            if (in_array('updated_at', $columns, true)) $insert['updated_at'] = now();

            $insert = $this->fillRequiredByPrototype($table, $insert, $this->prototypeFor($table), [
                'booking_id' => $booking,
                'status' => $derivedStatus,
                'pnr' => $pnr ?: null,
                'sort_order' => ($index + 1) * 10,
            ]);

            DB::table($table)->insert($insert);
        }
    }

    /** @return array<string,mixed>|null */
    private function findAirService(int $booking): ?array
    {
        if (! Schema::hasTable('booking_services')) {
            return null;
        }

        $columns = Schema::getColumnListing('booking_services');
        if (! in_array('booking_id', $columns, true) || ! in_array('id', $columns, true)) {
            return null;
        }

        $rows = DB::table('booking_services')->where('booking_id', $booking)->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $master = $this->resolveAirProductService();
        $masterId = (int) ($master['id'] ?? 0);
        $best = null;
        $bestScore = -1;

        foreach ($rows as $row) {
            $data = (array) $row;
            $score = 0;
            if ($masterId > 0 && (int) ($data['product_service_id'] ?? 0) === $masterId) {
                $score += 10000;
            }
            $haystack = strtolower(implode(' ', array_map('strval', array_intersect_key($data, array_flip([
                'service_name', 'name', 'title', 'description', 'details', 'service_type', 'product_type',
            ])))));
            if (str_contains($haystack, 'air ticket')) $score += 5000;
            if (str_contains($haystack, 'ticket')) $score += 2500;
            if (str_contains($haystack, 'flight')) $score += 1800;
            if (str_contains($haystack, 'air')) $score += 800;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $data;
            }
        }

        return $bestScore > 0 && $best
            ? ['id' => (int) $best['id'], 'row' => $best]
            : null;
    }

    /** @return array{id:int,row:array<string,mixed>} */
    private function ensureAirService(int $booking, object $bookingRow): array
    {
        $existing = $this->findAirService($booking);
        if ($existing) {
            return $existing;
        }

        $master = $this->resolveAirProductService();
        if (! $master) {
            throw ValidationException::withMessages([
                'air' => 'The Air Ticket Product Service master could not be resolved. Confirm that Air Ticket exists in Product/Service Master.',
            ]);
        }

        $table = 'booking_services';
        $columns = Schema::getColumnListing($table);
        $prototype = DB::table($table)
            ->where('product_service_id', (int) $master['id'])
            ->orderByDesc('id')
            ->first();
        $row = $prototype ? (array) $prototype : [];

        unset($row['id']);
        $this->clearUniqueReferenceFields($table, $row);

        $row['booking_id'] = $booking;
        if (in_array('product_service_id', $columns, true)) {
            $row['product_service_id'] = (int) $master['id'];
        }

        $bookingData = (array) $bookingRow;
        foreach (['company_id', 'branch_id', 'customer_id', 'agent_id', 'salesperson_id', 'currency_id', 'tenant_id', 'office_id'] as $field) {
            if (in_array($field, $columns, true) && array_key_exists($field, $bookingData)) {
                $row[$field] = $bookingData[$field];
            }
        }

        $masterRow = (array) ($master['row'] ?? []);
        $name = $this->firstNonEmpty($masterRow, ['name', 'service_name', 'title', 'label', 'description']) ?: 'Air Ticket';
        $code = $this->firstNonEmpty($masterRow, ['code', 'service_code', 'product_code', 'slug']);
        $this->put($row, $columns, ['service_name', 'name', 'title'], $name);
        $this->put($row, $columns, ['service_code', 'product_code', 'code'], $code ?: null);
        $this->putNativeEnum($row, $table, $columns, ['passenger_link_mode_snapshot', 'passenger_link_mode'], 'MULTIPLE', ['multiple']);
        $this->put($row, $columns, ['quantity', 'qty'], 1);
        $this->putNativeEnum($row, $table, $columns, ['status'], 'active', ['ACTIVE']);

        foreach (['is_active' => 1, 'active' => 1] as $field => $value) {
            if (in_array($field, $columns, true)) $row[$field] = $value;
        }
        foreach (['created_by', 'created_by_id', 'updated_by', 'updated_by_id', 'user_id'] as $field) {
            if (in_array($field, $columns, true) && Auth::id()) $row[$field] = Auth::id();
        }
        if (in_array('created_at', $columns, true)) $row['created_at'] = now();
        if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();

        $row = array_intersect_key($row, array_flip($columns));
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
        ]);

        $id = (int) DB::table($table)->insertGetId($row);

        return ['id' => $id, 'row' => $row + ['id' => $id]];
    }

    /** @return array<string,mixed>|null */
    private function resolveAirProductService(): ?array
    {
        if (! Schema::hasTable('booking_services')) {
            return null;
        }

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

        foreach (['product_services', 'product_service_master', 'product_service_masters', 'travel_product_services', 'service_products'] as $table) {
            if (Schema::hasTable($table)) $tables[] = $table;
        }

        $tables = array_values(array_unique($tables));
        $best = null;
        $bestScore = 0;

        foreach ($tables as $table) {
            try {
                $columns = Schema::getColumnListing($table);
                $idColumn = $this->firstColumn($columns, ['id', 'product_service_id']);
                if (! $idColumn) continue;

                foreach (DB::table($table)->limit(2000)->get() as $rowObject) {
                    $row = (array) $rowObject;
                    $id = (int) ($row[$idColumn] ?? 0);
                    if ($id <= 0) continue;
                    $text = strtolower(implode(' ', array_map('strval', $row)));
                    $score = 0;
                    if (str_contains($text, 'air ticket')) $score += 10000;
                    if (str_contains($text, 'air-ticket')) $score += 9000;
                    if (str_contains($text, 'ticketing')) $score += 5000;
                    if (str_contains($text, 'flight ticket')) $score += 7000;
                    if (str_contains($text, 'ticket')) $score += 2600;
                    if (str_contains($text, 'flight')) $score += 1800;
                    if (str_contains($text, 'air')) $score += 700;
                    if (str_contains($text, 'hotel')) $score -= 5000;
                    if (str_contains($text, 'visa')) $score -= 5000;
                    if (str_contains($text, 'umrah')) $score -= 3500;
                    if (str_contains($text, 'transport')) $score -= 5000;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = ['id' => $id, 'table' => $table, 'row' => $row];
                    }
                }
            } catch (Throwable) {
            }
        }

        return $bestScore >= 2000 ? $best : null;
    }

    /** @return list<array<string,mixed>> */
    private function ticketRows(int $serviceId, array $passengers): array
    {
        if ($serviceId <= 0 || ! Schema::hasTable('air_ticket_details')) return [];

        $table = 'air_ticket_details';
        // ERP-11.3.122: read back the same physical schema contract used by writes.
        // This prevents legacy supplier commercial columns from disappearing after save.
        $columns = $this->physicalColumnListing($table);
        if (! in_array('booking_service_id', $columns, true)) return [];

        $passengerColumn = $this->firstColumn($columns, ['booking_passenger_id', 'passenger_id', 'traveller_id', 'traveler_id']);
        $foreignTable = $passengerColumn ? $this->foreignTable($table, $passengerColumn) : null;
        $passengerMap = [];
        foreach ($passengers as $passenger) {
            $value = $this->ticketPassengerValue($passenger, $foreignTable);
            if ($value > 0) $passengerMap[$value] = $passenger;
        }

        $rows = DB::table($table)->where('booking_service_id', $serviceId);
        if (in_array('deleted_at', $columns, true)) $rows->whereNull('deleted_at');

        return $rows->orderBy(in_array('id', $columns, true) ? 'id' : 'booking_service_id')
            ->get()
            ->map(function (object $row) use ($columns, $passengerColumn, $passengerMap): array {
                $data = (array) $row;
                $passengerValue = $passengerColumn ? (int) ($data[$passengerColumn] ?? 0) : 0;
                $passenger = $passengerMap[$passengerValue] ?? null;
                $customer = $this->ticketCustomerTotal($data, $columns);
                $supplier = $this->ticketSupplierTotal($data, $columns);
                $commercialMeta = $this->commercialMetaFromRow($data, $columns);
                $nativeSupplierId = $this->firstPositiveInt($data, ['supplier_id', 'vendor_id']);
                $nativeSupplierName = $this->firstNonEmpty($data, ['supplier_name', 'vendor_name']);

                return [
                    'id' => (int) ($data['id'] ?? 0),
                    'booking_passenger_id' => (int) ($passenger['id'] ?? 0),
                    'passenger_name' => (string) ($passenger['name'] ?? $this->stringFrom($data, $columns, ['passenger_name', 'traveller_name', 'full_name', 'name'])),
                    'fare_type' => (string) ($passenger['fare_type'] ?? $this->normalizeFareType($this->stringFrom($data, $columns, ['fare_type', 'passenger_type', 'pax_type', 'age_type']))),
                    // ERP-11.3.125: ticket identifier aliases can coexist on long-lived
                    // native schemas. Read the first NON-EMPTY alias, not merely the first
                    // installed column, so an empty legacy sibling cannot hide the saved ticket.
                    'ticket_number' => $this->firstNonEmpty($data, ['ticket_number', 'ticket_no', 'e_ticket_number', 'eticket_number', 'document_number', 'document_no']),
                    'booking_class' => $this->stringFrom($data, $columns, ['booking_class', 'cabin_class', 'fare_class', 'class']),
                    'baggage' => $this->stringFrom($data, $columns, ['baggage', 'baggage_allowance']),
                    'status' => strtoupper($this->stringFrom($data, $columns, ['ticket_status', 'status']) ?: 'BOOKED'),
                    'pnr' => $this->stringFrom($data, $columns, ['pnr', 'gds_pnr', 'record_locator']),
                    'airline_pnr' => $this->stringFrom($data, $columns, ['airline_pnr', 'supplier_pnr']),
                    'booking_source' => $this->stringFrom($data, $columns, ['booking_source', 'gds_source', 'source']),
                    'issue_date' => $this->dateOnly($this->valueFrom($data, $columns, ['issue_date', 'ticket_issue_date', 'issued_at'])),
                    'supplier_id' => $nativeSupplierId > 0 ? $nativeSupplierId : (int) ($commercialMeta['supplier_id'] ?? $commercialMeta['vendor_id'] ?? 0),
                    'supplier_name' => $nativeSupplierName !== '' ? $nativeSupplierName : trim((string) ($commercialMeta['supplier_name'] ?? $commercialMeta['vendor_name'] ?? '')),
                    'customer_base_fare' => $this->money($this->valueFrom($data, $columns, ['base_fare', 'basic_fare'])),
                    'customer_taxes' => $this->money($this->valueFrom($data, $columns, ['airline_taxes', 'taxes', 'tax_amount'])),
                    'customer_total_sale_value' => $this->money($this->valueFrom($data, $columns, ['gross_sale', 'gross_selling_total', 'total_sale_value'])),
                    'customer_other_charges' => $this->money($this->valueFrom($data, $columns, ['customer_service_fee', 'service_markup', 'service_charge', 'markup'])),
                    'customer_discount' => $this->money($this->valueFrom($data, $columns, ['customer_discount_amount', 'discount_amount', 'discount'])),
                    'customer_discount_type' => strtoupper($this->stringFrom($data, $columns, ['customer_discount_type', 'discount_type'])),
                    'customer_total' => $customer,
                    'supplier_base_cost' => $this->money($this->valueFrom($data, $columns, ['supplier_base_fare'])),
                    'supplier_taxes' => $this->money($this->valueFrom($data, $columns, ['supplier_taxes'])),
                    'supplier_cost_price' => $this->money($this->valueFrom($data, $columns, ['supplier_gross_cost', 'gross_supplier_cost', 'supplier_cost_price', 'vendor_cost_price'])),
                    'supplier_other_cost' => $this->money($this->valueFrom($data, $columns, ['supplier_charges', 'supplier_charge', 'supplier_markup', 'supplier_other_charges', 'supplier_other_charge', 'supplier_other_cost', 'vendor_other_charges', 'vendor_other_charge', 'vendor_other_cost'])),
                    'supplier_discount' => $this->money($this->valueFrom($data, $columns, ['supplier_discount_amount', 'vendor_discount_amount', 'supplier_discount', 'purchase_discount_amount'])),
                    'supplier_discount_type' => strtoupper($this->stringFrom($data, $columns, ['supplier_discount_type', 'vendor_discount_type', 'purchase_discount_type'])),
                    'supplier_total' => $supplier,
                    'commercial_meta' => $commercialMeta,
                ];
            })
            ->values()
            ->all();
    }

    private function syncTickets(
        int $serviceId,
        int $booking,
        object $bookingRow,
        array $passengers,
        array $ticketPayloads,
        array $common,
        array $fareCommercials,
    ): void {
        $table = 'air_ticket_details';
        // ERP-11.3.122: derive the write contract from the physical database, not
        // Laravel's schema cache/DBAL listing. Production exposed required legacy
        // fields (supplier_incentive / supplier_other_charges after supplier_commission) that MySQL enforced
        // but Schema::getColumnListing() did not consistently surface.
        $columns = $this->physicalColumnListing($table);
        if (!$columns) {
            throw ValidationException::withMessages([
                'air' => 'Native Air schema inspection is unavailable. No Air data was written.',
            ]);
        }
        $passengerColumn = $this->firstColumn($columns, ['booking_passenger_id', 'passenger_id', 'traveller_id', 'traveler_id']);
        if (! $passengerColumn) {
            throw ValidationException::withMessages([
                'tickets' => 'The native Air Ticket detail table has no passenger link field.',
            ]);
        }

        $foreignTable = $this->foreignTable($table, $passengerColumn);
        $passengersById = [];
        foreach ($passengers as $passenger) $passengersById[(int) $passenger['id']] = $passenger;

        $prototype = $this->airTicketPrototype();
        $safePrototype = $prototype;
        $this->scrubTicketPrototype($safePrototype);
        $bookingData = (array) $bookingRow;
        $ticketStatus = strtoupper((string) ($common['ticket_status'] ?? 'BOOKED'));
        $fareCounts = $this->fareTypeCounts($passengers);
        $fareSeen = ['ADULT' => 0, 'CHILD' => 0, 'INFANT' => 0];

        foreach ($ticketPayloads as $index => $payload) {
            $bookingPassengerId = (int) ($payload['booking_passenger_id'] ?? 0);
            $passenger = $passengersById[$bookingPassengerId] ?? null;
            if (! $passenger) continue;

            $fareType = $this->normalizeFareType((string) ($passenger['fare_type'] ?? 'ADULT'));
            $commercial = $fareCommercials[$fareType] ?? $this->emptyFareCommercial($fareType);
            $customerTotal = (float) ($commercial['customer_total'] ?? 0);

            // ERP-11.3.114: V O Cost is entered once for the whole fare-type row,
            // not once per passenger. Allocate that one row-level amount across
            // the native passenger ticket rows so downstream native summations
            // reconcile to: (Cost - Vendor Minus) * Pax + V O Cost.
            $fareCount = max(1, (int) ($fareCounts[$fareType] ?? 0));
            $fareOrdinal = (int) ($fareSeen[$fareType] ?? 0);
            $fareSeen[$fareType] = $fareOrdinal + 1;
            $vendorOtherCostTotal = $this->money($commercial['vendor_other_cost'] ?? ($commercial['supplier_other_cost'] ?? 0));
            $standardOtherAllocation = round($vendorOtherCostTotal / $fareCount, 2);
            $vendorOtherAllocation = $fareOrdinal >= ($fareCount - 1)
                ? round($vendorOtherCostTotal - ($standardOtherAllocation * max(0, $fareCount - 1)), 2)
                : $standardOtherAllocation;
            $vendorBaseNet = max(0.0, round(
                $this->money($commercial['cost_price'] ?? 0)
                - $this->money($commercial['vendor_minus_amount'] ?? ($commercial['supplier_discount_amount'] ?? 0)),
                2
            ));
            $supplierTotal = max(0.0, round($vendorBaseNet + $vendorOtherAllocation, 2));

            $passengerValue = $this->ticketPassengerValue($passenger, $foreignTable);
            if ($passengerValue <= 0) {
                throw ValidationException::withMessages([
                    "tickets.$index.booking_passenger_id" => 'The native Air Ticket passenger link could not be resolved for this passenger.',
                ]);
            }

            $existing = DB::table($table)
                ->where('booking_service_id', $serviceId)
                ->where($passengerColumn, $passengerValue)
                ->orderByDesc(in_array('id', $columns, true) ? 'id' : 'booking_service_id')
                ->first();

            $ticketNumber = trim((string) ($payload['ticket_number'] ?? ''));
            $hasCommercial = $customerTotal > 0.0 || $supplierTotal > 0.0;
            if (! $existing && $ticketNumber === '' && ! $hasCommercial) {
                continue;
            }

            $row = $existing ? (array) $existing : $safePrototype;
            $id = (int) ($row['id'] ?? 0);
            unset($row['id']);

            $row['booking_service_id'] = $serviceId;
            $row[$passengerColumn] = $passengerValue;

            foreach (['company_id', 'branch_id', 'customer_id', 'agent_id', 'salesperson_id', 'currency_id', 'tenant_id', 'office_id'] as $field) {
                if (in_array($field, $columns, true) && array_key_exists($field, $bookingData)) {
                    $row[$field] = $bookingData[$field];
                }
            }

            // ERP-11.3.118 — native Air schema contract saturation.
            // Keep operational/identity fields on their existing first-match
            // native mapping because sibling columns such as `status` can have
            // different domain semantics on some installations. Saturation is
            // deliberately limited to TRUE monetary compatibility aliases below.
            $this->putAllowEmpty($row, $columns, ['ticket_number', 'ticket_no', 'e_ticket_number', 'eticket_number', 'document_number', 'document_no'], $ticketNumber);
            $this->putAllowEmpty($row, $columns, ['pnr', 'gds_pnr', 'record_locator'], trim((string) ($common['pnr'] ?? '')));
            $this->putAllowEmpty($row, $columns, ['airline_pnr', 'supplier_pnr'], trim((string) ($common['airline_pnr'] ?? '')));
            $this->putAllowEmpty($row, $columns, ['booking_source', 'gds_source', 'source'], trim((string) ($common['booking_source'] ?? '')));
            $this->putAllowEmpty($row, $columns, ['issue_date', 'ticket_issue_date', 'issued_at'], $common['issue_date'] ?? null);
            $this->putNativeEnum($row, $table, $columns, ['ticket_status', 'status'], $ticketStatus, $this->ticketStatusAliases($ticketStatus));
            $this->putNativeEnum($row, $table, $columns, ['fare_type', 'passenger_type', 'pax_type', 'age_type', 'ticket_type'], $fareType, $this->fareTypeAliases($fareType));
            $this->putAllowEmpty($row, $columns, ['booking_class', 'cabin_class', 'fare_class', 'class'], trim((string) ($payload['booking_class'] ?? '')));
            $this->putAllowEmpty($row, $columns, ['baggage', 'baggage_allowance'], trim((string) ($payload['baggage'] ?? '')));
            $this->putAllowEmpty($row, $columns, ['passenger_name', 'traveller_name', 'traveler_name', 'full_name', 'name'], $passenger['name']);

            $supplierId = (int) ($common['supplier_id'] ?? 0);
            // Supplier/vendor IDs are NOT saturated because installations can
            // keep both columns with different foreign-key masters. Use the
            // authoritative first installed relation only; names are safe aliases.
            if ($supplierId > 0) $this->put($row, $columns, ['supplier_id', 'vendor_id'], $supplierId);
            $this->putAllAllowEmpty($row, $columns, ['supplier_name', 'vendor_name'], trim((string) ($common['supplier_name'] ?? '')));

            $basicRateValue = $this->money($commercial['customer_base_fare'] ?? ($commercial['basic_rate'] ?? 0));
            $taxValue = $this->money($commercial['customer_taxes'] ?? ($commercial['taxes'] ?? 0));
            $customerDiscountAmount = $this->money($commercial['customer_discount_amount'] ?? 0);
            $salePriceValue = $this->money($commercial['customer_total_sale_value'] ?? ($commercial['sale_price'] ?? 0));
            $costPriceValue = $this->money($commercial['cost_price'] ?? 0);
            $supplierDiscountAmount = $this->money($commercial['supplier_discount_amount'] ?? 0);

            $this->putAllAllowEmpty($row, $columns, ['base_fare', 'basic_fare'], $basicRateValue);
            $this->putAllAllowEmpty($row, $columns, ['airline_taxes', 'taxes', 'tax_amount'], $taxValue);

            // Customer Other Charges is retired. Every legacy markup/service
            // alias must still receive 0 so native NOT NULL columns are satisfied.
            $this->putAllAllowEmpty($row, $columns, [
                'customer_service_fee', 'service_markup', 'service_charge',
                'markup', 'customer_markup', 'customer_service_charge',
            ], 0.0);

            // `discount` is a confirmed live required legacy sibling. It is an
            // amount field in the native ERP commercial readers, so store the
            // calculated Customer Minus amount in every amount alias.
            $this->putAllAllowEmpty($row, $columns, [
                'customer_discount_amount', 'discount_amount', 'discount',
            ], $customerDiscountAmount);
            $this->putNativeEnum($row, $table, $columns, ['customer_discount_type', 'discount_type'], (string) ($commercial['customer_discount_type'] ?? 'FIXED'), ['fixed', 'percent', 'percentage']);
            $this->putAllAllowEmpty($row, $columns, ['gross_sale', 'gross_selling_total', 'total_sale_value'], $salePriceValue);
            $this->putAllAllowEmpty($row, $columns, ['selling_total', 'customer_sale', 'customer_sell', 'customer_sale_amount', 'sale_amount', 'selling_price', 'sale_price', 'customer_price', 'customer_total', 'receivable_amount'], round($customerTotal, 2));

            // Airline Basic Rate and Taxes stay shared; Cost Price is the vendor gross.
            $this->putAllAllowEmpty($row, $columns, ['supplier_base_fare'], $basicRateValue);
            $this->putAllAllowEmpty($row, $columns, ['supplier_taxes'], $taxValue);
            $this->putAllAllowEmpty($row, $columns, ['supplier_gross_cost', 'gross_supplier_cost', 'supplier_cost_price', 'vendor_cost_price'], $costPriceValue);
            $this->putAllAllowEmpty($row, $columns, ['supplier_charges', 'supplier_charge', 'supplier_markup', 'supplier_other_charges', 'supplier_other_charge', 'supplier_other_cost', 'vendor_other_charges', 'vendor_other_charge', 'vendor_other_cost'], $vendorOtherAllocation);
            $this->putAllAllowEmpty($row, $columns, ['supplier_discount_amount', 'vendor_discount_amount', 'supplier_discount', 'purchase_discount_amount'], $supplierDiscountAmount);
            $this->putNativeEnum($row, $table, $columns, ['supplier_discount_type', 'vendor_discount_type', 'purchase_discount_type'], (string) ($commercial['supplier_discount_type'] ?? 'FIXED'), ['fixed', 'percent', 'percentage']);

            // ERP-11.3.119 — the production Air Ticket table also carries legacy
            // supplier/vendor commission siblings. This GENERAL Air workspace has
            // no separate supplier-commission input: Vendor Minus is already the
            // approved supplier reduction. Therefore native commission compatibility
            // fields must be deterministically zero rather than left absent/NULL.
            $this->putAllAllowEmpty($row, $columns, [
                'supplier_commission', 'supplier_commission_amount',
                'vendor_commission', 'vendor_commission_amount',
            ], 0.0);
            $this->putAllAllowEmpty($row, $columns, [
                'supplier_commission_rate', 'supplier_commission_percent', 'supplier_commission_percentage',
                'vendor_commission_rate', 'vendor_commission_percent', 'vendor_commission_percentage',
            ], 0.0);
            $this->putNativeEnum($row, $table, $columns, [
                'supplier_commission_type', 'vendor_commission_type',
            ], 'FIXED', ['fixed', 'amount', 'flat']);

            // Incentive fields are another retired/native supplier-commercial
            // compatibility family on some installs. There is no independent
            // incentive input in this workspace, so the deterministic value is 0.
            // This is not the primary fix: physical schema saturation below now
            // handles every required numeric compatibility column generically.
            $this->putAllAllowEmpty($row, $columns, [
                'supplier_incentive', 'supplier_incentive_amount',
                'vendor_incentive', 'vendor_incentive_amount',
            ], 0.0);
            $this->putAllAllowEmpty($row, $columns, [
                'supplier_incentive_rate', 'supplier_incentive_percent', 'supplier_incentive_percentage',
                'vendor_incentive_rate', 'vendor_incentive_percent', 'vendor_incentive_percentage',
            ], 0.0);

            // ERP-11.3.122 — status-family compatibility must be saturated
            // proactively, not only when metadata says a field is required.
            // Production exposed supplier_cost_status after the numeric legacy
            // commercial families. This field is a domain/status value, so the
            // numeric compatibility resolver cannot safely populate it. Fill all
            // installed native *_status siblings with a safe enum-aware value
            // before preflight/INSERT. Existing ticket rows keep their current
            // status because non-empty values are never overwritten.
            $this->saturateNativeStatusCompatibility($row, $table, $columns, $prototype);

            $this->putAllAllowEmpty($row, $columns, ['net_supplier_cost', 'supplier_cost', 'supplier_cost_amount', 'net_cost', 'purchase_cost', 'purchase_price', 'supplier_total', 'cost_amount'], round($supplierTotal, 2));
            $ticketCommercial = $commercial;
            $ticketCommercial['vendor_other_cost_allocation'] = $vendorOtherAllocation;
            $ticketCommercial['supplier_total'] = round($supplierTotal, 2);
            $ticketCommercial['vendor_net'] = round($supplierTotal, 2);
            $ticketCommercial['supplier_id'] = (int) ($common['supplier_id'] ?? 0);
            $ticketCommercial['vendor_id'] = (int) ($common['supplier_id'] ?? 0);
            $ticketCommercial['supplier_name'] = trim((string) ($common['supplier_name'] ?? ''));
            $ticketCommercial['vendor_name'] = trim((string) ($common['supplier_name'] ?? ''));
            $this->writeCommercialMeta($row, $columns, $ticketCommercial);

            foreach (['created_by', 'created_by_id', 'updated_by', 'updated_by_id', 'user_id'] as $field) {
                if (in_array($field, $columns, true) && Auth::id()) $row[$field] = Auth::id();
            }
            if (!$existing && in_array('created_at', $columns, true)) $row['created_at'] = now();
            if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();
            if (in_array('deleted_at', $columns, true)) $row['deleted_at'] = null;

            $row = array_intersect_key($row, array_flip($columns));
            $row = $this->fillRequiredByPrototype($table, $row, $safePrototype, [
                'booking_service_id' => $serviceId,
                $passengerColumn => $passengerValue,
                'passenger_name' => $passenger['name'],
                'fare_type' => $fareType,
                'ticket_status' => $ticketStatus,
                'status' => $ticketStatus,
                'ticket_number' => $ticketNumber,
                'ticket_no' => $ticketNumber,
                'supplier_id' => (int) ($common['supplier_id'] ?? 0) ?: null,
                'vendor_id' => (int) ($common['supplier_id'] ?? 0) ?: null,
                'selling_total' => round($customerTotal, 2),
                'net_supplier_cost' => round($supplierTotal, 2),
                'base_fare' => $basicRateValue,
                'basic_fare' => $basicRateValue,
                'airline_taxes' => $taxValue,
                'taxes' => $taxValue,
                'tax_amount' => $taxValue,
                'customer_service_fee' => 0.0,
                'markup' => 0.0,
                'discount' => $customerDiscountAmount,
                'discount_amount' => $customerDiscountAmount,
                'customer_discount_amount' => $customerDiscountAmount,
                'supplier_base_fare' => $basicRateValue,
                'supplier_taxes' => $taxValue,
                'supplier_charges' => $vendorOtherAllocation,
                'supplier_charge' => $vendorOtherAllocation,
                'supplier_markup' => $vendorOtherAllocation,
                'supplier_other_charges' => $vendorOtherAllocation,
                'supplier_other_charge' => $vendorOtherAllocation,
                'supplier_other_cost' => $vendorOtherAllocation,
                'vendor_other_charges' => $vendorOtherAllocation,
                'vendor_other_charge' => $vendorOtherAllocation,
                'vendor_other_cost' => $vendorOtherAllocation,
                'supplier_discount_amount' => $supplierDiscountAmount,
                'supplier_commission' => 0.0,
                'supplier_commission_amount' => 0.0,
                'supplier_commission_rate' => 0.0,
                'supplier_commission_percent' => 0.0,
                'supplier_commission_percentage' => 0.0,
                'supplier_commission_type' => 'FIXED',
                'vendor_commission' => 0.0,
                'vendor_commission_amount' => 0.0,
                'vendor_commission_rate' => 0.0,
                'vendor_commission_percent' => 0.0,
                'vendor_commission_percentage' => 0.0,
                'vendor_commission_type' => 'FIXED',
                'supplier_incentive' => 0.0,
                'supplier_incentive_amount' => 0.0,
                'supplier_incentive_rate' => 0.0,
                'supplier_incentive_percent' => 0.0,
                'supplier_incentive_percentage' => 0.0,
                'vendor_incentive' => 0.0,
                'vendor_incentive_amount' => 0.0,
                'vendor_incentive_rate' => 0.0,
                'vendor_incentive_percent' => 0.0,
                'vendor_incentive_percentage' => 0.0,
            ]);

            $this->assertRequiredNativeContract($table, $row);

            if ($id > 0) {
                DB::table($table)->where('id', $id)->update($row);
            } else {
                DB::table($table)->insert($row);
            }
        }
    }

    /** @return array<string,mixed> */
    private function commonSnapshot(array $tickets, array $serviceRow = []): array
    {
        $first = $tickets[0] ?? [];
        $serviceColumns = array_keys($serviceRow);
        $pnr = trim((string) ($first['pnr'] ?? ''));
        $airlinePnr = trim((string) ($first['airline_pnr'] ?? ''));
        $source = trim((string) ($first['booking_source'] ?? ''));
        $status = strtoupper(trim((string) ($first['status'] ?? '')));
        $issueDate = trim((string) ($first['issue_date'] ?? ''));
        $supplierId = (int) ($first['supplier_id'] ?? 0);
        $supplierName = trim((string) ($first['supplier_name'] ?? ''));
        $serviceVendorMeta = $this->vendorMetaFromRow($serviceRow, $serviceColumns);
        $serviceSupplierId = $this->firstPositiveInt($serviceRow, ['supplier_id', 'vendor_id']);
        $serviceSupplierName = $this->firstNonEmpty($serviceRow, ['supplier_name', 'vendor_name']);

        return [
            'pnr' => $pnr !== '' ? $pnr : $this->stringFrom($serviceRow, $serviceColumns, ['pnr', 'gds_pnr', 'record_locator']),
            'airline_pnr' => $airlinePnr !== '' ? $airlinePnr : $this->stringFrom($serviceRow, $serviceColumns, ['airline_pnr', 'supplier_pnr']),
            'booking_source' => $source !== '' ? $source : $this->stringFrom($serviceRow, $serviceColumns, ['booking_source', 'gds_source', 'source']),
            'ticket_status' => $status !== '' ? $status : (strtoupper($this->stringFrom($serviceRow, $serviceColumns, ['ticket_status'])) ?: 'BOOKED'),
            'issue_date' => $issueDate !== '' ? $issueDate : $this->dateOnly($this->valueFrom($serviceRow, $serviceColumns, ['issue_date', 'ticket_issue_date', 'issued_at'])),
            'supplier_id' => $supplierId > 0 ? $supplierId : ($serviceSupplierId > 0 ? $serviceSupplierId : (int) ($serviceVendorMeta['id'] ?? 0)),
            'supplier_name' => $supplierName !== '' ? $supplierName : ($serviceSupplierName !== '' ? $serviceSupplierName : trim((string) ($serviceVendorMeta['name'] ?? ''))),
        ];
    }

    /** @return array<string,mixed> */
    private function summary(array $tickets, int $serviceId = 0): array
    {
        $customer = round(array_sum(array_map(static fn (array $row): float => (float) ($row['customer_total'] ?? 0), $tickets)), 2);
        $supplier = round(array_sum(array_map(static fn (array $row): float => (float) ($row['supplier_total'] ?? 0), $tickets)), 2);

        // ERP-11.3.125: ticket KPI has one authoritative source — persisted native
        // passenger-ticket identifiers. Do not infer it from rendered DOM text or
        // Air service count. Prefer a physical-schema count and fall back to the
        // normalized ticket snapshot only if the native store cannot be inspected.
        $nativeTicketCount = $serviceId > 0 ? $this->nativeTicketCount($serviceId) : null;
        $snapshotTicketCount = count(array_filter($tickets, static fn (array $row): bool => trim((string) ($row['ticket_number'] ?? '')) !== ''));

        return [
            'ticket_count' => $nativeTicketCount ?? $snapshotTicketCount,
            'customer_total' => $customer,
            'supplier_total' => $supplier,
            'gross_margin' => round($customer - $supplier, 2),
        ];
    }

    private function nativeTicketCount(int $serviceId): ?int
    {
        if ($serviceId <= 0 || ! Schema::hasTable('air_ticket_details')) return null;

        try {
            $table = 'air_ticket_details';
            $columns = $this->physicalColumnListing($table);
            if (! in_array('booking_service_id', $columns, true)) return null;

            $ticketAliases = array_values(array_filter(
                ['ticket_number', 'ticket_no', 'e_ticket_number', 'eticket_number', 'document_number', 'document_no'],
                static fn (string $column): bool => in_array($column, $columns, true)
            ));
            if (! $ticketAliases) return 0;

            $passengerColumn = $this->firstColumn($columns, ['booking_passenger_id', 'passenger_id', 'traveller_id', 'traveler_id']);
            $select = array_values(array_unique(array_merge(
                ['id'],
                $passengerColumn ? [$passengerColumn] : [],
                $ticketAliases
            )));
            $select = array_values(array_filter($select, static fn (string $column): bool => in_array($column, $columns, true)));

            $query = DB::table($table)->where('booking_service_id', $serviceId);
            if (in_array('deleted_at', $columns, true)) $query->whereNull('deleted_at');

            $seen = [];
            foreach ($query->select($select)->get() as $rowObject) {
                $row = (array) $rowObject;
                if ($this->firstNonEmpty($row, $ticketAliases) === '') continue;

                $passengerValue = $passengerColumn ? (int) ($row[$passengerColumn] ?? 0) : 0;
                $rowId = (int) ($row['id'] ?? 0);
                $key = $passengerValue > 0 ? 'p:'.$passengerValue : 'r:'.$rowId;
                $seen[$key] = true;
            }

            return count($seen);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function fareCommercialsSnapshot(array $tickets): array
    {
        $result = [];
        foreach (['ADULT', 'CHILD', 'INFANT'] as $fareType) {
            $matching = array_values(array_filter($tickets, fn (array $row): bool => $this->normalizeFareType((string) ($row['fare_type'] ?? '')) === $fareType));
            $first = $matching[0] ?? [];
            $meta = is_array($first['commercial_meta'] ?? null) ? $first['commercial_meta'] : [];

            $basicRate = $this->money($meta['basic_rate'] ?? ($first['customer_base_fare'] ?? 0));
            $storedTaxes = $this->money($meta['taxes'] ?? ($first['customer_taxes'] ?? 0));
            $nativeSalePrice = $this->money($first['customer_total_sale_value'] ?? 0);
            $customerNet = $this->money($first['customer_total'] ?? 0);
            $nativeCustomerDiscount = $this->money($first['customer_discount'] ?? 0);
            $salePrice = $this->money($meta['sale_price'] ?? ($nativeSalePrice > 0 ? $nativeSalePrice : ($customerNet + $nativeCustomerDiscount)));
            if ($salePrice == 0.0 && ($basicRate + $storedTaxes) > 0.0) {
                $salePrice = round($basicRate + $storedTaxes, 2);
            }

            $customerMinusAmount = $this->money($meta['customer_minus_amount'] ?? ($nativeCustomerDiscount ?: max(0.0, $salePrice - $customerNet)));
            $nativeCustomerMinusType = strtoupper((string) ($first['customer_discount_type'] ?? ''));
            $customerMinusType = strtoupper((string) ($meta['customer_minus_type'] ?? ($meta['customer_discount_type'] ?? ($nativeCustomerMinusType ?: 'FIXED'))));
            if (in_array($customerMinusType, ['%', 'PERCENTAGE'], true)) $customerMinusType = 'PERCENT';
            if (! in_array($customerMinusType, ['FIXED', 'PERCENT'], true)) $customerMinusType = 'FIXED';
            $customerMinusValue = $customerMinusType === 'PERCENT'
                ? $this->money($meta['customer_minus_value'] ?? ($meta['customer_discount_value'] ?? ($basicRate > 0 ? ($customerMinusAmount / $basicRate) * 100 : 0)))
                : $this->money($meta['customer_minus_value'] ?? ($meta['customer_discount_value'] ?? $customerMinusAmount));

            // ERP-11.3.116: if the native table has no JSON metadata column,
            // reconstruct the fare-row V O Cost by summing its per-passenger
            // native allocations. This keeps the commercial matrix persistent
            // after refresh without introducing a parallel table or migration.
            $nativeVendorOtherCost = round(array_sum(array_map(
                fn (array $row): float => $this->money($row['supplier_other_cost'] ?? 0),
                $matching
            )), 2);
            $vendorOtherCost = $this->money($meta['vendor_other_cost'] ?? $nativeVendorOtherCost);
            $vendorNet = $this->money($first['supplier_total'] ?? 0);
            $vendorMinusAmount = $this->money($meta['vendor_minus_amount'] ?? ($first['supplier_discount'] ?? 0));
            $nativeCostPrice = $this->money($first['supplier_cost_price'] ?? 0);
            $firstOtherAllocation = $this->money($first['supplier_other_cost'] ?? 0);
            // Cost Price is authoritative. Prefer the native gross-cost field;
            // otherwise infer per-pax gross cost from Vendor Net + Minus - that
            // ticket's allocated share of the fare-row V O Cost.
            $costPrice = $this->money($meta['cost_price'] ?? ($nativeCostPrice > 0
                ? $nativeCostPrice
                : max(0.0, $vendorNet + $vendorMinusAmount - $firstOtherAllocation)));
            if ($costPrice == 0.0 && ($basicRate + $storedTaxes) > 0.0 && $vendorNet == 0.0) {
                $costPrice = round($basicRate + $storedTaxes, 2);
            }
            $taxes = max(0.0, round($costPrice - $basicRate, 2));
            $nativeVendorMinusType = strtoupper((string) ($first['supplier_discount_type'] ?? ''));
            $vendorMinusType = strtoupper((string) ($meta['vendor_minus_type'] ?? ($meta['supplier_discount_type'] ?? ($nativeVendorMinusType ?: 'FIXED'))));
            if (in_array($vendorMinusType, ['%', 'PERCENTAGE'], true)) $vendorMinusType = 'PERCENT';
            if (! in_array($vendorMinusType, ['FIXED', 'PERCENT'], true)) $vendorMinusType = 'FIXED';
            $vendorMinusValue = $vendorMinusType === 'PERCENT'
                ? $this->money($meta['vendor_minus_value'] ?? ($meta['supplier_discount_value'] ?? ($basicRate > 0 ? ($vendorMinusAmount / $basicRate) * 100 : 0)))
                : $this->money($meta['vendor_minus_value'] ?? ($meta['supplier_discount_value'] ?? $vendorMinusAmount));

            $margin = round($customerNet - $vendorNet, 2);
            $keys = array_map(static fn (array $row): string => implode('|', [
                number_format((float) ($row['customer_total'] ?? 0), 2, '.', ''),
                number_format((float) ($row['supplier_total'] ?? 0), 2, '.', ''),
            ]), $matching);

            $result[$fareType] = [
                'fare_type' => $fareType,
                'sale_price' => $salePrice,
                'cost_price' => $costPrice,
                'basic_rate' => $basicRate,
                'taxes' => $taxes,
                'vendor_minus_type' => $vendorMinusType,
                'vendor_minus_value' => round($vendorMinusValue, 4),
                'vendor_minus_amount' => $vendorMinusAmount,
                'customer_minus_type' => $customerMinusType,
                'customer_minus_value' => round($customerMinusValue, 4),
                'customer_minus_amount' => $customerMinusAmount,
                'vendor_other_cost' => $vendorOtherCost,
                'customer_net' => $customerNet,
                'vendor_net' => $vendorNet,
                'margin' => $margin,
                // Legacy aliases keep downstream/native integrations stable.
                'customer_total_sale_value' => $salePrice,
                'customer_base_fare' => $basicRate,
                'customer_taxes' => $taxes,
                'customer_discount_type' => $customerMinusType,
                'customer_discount_value' => round($customerMinusValue, 4),
                'customer_discount_amount' => $customerMinusAmount,
                'customer_total' => $customerNet,
                'supplier_base_fare' => $basicRate,
                'supplier_taxes' => $taxes,
                'supplier_other_cost' => $vendorOtherCost,
                'supplier_discount_type' => $vendorMinusType,
                'supplier_discount_value' => round($vendorMinusValue, 4),
                'supplier_discount_amount' => $vendorMinusAmount,
                'supplier_total' => $vendorNet,
                'mixed' => count(array_unique($keys)) > 1,
            ];
        }
        return $result;
    }

    /** @return array{ADULT:int,CHILD:int,INFANT:int} */
    private function fareTypeCounts(array $passengers): array
    {
        $counts = ['ADULT' => 0, 'CHILD' => 0, 'INFANT' => 0];
        foreach ($passengers as $passenger) {
            $fareType = $this->normalizeFareType((string) ($passenger['fare_type'] ?? 'ADULT'));
            if (isset($counts[$fareType])) $counts[$fareType]++;
        }
        return $counts;
    }

    /** @return array<string,array<string,mixed>> */
    private function normalizeFareCommercials(array $rows, array $passengers = []): array
    {
        $result = [];
        $fareCounts = $this->fareTypeCounts($passengers);
        foreach ($rows as $index => $row) {
            $fareType = $this->normalizeFareType((string) ($row['fare_type'] ?? ''));

            $salePrice = $this->money($row['sale_price'] ?? ($row['customer_total_sale_value'] ?? 0));
            $costPrice = $this->money($row['cost_price'] ?? ($row['supplier_cost_price'] ?? $salePrice));
            $basicRate = $this->money($row['basic_rate'] ?? ($row['customer_base_fare'] ?? 0));
            if ($basicRate > $costPrice + 0.009) {
                throw ValidationException::withMessages([
                    "fare_commercials.$index.basic_rate" => "$fareType Basic Rate cannot be greater than Cost Price.",
                ]);
            }
            $taxes = max(0.0, round($costPrice - $basicRate, 2));

            $customerMinusType = strtoupper((string) ($row['customer_minus_type'] ?? ($row['customer_discount_type'] ?? 'FIXED')));
            if (! in_array($customerMinusType, ['FIXED', 'PERCENT'], true)) $customerMinusType = 'FIXED';
            $customerMinusValue = $this->money($row['customer_minus_value'] ?? ($row['customer_discount_value'] ?? 0));
            if ($customerMinusType === 'PERCENT' && $customerMinusValue > 100) {
                throw ValidationException::withMessages([
                    "fare_commercials.$index.customer_minus_value" => "$fareType Customer Minus percentage cannot exceed 100%.",
                ]);
            }
            $customerMinusAmount = $this->discountAmount($basicRate, $customerMinusType, $customerMinusValue);
            $customerNet = max(0.0, round($salePrice - $customerMinusAmount, 2));

            $vendorMinusType = strtoupper((string) ($row['vendor_minus_type'] ?? ($row['supplier_discount_type'] ?? 'FIXED')));
            if (! in_array($vendorMinusType, ['FIXED', 'PERCENT'], true)) $vendorMinusType = 'FIXED';
            $vendorMinusValue = $this->money($row['vendor_minus_value'] ?? ($row['supplier_discount_value'] ?? 0));
            if ($vendorMinusType === 'PERCENT' && $vendorMinusValue > 100) {
                throw ValidationException::withMessages([
                    "fare_commercials.$index.vendor_minus_value" => "$fareType Vendor Minus percentage cannot exceed 100%.",
                ]);
            }
            $vendorMinusAmount = $this->discountAmount($basicRate, $vendorMinusType, $vendorMinusValue);
            // V O Cost is a fare-type ROW TOTAL. It is added once after the
            // per-passenger vendor net has been multiplied by ADT/CHD/INF Pax.
            $vendorOtherCost = $this->money($row['vendor_other_cost'] ?? ($row['supplier_other_cost'] ?? 0));
            $paxCount = max(0, (int) ($fareCounts[$fareType] ?? 0));
            $vendorBaseNet = max(0.0, round($costPrice - $vendorMinusAmount, 2));
            $customerRowTotal = round($customerNet * $paxCount, 2);
            $vendorRowTotal = round(($vendorBaseNet * $paxCount) + $vendorOtherCost, 2);
            $vendorNet = $paxCount > 0 ? round($vendorRowTotal / $paxCount, 2) : $vendorBaseNet;
            $margin = $paxCount > 0
                ? round(($customerRowTotal - $vendorRowTotal) / $paxCount, 2)
                : round($customerNet - $vendorBaseNet, 2);

            $result[$fareType] = [
                'fare_type' => $fareType,
                'sale_price' => $salePrice,
                'cost_price' => $costPrice,
                'basic_rate' => $basicRate,
                'taxes' => $taxes,
                'vendor_minus_type' => $vendorMinusType,
                'vendor_minus_value' => $vendorMinusValue,
                'vendor_minus_amount' => $vendorMinusAmount,
                'customer_minus_type' => $customerMinusType,
                'customer_minus_value' => $customerMinusValue,
                'customer_minus_amount' => $customerMinusAmount,
                'vendor_other_cost' => $vendorOtherCost,
                'pax_count' => $paxCount,
                'customer_net' => $customerNet,
                'vendor_base_net' => $vendorBaseNet,
                'vendor_net' => $vendorNet,
                'customer_row_total' => $customerRowTotal,
                'vendor_row_total' => $vendorRowTotal,
                'margin_row_total' => round($customerRowTotal - $vendorRowTotal, 2),
                'margin' => $margin,
                // Native field aliases used by existing Air accounting/invoice logic.
                'customer_total_sale_value' => $salePrice,
                'customer_base_fare' => $basicRate,
                'customer_taxes' => $taxes,
                'customer_discount_type' => $customerMinusType,
                'customer_discount_value' => $customerMinusValue,
                'customer_discount_amount' => $customerMinusAmount,
                'customer_total' => $customerNet,
                'supplier_base_fare' => $basicRate,
                'supplier_taxes' => $taxes,
                'supplier_other_cost' => $vendorOtherCost,
                'supplier_discount_type' => $vendorMinusType,
                'supplier_discount_value' => $vendorMinusValue,
                'supplier_discount_amount' => $vendorMinusAmount,
                'supplier_total' => $vendorNet,
            ];
        }
        foreach (['ADULT', 'CHILD', 'INFANT'] as $fareType) {
            if (! isset($result[$fareType])) $result[$fareType] = $this->emptyFareCommercial($fareType);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function emptyFareCommercial(string $fareType): array
    {
        return [
            'fare_type' => $fareType,
            'sale_price' => 0.0,
            'cost_price' => 0.0,
            'basic_rate' => 0.0,
            'taxes' => 0.0,
            'vendor_minus_type' => 'FIXED',
            'vendor_minus_value' => 0.0,
            'vendor_minus_amount' => 0.0,
            'customer_minus_type' => 'FIXED',
            'customer_minus_value' => 0.0,
            'customer_minus_amount' => 0.0,
            'vendor_other_cost' => 0.0,
            'customer_net' => 0.0,
            'vendor_net' => 0.0,
            'margin' => 0.0,
            'customer_total_sale_value' => 0.0,
            'customer_base_fare' => 0.0,
            'customer_taxes' => 0.0,
            'customer_discount_type' => 'FIXED',
            'customer_discount_value' => 0.0,
            'customer_discount_amount' => 0.0,
            'customer_total' => 0.0,
            'supplier_base_fare' => 0.0,
            'supplier_taxes' => 0.0,
            'supplier_other_cost' => 0.0,
            'supplier_discount_type' => 'FIXED',
            'supplier_discount_value' => 0.0,
            'supplier_discount_amount' => 0.0,
            'supplier_total' => 0.0,
        ];
    }

    private function discountAmount(float $gross, string $type, float $value): float
    {
        $amount = strtoupper($type) === 'PERCENT'
            ? $gross * max(0.0, min(100.0, $value)) / 100
            : max(0.0, $value);
        return min($gross, round($amount, 2));
    }

    private function syncAirServiceContext(int $serviceId, array $common): void
    {
        if ($serviceId <= 0 || ! Schema::hasTable('booking_services')) return;
        $columns = Schema::getColumnListing('booking_services');
        $update = [];
        $supplierId = (int) ($common['supplier_id'] ?? 0);
        if ($supplierId > 0) $this->put($update, $columns, ['supplier_id', 'vendor_id'], $supplierId);
        $this->putAllAllowEmpty($update, $columns, ['supplier_name', 'vendor_name'], trim((string) ($common['supplier_name'] ?? '')));
        // ERP-11.3.132: preserve/read vendor context through the first
        // JSON-capable metadata alias, not merely the first installed meta-like
        // column (which can be unrelated non-JSON text on older installs).
        foreach (['meta', 'metadata', 'extra_data', 'details_json', 'attributes'] as $metaField) {
            if (! in_array($metaField, $columns, true)) continue;
            try {
                $currentMeta = DB::table('booking_services')->where('id', $serviceId)->value($metaField);
                if ($currentMeta !== null) $update[$metaField] = $currentMeta;
            } catch (Throwable) {
            }
        }
        $this->writeVendorMeta($update, $columns, $supplierId, trim((string) ($common['supplier_name'] ?? '')));
        $this->putAllowEmpty($update, $columns, ['pnr', 'gds_pnr', 'record_locator'], trim((string) ($common['pnr'] ?? '')));
        $this->putAllowEmpty($update, $columns, ['airline_pnr', 'supplier_pnr'], trim((string) ($common['airline_pnr'] ?? '')));
        $serviceTicketStatus = strtoupper((string) ($common['ticket_status'] ?? 'BOOKED'));
        $this->putNativeEnum($update, 'booking_services', $columns, ['ticket_status'], $serviceTicketStatus, $this->ticketStatusAliases($serviceTicketStatus));
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if ($update) DB::table('booking_services')->where('id', $serviceId)->update($update);
    }

    /** @return list<array{id:int,code:string,name:string,label:string}> */
    private function airlineOptions(): array
    {
        foreach (['airlines', 'travel_airlines', 'airline_master', 'airline_masters', 'travel_airline_master', 'travel_airline_masters'] as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = Schema::getColumnListing($table);
                $idColumn = $this->firstColumn($columns, ['id', 'airline_id']);
                $nameColumn = $this->firstColumn($columns, ['name', 'airline_name', 'title', 'display_name']);
                $codeColumn = $this->firstColumn($columns, ['iata_code', 'airline_code', 'code', 'iata', 'short_code']);
                if (!$idColumn || (!$nameColumn && !$codeColumn)) continue;

                $select = [$idColumn];
                if ($nameColumn) $select[] = $nameColumn;
                if ($codeColumn && ! in_array($codeColumn, $select, true)) $select[] = $codeColumn;
                $query = DB::table($table)->select($select);
                $statusColumn = $this->firstColumn($columns, ['is_active', 'active']);
                if ($statusColumn) $query->where($statusColumn, 1);
                $sortColumn = $nameColumn ?: $codeColumn;
                $rows = $query->orderBy($sortColumn)->get();
                if ($rows->isEmpty()) continue;

                return $rows->map(static function (object $row) use ($idColumn, $nameColumn, $codeColumn): array {
                    $id = (int) $row->{$idColumn};
                    $name = $nameColumn ? trim((string) ($row->{$nameColumn} ?? '')) : '';
                    $code = $codeColumn ? strtoupper(trim((string) ($row->{$codeColumn} ?? ''))) : '';
                    $label = trim(($name !== '' ? $name : $code).($name !== '' && $code !== '' ? " ($code)" : ''));
                    return ['id' => $id, 'code' => $code, 'name' => $name, 'label' => $label];
                })->filter(static fn (array $row): bool => $row['id'] > 0 && $row['label'] !== '')->values()->all();
            } catch (Throwable) {
            }
        }
        return [];
    }

    /** @return list<array{airline_id:int,airline_code:string,airline:string,flight_number:string}> */
    private function flightNumberOptions(): array
    {
        if (! Schema::hasTable('booking_itinerary_segments')) return [];
        try {
            $table = 'booking_itinerary_segments';
            $columns = Schema::getColumnListing($table);
            $flightColumn = $this->firstColumn($columns, ['flight_number', 'flight_no']);
            if (!$flightColumn) return [];
            $rows = DB::table($table)
                ->whereNotNull($flightColumn)
                ->where($flightColumn, '<>', '')
                ->orderByDesc(in_array('id', $columns, true) ? 'id' : $flightColumn)
                ->limit(1000)
                ->get();

            $seen = [];
            $result = [];
            foreach ($rows as $row) {
                $data = (array) $row;
                $flight = strtoupper(trim((string) ($data[$flightColumn] ?? '')));
                if ($flight === '') continue;
                $airlineId = (int) ($this->valueFrom($data, $columns, ['airline_id', 'carrier_id']) ?? 0);
                $airlineCode = strtoupper($this->stringFrom($data, $columns, ['airline_code', 'carrier_code']));
                $airline = $this->stringFrom($data, $columns, ['airline_name', 'airline', 'carrier_name']);
                $key = $airlineId.'|'.strtolower($airlineCode.'|'.$airline).'|'.$flight;
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $result[] = [
                    'airline_id' => $airlineId,
                    'airline_code' => $airlineCode,
                    'airline' => $airline,
                    'flight_number' => $flight,
                ];
                if (count($result) >= 500) break;
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    private function itineraryStatusFromTicketStatus(string $ticketStatus): string
    {
        return match (strtoupper(trim($ticketStatus))) {
            'ISSUED' => 'issued',
            'VOID', 'REFUNDED', 'CANCELLED' => 'cancelled',
            'PENDING' => 'requested',
            default => 'booked',
        };
    }

    /** @return array<string,mixed> */
    private function commercialMetaFromRow(array $row, array $columns): array
    {
        foreach (['meta', 'metadata', 'extra_data', 'details_json', 'attributes'] as $field) {
            if (! in_array($field, $columns, true) || ! array_key_exists($field, $row)) continue;
            $raw = $row[$field];
            if (is_array($raw)) $decoded = $raw;
            else {
                $decoded = json_decode((string) ($raw ?? ''), true);
                if (! is_array($decoded)) continue;
            }
            $commercial = $decoded['et_erp_commercial'] ?? null;
            return is_array($commercial) ? $commercial : [];
        }
        return [];
    }

    private function writeCommercialMeta(array &$row, array $columns, array $commercial): void
    {
        $field = $this->firstColumn($columns, ['meta', 'metadata', 'extra_data', 'details_json', 'attributes']);
        if (!$field) return;
        $existing = [];
        $rawExisting = $row[$field] ?? null;
        if ($rawExisting !== null && $rawExisting !== '') {
            if (is_array($rawExisting)) {
                $existing = $rawExisting;
            } else {
                $decoded = json_decode((string) $rawExisting, true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                } else {
                    $metadata = $this->columnMetadata('air_ticket_details');
                    $type = isset($metadata[$field]) ? $this->metaType($metadata[$field]) : '';
                    if (! str_contains($type, 'json')) return;
                }
            }
        }
        $existing['et_erp_commercial'] = [
            'version' => 'ERP-11.3.132',
            'fare_type' => (string) ($commercial['fare_type'] ?? 'ADULT'),
            'supplier_id' => (int) ($commercial['supplier_id'] ?? $commercial['vendor_id'] ?? 0),
            'vendor_id' => (int) ($commercial['vendor_id'] ?? $commercial['supplier_id'] ?? 0),
            'supplier_name' => trim((string) ($commercial['supplier_name'] ?? $commercial['vendor_name'] ?? '')),
            'vendor_name' => trim((string) ($commercial['vendor_name'] ?? $commercial['supplier_name'] ?? '')),
            'sale_price' => $this->money($commercial['sale_price'] ?? ($commercial['customer_total_sale_value'] ?? 0)),
            'cost_price' => $this->money($commercial['cost_price'] ?? 0),
            'basic_rate' => $this->money($commercial['basic_rate'] ?? ($commercial['customer_base_fare'] ?? 0)),
            'taxes' => $this->money($commercial['taxes'] ?? ($commercial['customer_taxes'] ?? 0)),
            'vendor_minus_type' => (string) ($commercial['vendor_minus_type'] ?? ($commercial['supplier_discount_type'] ?? 'FIXED')),
            'vendor_minus_value' => $this->money($commercial['vendor_minus_value'] ?? ($commercial['supplier_discount_value'] ?? 0)),
            'vendor_minus_amount' => $this->money($commercial['vendor_minus_amount'] ?? ($commercial['supplier_discount_amount'] ?? 0)),
            'customer_minus_type' => (string) ($commercial['customer_minus_type'] ?? ($commercial['customer_discount_type'] ?? 'FIXED')),
            'customer_minus_value' => $this->money($commercial['customer_minus_value'] ?? ($commercial['customer_discount_value'] ?? 0)),
            'customer_minus_amount' => $this->money($commercial['customer_minus_amount'] ?? ($commercial['customer_discount_amount'] ?? 0)),
            'vendor_other_cost' => $this->money($commercial['vendor_other_cost'] ?? ($commercial['supplier_other_cost'] ?? 0)),
            'vendor_other_cost_scope' => 'FARE_ROW_TOTAL',
            'vendor_other_cost_allocation' => $this->money($commercial['vendor_other_cost_allocation'] ?? 0),
            'pax_count' => (int) ($commercial['pax_count'] ?? 0),
            'customer_net' => $this->money($commercial['customer_net'] ?? ($commercial['customer_total'] ?? 0)),
            'vendor_net' => $this->money($commercial['vendor_net'] ?? ($commercial['supplier_total'] ?? 0)),
            'customer_row_total' => $this->money($commercial['customer_row_total'] ?? 0),
            'vendor_row_total' => $this->money($commercial['vendor_row_total'] ?? 0),
            'margin_row_total' => $this->money($commercial['margin_row_total'] ?? 0),
            'margin' => $this->money($commercial['margin'] ?? 0),
            // Legacy keys retained for older reporting/readers.
            'customer_discount_type' => (string) ($commercial['customer_discount_type'] ?? 'FIXED'),
            'customer_discount_value' => $this->money($commercial['customer_discount_value'] ?? 0),
            'customer_discount_amount' => $this->money($commercial['customer_discount_amount'] ?? 0),
            'supplier_discount_type' => (string) ($commercial['supplier_discount_type'] ?? 'FIXED'),
            'supplier_discount_value' => $this->money($commercial['supplier_discount_value'] ?? 0),
            'supplier_discount_amount' => $this->money($commercial['supplier_discount_amount'] ?? 0),
        ];
        $row[$field] = json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            if (is_array($vendor)) {
                return ['id' => (int) ($vendor['id'] ?? 0), 'name' => trim((string) ($vendor['name'] ?? ''))];
            }
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
                if (! is_array($decoded)) continue; // try next metadata alias
                $existing = $decoded;
            }
            $existing['et_erp_vendor'] = [
                'version' => 'ERP-11.3.132',
                'id' => max(0, $vendorId),
                'name' => trim($vendorName),
            ];
            $row[$field] = json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
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

    /** @return list<array{id:int,name:string}> */
    private function supplierOptions(): array
    {
        // ERP-11.3.116: use the same authoritative Vendor / Supplier source as
        // Group Umrah instead of looking only at standalone suppliers/vendors
        // tables. Most production installs keep vendors in the shared parties
        // master with a vendor/supplier/service role.
        try {
            $vendors = app(UnifiedGroupPackageDataSource::class)->vendors();
            if ($vendors->isNotEmpty()) {
                return $vendors->map(static function (array $row): array {
                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'name' => trim((string) ($row['name'] ?? '')),
                    ];
                })->filter(static fn (array $row): bool => $row['id'] > 0 && $row['name'] !== '')
                    ->unique('id')
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all();
            }
        } catch (Throwable) {
            // Fall through to adaptive table discovery below.
        }

        foreach (['parties', 'suppliers', 'vendors', 'travel_suppliers', 'supplier_masters', 'vendor_masters'] as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = Schema::getColumnListing($table);
                $idColumn = $this->firstColumn($columns, ['id', 'party_id', 'supplier_id', 'vendor_id']);
                $nameColumn = $this->firstColumn($columns, ['name', 'display_name', 'legal_name', 'supplier_name', 'vendor_name', 'company_name', 'title']);
                if (!$idColumn || !$nameColumn) continue;

                $query = DB::table($table)->select([$idColumn, $nameColumn]);

                if ($table === 'parties') {
                    $typeColumn = $this->firstColumn($columns, ['party_type', 'type', 'category', 'role']);
                    if ($typeColumn) {
                        $query->where(function ($q) use ($typeColumn): void {
                            $q->whereRaw('LOWER(' . $typeColumn . ") LIKE '%vendor%'")
                                ->orWhereRaw('LOWER(' . $typeColumn . ") LIKE '%supplier%'")
                                ->orWhereRaw('LOWER(' . $typeColumn . ") LIKE '%service%'");
                        });
                    }
                }

                $statusColumn = $this->firstColumn($columns, ['is_active', 'active']);
                if ($statusColumn) $query->where($statusColumn, 1);

                $rows = $query->orderBy($nameColumn)->limit(1500)->get();
                if ($rows->isNotEmpty()) {
                    return $rows->map(static function (object $row) use ($idColumn, $nameColumn): array {
                        return ['id' => (int) $row->{$idColumn}, 'name' => trim((string) $row->{$nameColumn})];
                    })->filter(static fn (array $row): bool => $row['id'] > 0 && $row['name'] !== '')
                        ->unique('id')
                        ->values()
                        ->all();
                }
            } catch (Throwable) {
            }
        }
        return [];
    }

    private function airTicketPrototype(): array
    {
        if (! Schema::hasTable('air_ticket_details')) return [];
        $row = DB::table('air_ticket_details')->orderByDesc('id')->first();
        return $row ? (array) $row : [];
    }

    private function prototypeFor(string $table): array
    {
        if (! Schema::hasTable($table) || ! in_array('id', Schema::getColumnListing($table), true)) return [];
        $row = DB::table($table)->orderByDesc('id')->first();
        return $row ? (array) $row : [];
    }

    private function scrubTicketPrototype(array &$row): void
    {
        foreach (array_keys($row) as $field) {
            $lower = strtolower($field);
            if ($field === 'id') {
                unset($row[$field]);
                continue;
            }
            if (
                str_contains($lower, 'ticket')
                || in_array($lower, ['company_id', 'branch_id', 'customer_id', 'agent_id', 'salesperson_id', 'currency_id', 'tenant_id', 'office_id'], true)
                || str_contains($lower, 'pnr')
                || str_contains($lower, 'passenger')
                || str_contains($lower, 'traveller')
                || str_contains($lower, 'traveler')
                || str_contains($lower, 'fare')
                || str_contains($lower, 'tax')
                || str_contains($lower, 'cost')
                || str_contains($lower, 'sale')
                || str_contains($lower, 'sell')
                || str_contains($lower, 'price')
                || str_contains($lower, 'amount')
                || str_contains($lower, 'discount')
                || str_contains($lower, 'markup')
                || str_contains($lower, 'charge')
                || str_contains($lower, 'baggage')
                || str_contains($lower, 'class')
                || str_contains($lower, 'supplier')
                || str_contains($lower, 'vendor')
                || str_contains($lower, 'issue_date')
                || str_contains($lower, 'issued_at')
                || str_contains($lower, 'booking_source')
                || str_contains($lower, 'gds')
                || in_array($lower, ['meta', 'metadata', 'extra_data', 'details_json', 'attributes'], true)
                || $lower === 'source'
            ) {
                $row[$field] = null;
            }
        }
        $this->clearUniqueReferenceFields('air_ticket_details', $row);
    }

    private function fillRequiredByPrototype(string $table, array $row, array $prototype, array $known): array
    {
        // ERP-11.3.122: the physical column list must be the same contract used
        // by preflight. Do not intersect against a potentially stale Laravel
        // column listing or mandatory legacy fields can be dropped before INSERT.
        $columns = $this->physicalColumnListing($table);
        $metadata = $this->columnMetadata($table);
        if (!$columns || !$metadata) {
            throw ValidationException::withMessages([
                'air' => 'Native Air physical schema inspection is unavailable. No Air data was written.',
            ]);
        }
        $row = array_intersect_key($row, array_flip($columns));


        if (!$metadata) return $row;

        $unresolved = [];
        foreach ($metadata as $field => $meta) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') continue;
            if ($this->columnCanBeOmitted($field, $meta)) continue;

            if (array_key_exists($field, $known) && $known[$field] !== null && $known[$field] !== '') {
                $row[$field] = $known[$field];
                continue;
            }

            $lower = strtolower($field);

            // Never fabricate a real travel document/ticket identity. If one of
            // these fields is mandatory, staff must provide it in the product UI.
            if (in_array($lower, [
                'ticket_number', 'ticket_no', 'e_ticket_number', 'eticket_number',
                'document_number', 'document_no',
            ], true)) {
                $unresolved[] = $field;
                continue;
            }

            // Do not copy a unique reference from another booking/service row.
            // Generate a new technical reference only for non-ticket native keys.
            if ($this->isSingleUniqueColumn($table, $field)) {
                $row[$field] = $this->newTechnicalReference($table, $field);
                continue;
            }

            // ERP-11.3.122: legacy Air installations carry several generations
            // of mandatory commercial siblings. Resolve them by semantic family
            // before prototype fallback so we never copy another ticket's money.
            [$commercialMatched, $commercialValue] = $this->requiredCommercialCompatibilityValue($field, $known, $meta);
            if ($commercialMatched) {
                $row[$field] = $commercialValue;
                continue;
            }

            if (array_key_exists($field, $prototype) && $prototype[$field] !== null && $prototype[$field] !== '') {
                $row[$field] = $prototype[$field];
                continue;
            }
            if (in_array($lower, ['created_by', 'created_by_id', 'updated_by', 'updated_by_id', 'user_id'], true) && Auth::id()) {
                $row[$field] = Auth::id();
                continue;
            }
            if ($lower === 'created_at' || $lower === 'updated_at') {
                $row[$field] = now();
                continue;
            }
            if ($lower === 'status' || str_ends_with($lower, '_status')) {
                $row[$field] = $this->safeNativeStatusValue($table, $field, $prototype[$field] ?? null);
                continue;
            }
            if (str_starts_with($lower, 'is_') || in_array($this->metaType($meta), ['boolean', 'bool'], true)) {
                $row[$field] = 0;
                continue;
            }
            if (str_ends_with($lower, '_id')) {
                $unresolved[] = $field;
                continue;
            }
            if (in_array($this->metaType($meta), ['integer', 'bigint', 'smallint', 'tinyint', 'decimal', 'double', 'float'], true)) {
                $row[$field] = 0;
                continue;
            }

            $unresolved[] = $field;
        }

        if ($unresolved) {
            throw ValidationException::withMessages([
                'air' => 'The live Air Ticket schema has required field(s) that cannot be safely inferred: '.implode(', ', array_slice($unresolved, 0, 12)).'.',
            ]);
        }

        return $row;
    }

    /**
     * Resolve mandatory legacy commercial siblings by field semantics.
     *
     * @return array{0:bool,1:mixed}
     */
    private function requiredCommercialCompatibilityValue(string $field, array $known, array $meta): array
    {
        $name = strtolower($field);

        $knownValue = static function (array $keys, mixed $fallback = 0.0) use ($known): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $known) && $known[$key] !== null && $known[$key] !== '') {
                    return $known[$key];
                }
            }
            return $fallback;
        };

        // Supplier/Vendor "other" commercial fields are the row-scoped V O Cost
        // allocation for the current passenger ticket.
        if ((str_contains($name, 'supplier') || str_contains($name, 'vendor'))
            && str_contains($name, 'other')
            && (str_contains($name, 'charge') || str_contains($name, 'cost') || str_contains($name, 'fee'))) {
            return [true, $knownValue([
                'supplier_other_charges', 'supplier_other_charge', 'supplier_other_cost',
                'vendor_other_charges', 'vendor_other_charge', 'vendor_other_cost',
                'supplier_charges', 'supplier_charge', 'supplier_markup',
            ])];
        }

        if ((str_contains($name, 'supplier') || str_contains($name, 'vendor')) && str_contains($name, 'discount')) {
            if (str_ends_with($name, '_type') || $name === 'supplier_discount_type' || $name === 'vendor_discount_type') {
                return [true, 'FIXED'];
            }
            return [true, $knownValue(['supplier_discount_amount', 'vendor_discount_amount', 'supplier_discount'])];
        }

        // Commission / incentive are retired compatibility components in this
        // GENERAL Air workspace. Vendor Minus is the operative supplier reduction.
        if ((str_contains($name, 'supplier') || str_contains($name, 'vendor'))
            && (str_contains($name, 'commission') || str_contains($name, 'incentive'))) {
            if (str_ends_with($name, '_type')) return [true, 'FIXED'];
            return [true, 0.0];
        }

        if (str_contains($name, 'discount')) {
            if (str_ends_with($name, '_type')) return [true, 'FIXED'];
            return [true, $knownValue(['customer_discount_amount', 'discount_amount', 'discount'])];
        }

        if (str_contains($name, 'markup') || str_contains($name, 'service_fee') || str_contains($name, 'service_charge')) {
            return [true, 0.0];
        }

        if (str_contains($name, 'tax')) {
            return [true, $knownValue(['airline_taxes', 'taxes', 'tax_amount', 'supplier_taxes'])];
        }

        if (str_contains($name, 'base_fare') || str_contains($name, 'basic_fare')) {
            return [true, $knownValue(['base_fare', 'basic_fare', 'supplier_base_fare'])];
        }

        if ((str_contains($name, 'supplier') || str_contains($name, 'vendor'))
            && (str_contains($name, 'cost') || str_contains($name, 'purchase') || str_contains($name, 'payable'))) {
            return [true, $knownValue(['net_supplier_cost', 'supplier_cost', 'supplier_total'])];
        }

        if (str_contains($name, 'sale') || str_contains($name, 'selling') || str_contains($name, 'receivable')) {
            return [true, $knownValue(['selling_total', 'customer_total'])];
        }

        // A mandatory numeric commercial-looking field should never force a
        // one-by-one production patch. Unknown numeric compatibility fields are
        // neutral at zero; identity/reference fields are deliberately excluded.
        $type = $this->metaType($meta);
        if (in_array($type, ['integer', 'bigint', 'smallint', 'tinyint', 'decimal', 'double', 'float'], true)
            && preg_match('/(?:amount|charge|cost|fee|fare|tax|markup|discount|commission|incentive|price|total|rate|percent|value)/', $name)) {
            return [true, 0.0];
        }

        return [false, null];
    }

    /**
     * Proactively populate installed native status siblings before required-field
     * preflight. This deliberately does not rely on nullable/default metadata:
     * long-lived production schemas can expose a status field to INSERT while a
     * framework metadata layer still reports it as optional.
     */
    private function saturateNativeStatusCompatibility(array &$row, string $table, array $columns, array $prototype = []): void
    {
        foreach ($columns as $field) {
            $field = (string) $field;
            $lower = strtolower($field);
            if ($lower !== 'status' && ! str_ends_with($lower, '_status')) continue;
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') continue;

            $row[$field] = $this->safeNativeStatusValue($table, $field, $prototype[$field] ?? null);
        }
    }

    /**
     * Select a conservative live-native value for a status field.
     *
     * Cost/accounting status fields must never be fabricated as posted/paid.
     * Prefer pending/draft/unposted/open values exposed by the live enum. For a
     * generic status field prefer active, then other non-final values. If the
     * field is not an enum, use a conservative textual value that matches the
     * field's semantics.
     */
    private function safeNativeStatusValue(string $table, string $field, mixed $prototypeValue = null): string
    {
        $lower = strtolower($field);
        $isCostStatus = (str_contains($lower, 'supplier') || str_contains($lower, 'vendor') || str_contains($lower, 'cost'))
            && str_contains($lower, 'status');

        $preferred = $isCostStatus
            ? ['pending', 'unposted', 'not_posted', 'draft', 'open', 'active', 'new']
            : ['active', 'pending', 'draft', 'open', 'booked', 'new'];

        $finalWords = ['posted', 'paid', 'settled', 'closed', 'approved', 'completed', 'issued', 'refunded', 'void', 'voided', 'cancelled', 'canceled'];
        $prototype = trim((string) ($prototypeValue ?? ''));
        $options = $this->enumOptions($table, $field);

        if ($options) {
            foreach ($preferred as $candidate) {
                foreach ($options as $option) {
                    if (strcasecmp($candidate, (string) $option) === 0) return (string) $option;
                }
            }

            if ($prototype !== '') {
                foreach ($options as $option) {
                    if (strcasecmp($prototype, (string) $option) !== 0) continue;
                    $prototypeLower = strtolower($prototype);
                    $isFinal = false;
                    foreach ($finalWords as $word) {
                        if (str_contains($prototypeLower, $word)) {
                            $isFinal = true;
                            break;
                        }
                    }
                    if (! $isFinal) return (string) $option;
                }
            }

            foreach ($options as $option) {
                $optionLower = strtolower((string) $option);
                $isFinal = false;
                foreach ($finalWords as $word) {
                    if (str_contains($optionLower, $word)) {
                        $isFinal = true;
                        break;
                    }
                }
                if (! $isFinal) return (string) $option;
            }

            return (string) $options[0];
        }

        if ($prototype !== '') {
            $prototypeLower = strtolower($prototype);
            $isFinal = false;
            foreach ($finalWords as $word) {
                if (str_contains($prototypeLower, $word)) {
                    $isFinal = true;
                    break;
                }
            }
            if (! $isFinal) return $prototype;
        }

        return $isCostStatus ? 'pending' : 'active';
    }

    private function isSingleUniqueColumn(string $table, string $field): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (! is_array($index) || ! (bool) ($index['unique'] ?? false)) continue;
                $fields = array_values((array) ($index['columns'] ?? []));
                if (count($fields) === 1 && (string) $fields[0] === $field) return true;
            }
        } catch (Throwable) {
        }
        return false;
    }

    private function newTechnicalReference(string $table, string $field): string
    {
        $prefix = str_contains(strtolower($table), 'service') ? 'AIR-SVC-' : 'AIR-';
        $value = $prefix.now()->format('ymdHis').'-'.Str::upper(Str::random(6));
        $length = $this->columnLength($table, $field);
        return $length !== null && $length > 0 ? mb_substr($value, 0, $length) : $value;
    }

    private function columnLength(string $table, string $column): ?int
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! preg_match('/^[A-Za-z0-9_]+$/', $column)) return null;
        try {
            $row = DB::selectOne('SHOW COLUMNS FROM `'.$table.'` LIKE ?', [$column]);
            $type = strtolower((string) ($row->Type ?? ''));
            if (preg_match('/(?:var)?char\((\d+)\)/', $type, $match)) return (int) $match[1];
        } catch (Throwable) {
        }
        return null;
    }

    private function clearUniqueReferenceFields(string $table, array &$row): void
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (! is_array($index) || !(bool) ($index['unique'] ?? false)) continue;
                $fields = array_values((array) ($index['columns'] ?? []));
                if (count($fields) !== 1) continue;
                $field = (string) $fields[0];
                if ($field === 'id' || ! array_key_exists($field, $row)) continue;
                $lower = strtolower($field);
                if (str_contains($lower, 'reference') || str_contains($lower, 'number') || str_ends_with($lower, '_no') || str_contains($lower, 'code')) {
                    $row[$field] = null;
                }
            }
        } catch (Throwable) {
        }
    }

    private function foreignTable(string $table, string $column): ?string
    {
        try {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $locals = (array) ($foreign['columns'] ?? $foreign['local_columns'] ?? []);
                if (! in_array($column, $locals, true)) continue;
                $foreignTable = (string) ($foreign['foreign_table'] ?? $foreign['foreign_table_name'] ?? $foreign['table'] ?? '');
                return $foreignTable !== '' ? $foreignTable : null;
            }
        } catch (Throwable) {
        }
        return null;
    }

    private function ticketPassengerValue(array $passenger, ?string $foreignTable): int
    {
        $foreign = strtolower((string) $foreignTable);
        if ($foreign !== '' && ! str_contains($foreign, 'booking_') && (
            str_contains($foreign, 'passenger') || str_contains($foreign, 'traveller') || str_contains($foreign, 'traveler')
        )) {
            return (int) ($passenger['master_id'] ?? 0);
        }
        return (int) ($passenger['id'] ?? 0);
    }

    private function ticketCustomerTotal(array $data, array $columns): float
    {
        $direct = $this->money($this->valueFrom($data, $columns, [
            'selling_total', 'customer_sale', 'customer_sell', 'customer_sale_amount', 'customer_sell_amount',
            'sale_amount', 'sell_amount', 'selling_price', 'sale_price', 'customer_price', 'customer_total', 'receivable_amount',
        ]));
        if ($direct != 0.0) return $direct;

        return max(0.0,
            $this->money($this->valueFrom($data, $columns, ['base_fare', 'basic_fare']))
            + $this->money($this->valueFrom($data, $columns, ['airline_taxes', 'taxes', 'tax_amount']))
            + $this->money($this->valueFrom($data, $columns, ['customer_service_fee', 'service_markup', 'service_charge', 'markup']))
            - $this->money($this->valueFrom($data, $columns, ['customer_discount_amount', 'discount_amount', 'discount']))
        );
    }

    private function ticketSupplierTotal(array $data, array $columns): float
    {
        $direct = $this->money($this->valueFrom($data, $columns, [
            'net_supplier_cost', 'supplier_cost', 'supplier_cost_amount', 'net_cost', 'purchase_cost', 'purchase_price', 'supplier_total', 'cost_amount',
        ]));
        if ($direct != 0.0) return $direct;

        return max(0.0,
            $this->money($this->valueFrom($data, $columns, ['supplier_base_fare', 'base_fare', 'basic_fare']))
            + $this->money($this->valueFrom($data, $columns, ['supplier_taxes', 'airline_taxes', 'taxes']))
            + $this->money($this->valueFrom($data, $columns, ['supplier_charges', 'supplier_charge', 'supplier_markup', 'supplier_other_charges', 'supplier_other_charge', 'supplier_other_cost', 'vendor_other_charges', 'vendor_other_charge', 'vendor_other_cost']))
        );
    }

    private function bookingCurrency(object $booking): string
    {
        $data = (array) $booking;
        foreach (['currency_code', 'currency', 'booking_currency'] as $field) {
            if (! empty($data[$field])) return strtoupper(trim((string) $data[$field]));
        }
        return 'PKR';
    }

    private function segmentType(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === 'return' || $value === 'inbound') return 'return';
        if ($value === 'connection') return 'connection';
        return 'outbound';
    }

    private function normalizeFareType(string $value): string
    {
        $value = strtoupper(trim($value));
        if (str_contains($value, 'INF') || str_contains($value, 'BABY')) return 'INFANT';
        if (str_contains($value, 'CHD') || str_contains($value, 'CHILD') || str_contains($value, 'CNN')) return 'CHILD';
        return 'ADULT';
    }

    private function money(mixed $value): float
    {
        if (is_numeric($value)) return round((float) $value, 2);
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value) ?? '';
        return is_numeric($clean) ? round((float) $clean, 2) : 0.0;
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate;
        return null;
    }

    /** @return list<string> */
    private function ticketStatusAliases(string $status): array
    {
        return match (strtoupper(trim($status))) {
            'ISSUED' => ['issued', 'ticketed'],
            'VOID' => ['void', 'voided', 'cancelled', 'canceled'],
            'REFUNDED' => ['refunded', 'refund', 'cancelled', 'canceled'],
            'CANCELLED' => ['cancelled', 'canceled'],
            'PENDING' => ['pending', 'requested'],
            default => ['booked', 'reserved'],
        };
    }

    /** @return list<string> */
    private function fareTypeAliases(string $fareType): array
    {
        return match ($this->normalizeFareType($fareType)) {
            'CHILD' => ['child', 'chd', 'cnn'],
            'INFANT' => ['infant', 'inf'],
            default => ['adult', 'adt'],
        };
    }

    private function putNativeEnum(
        array &$row,
        string $table,
        array $columns,
        array $candidates,
        mixed $value,
        array $aliases = [],
    ): void {
        foreach ($candidates as $candidate) {
            if (! in_array($candidate, $columns, true)) continue;
            $row[$candidate] = $this->nativeEnumValue($table, $candidate, (string) $value, $aliases);
            return;
        }
    }

    private function nativeEnumValue(string $table, string $column, string $value, array $aliases = []): string
    {
        $options = $this->enumOptions($table, $column);
        if (! $options) return $value;

        $candidates = array_values(array_unique(array_filter(array_merge(
            [$value, strtolower($value), strtoupper($value)],
            array_map('strval', $aliases)
        ), static fn ($candidate): bool => trim((string) $candidate) !== '')));

        foreach ($candidates as $candidate) {
            foreach ($options as $option) {
                if (strcasecmp((string) $candidate, (string) $option) === 0) return (string) $option;
            }
        }

        // Keep the original value so the database rejects an unsupported
        // semantic status rather than silently mapping it to the wrong state.
        return $value;
    }

    /** @return list<string> */
    private function enumOptions(string $table, string $column): array
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! preg_match('/^[A-Za-z0-9_]+$/', $column)) return [];
        try {
            $row = DB::selectOne('SHOW COLUMNS FROM `'.$table.'` LIKE ?', [$column]);
            $type = (string) ($row->Type ?? '');
            if (! preg_match('/^enum\((.*)\)$/i', $type, $match)) return [];
            $raw = str_getcsv($match[1], ',', "'", '\\');
            return array_values(array_map(static fn ($item): string => (string) $item, $raw));
        } catch (Throwable) {
            return [];
        }
    }

    private function nativeQueryFailureMessage(QueryException $e, string $stage): string
    {
        $message = (string) $e->getMessage();
        $prefix = 'Native Air save failed while writing '.$stage.'. ';

        if (preg_match('/Field [\'`"]([^\'`"]+)[\'`"] doesn\\?\'t have a default value/i', $message, $match)
            || preg_match('/Column [\'`"]([^\'`"]+)[\'`"] cannot be null/i', $message, $match)) {
            return $prefix.'The live ERP requires field: '.$match[1].'.';
        }
        if (preg_match('/Data truncated for column [\'`"]([^\'`"]+)[\'`"]/i', $message, $match)) {
            return $prefix.'The live ERP rejected the value for field: '.$match[1].'.';
        }
        if (preg_match('/Duplicate entry .* for key [\'`"]([^\'`"]+)[\'`"]/i', $message, $match)) {
            return $prefix.'A native unique field conflicts with an existing record ('.$match[1].').';
        }
        if (str_contains(strtolower($message), 'foreign key constraint fails')) {
            if (preg_match('/FOREIGN KEY \([\'`"]?([^\'`"\)]+)[\'`"]?\)/i', $message, $match)) {
                return $prefix.'The live ERP rejected the reference in field: '.$match[1].'.';
            }
            return $prefix.'The live ERP rejected one of the selected native references (vendor, passenger, airline or product service).';
        }

        return $prefix.'The database rejected the native-store write. The underlying SQLSTATE has been written to the Laravel log.';
    }

    private function put(array &$row, array $columns, array $candidates, mixed $value): void
    {
        if ($value === null || $value === '') return;
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $row[$candidate] = $value;
                return;
            }
        }
    }

    private function putAllowEmpty(array &$row, array $columns, array $candidates, mixed $value): void
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $row[$candidate] = $value;
                return;
            }
        }
    }

    /**
     * Write the same semantic value to every installed alias column.
     *
     * Use this only where the aliases are true compatibility duplicates. It is
     * required for native schemas that keep a legacy NOT NULL sibling such as
     * `markup` alongside `customer_service_fee`.
     */
    private function putAllAllowEmpty(array &$row, array $columns, array $candidates, mixed $value): void
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $row[$candidate] = $value;
            }
        }
    }

    private function valueFrom(array $row, array $columns, array $candidates): mixed
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true) && array_key_exists($candidate, $row)) return $row[$candidate];
        }
        return null;
    }

    private function stringFrom(array $row, array $columns, array $candidates): string
    {
        return trim((string) ($this->valueFrom($row, $columns, $candidates) ?? ''));
    }

    private function firstNonEmpty(array $row, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($row[$candidate] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function dateOnly(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return '';
        return substr($raw, 0, 10);
    }

    private function dateTimeLocal(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return '';
        $raw = str_replace(' ', 'T', $raw);
        return substr($raw, 0, 16);
    }

    /** @return array<string,array<string,mixed>> */
    private function columnMetadata(string $table): array
    {
        $result = [];
        $physicalNames = [];

        // Laravel/DBAL is useful as supplemental metadata only. It is not the
        // authority for the live Air write contract because framework schema
        // caches/drivers can omit legacy columns on long-lived installations.
        try {
            foreach (Schema::getColumns($table) as $column) {
                if (! is_array($column)) continue;
                $name = (string) ($column['name'] ?? $column['column_name'] ?? '');
                if ($name !== '') $result[$name] = $column;
            }
        } catch (Throwable) {
        }

        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) return $result;

        // Physical source #1: information_schema. This is independent of
        // Laravel's schema abstraction and gives the exact nullable/default/type
        // contract used by MySQL/MariaDB for the current database.
        try {
            $physical = DB::select(
                'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, COLUMN_TYPE, EXTRA '
                .'FROM information_schema.COLUMNS '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$table]
            );
            foreach ($physical as $column) {
                $raw = (array) $column;
                $name = (string) ($raw['COLUMN_NAME'] ?? $raw['column_name'] ?? '');
                if ($name === '') continue;
                $fallback = [
                    'name' => $name,
                    'type' => strtolower((string) ($raw['COLUMN_TYPE'] ?? $raw['column_type'] ?? $raw['DATA_TYPE'] ?? $raw['data_type'] ?? '')),
                    'type_name' => strtolower((string) ($raw['COLUMN_TYPE'] ?? $raw['column_type'] ?? $raw['DATA_TYPE'] ?? $raw['data_type'] ?? '')),
                    'data_type' => strtolower((string) ($raw['DATA_TYPE'] ?? $raw['data_type'] ?? '')),
                    'nullable' => strtoupper((string) ($raw['IS_NULLABLE'] ?? $raw['is_nullable'] ?? 'NO')) === 'YES',
                    'default' => $raw['COLUMN_DEFAULT'] ?? $raw['column_default'] ?? null,
                    'extra' => strtolower((string) ($raw['EXTRA'] ?? $raw['extra'] ?? '')),
                    'auto_increment' => str_contains(strtolower((string) ($raw['EXTRA'] ?? $raw['extra'] ?? '')), 'auto_increment'),
                    'physical_source' => 'information_schema',
                ];
                $result[$name] = array_merge($result[$name] ?? [], $fallback);
                $physicalNames[$name] = true;
            }
        } catch (Throwable) {
        }

        // Physical source #2/fallback: SHOW FULL COLUMNS. Give it final
        // precedence where available. Between these two physical sources the
        // preflight no longer silently falls back to an incomplete DBAL list.
        try {
            foreach (DB::select('SHOW FULL COLUMNS FROM `'.$table.'`') as $column) {
                $raw = (array) $column;
                $name = (string) ($raw['Field'] ?? $raw['field'] ?? '');
                if ($name === '') continue;
                $fallback = [
                    'name' => $name,
                    'type' => strtolower((string) ($raw['Type'] ?? $raw['type'] ?? '')),
                    'type_name' => strtolower((string) ($raw['Type'] ?? $raw['type'] ?? '')),
                    'nullable' => strtoupper((string) ($raw['Null'] ?? $raw['null'] ?? 'NO')) === 'YES',
                    'default' => $raw['Default'] ?? $raw['default'] ?? null,
                    'extra' => strtolower((string) ($raw['Extra'] ?? $raw['extra'] ?? '')),
                    'auto_increment' => str_contains(strtolower((string) ($raw['Extra'] ?? $raw['extra'] ?? '')), 'auto_increment'),
                    'physical_source' => 'show_full_columns',
                ];
                $result[$name] = array_merge($result[$name] ?? [], $fallback);
                $physicalNames[$name] = true;
            }
        } catch (Throwable) {
        }

        // If at least one physical inspection method succeeded, discard any
        // DBAL-only/stale names. The INSERT/preflight contract must exactly match
        // columns the database itself reports.
        if ($physicalNames) {
            return array_intersect_key($result, $physicalNames);
        }

        return $result;
    }

    /** @return list<string> */
    private function physicalColumnListing(string $table): array
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
        $names = [];
        try {
            $rows = DB::select(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$table]
            );
            foreach ($rows as $row) {
                $raw = (array) $row;
                $name = (string) ($raw['COLUMN_NAME'] ?? $raw['column_name'] ?? '');
                if ($name !== '') $names[$name] = true;
            }
        } catch (Throwable) {
        }
        try {
            foreach (DB::select('SHOW FULL COLUMNS FROM `'.$table.'`') as $row) {
                $raw = (array) $row;
                $name = (string) ($raw['Field'] ?? $raw['field'] ?? '');
                if ($name !== '') $names[$name] = true;
            }
        } catch (Throwable) {
        }
        return array_values(array_keys($names));
    }

    private function assertRequiredNativeContract(string $table, array $row): void
    {
        $metadata = $this->columnMetadata($table);
        if (!$metadata) return;

        $missing = [];
        foreach ($metadata as $field => $meta) {
            if ($this->columnCanBeOmitted($field, $meta)) continue;
            if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                $missing[] = $field;
            }
        }

        if ($missing) {
            throw ValidationException::withMessages([
                'air' => 'Native Air preflight found unresolved required field(s): '.implode(', ', array_slice($missing, 0, 20)).'. No Air data was written.',
            ]);
        }
    }

    private function columnCanBeOmitted(string $field, array $meta): bool
    {
        if ($field === 'id') return true;
        if ((bool) ($meta['nullable'] ?? $meta['is_nullable'] ?? false)) return true;
        if (array_key_exists('default', $meta) && $meta['default'] !== null) return true;
        if (array_key_exists('default_value', $meta) && $meta['default_value'] !== null) return true;
        if ((bool) ($meta['auto_increment'] ?? false)) return true;
        $extra = strtolower((string) ($meta['extra'] ?? ''));
        if (str_contains($extra, 'auto_increment')) return true;
        return false;
    }

    private function metaType(array $meta): string
    {
        $type = strtolower(trim((string) ($meta['type_name'] ?? $meta['type'] ?? $meta['data_type'] ?? '')));
        if (preg_match('/^([a-z]+)/', $type, $match)) return (string) $match[1];
        return $type;
    }
}
