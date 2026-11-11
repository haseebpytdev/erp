<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class OperationalAmendmentService
{
    private const ALLOWED = [
        'hotel' => ['hotel_name','room_type','check_in','check_out','confirmation_no','confirmation_number','city','board'],
        'air' => ['departure_date','departure_time','departure_at','arrival_date','arrival_time','arrival_at','flight_number','pnr','airline_name','airline_code','ticket_number'],
        'transport' => ['company_name','transport_company_id','route_name','from_location','to_location','vehicle_type','pickup_date','pickup_time','provider_reference','driver_name','driver_cell','plate_number'],
        'visa' => ['visa_type','application_reference','status','issue_date','expiry_date','confirmation_reference'],
        'booking' => ['notes'],
    ];

    public function validatePayload(mixed $user, string $reason, array $changes): void
    {
        if (! app(GroupUmrahEditAuthority::class)->canReopen($user)) {
            throw ValidationException::withMessages(['authorization' => 'Only an Administrator may apply operational amendments.']);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'A meaningful amendment reason is required.']);
        }
        if ($changes === []) throw ValidationException::withMessages(['changes' => 'At least one operational field is required.']);
        foreach ($changes as $change) {
            $section = strtolower(trim((string) ($change['section'] ?? '')));
            $field = strtolower(trim((string) ($change['field'] ?? '')));
            if (! isset(self::ALLOWED[$section]) || ! in_array($field, self::ALLOWED[$section], true)) {
                throw ValidationException::withMessages(['changes' => 'Commercial, accounting or unsupported operational fields are not allowed.']);
            }
        }
    }

    public function apply(int $bookingId, mixed $user, string $reason, array $changes, callable $mutator): int
    {
        $this->validatePayload($user, $reason, $changes);
        return DB::transaction(function () use ($bookingId, $user, $reason, $changes, $mutator): int {
            if (! Schema::hasTable('booking_operational_amendments') || ! Schema::hasTable('booking_operational_amendment_details')) {
                throw ValidationException::withMessages(['schema' => 'Operational amendment audit schema is not installed.']);
            }
            $before = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            if (! $before) throw ValidationException::withMessages(['booking' => 'Booking was not found.']);
            foreach ($changes as &$change) {
                if (array_key_exists('old_value', $change) && $change['old_value'] !== null) continue;
                $section = strtolower((string) ($change['section'] ?? ''));
                $field = strtolower((string) ($change['field'] ?? ''));
                if ($section === 'booking' && $field === 'notes') { $change['old_value'] = $before->notes ?? null; continue; }
                $recordId = (int) ($change['record_id'] ?? 0);
                if ($recordId <= 0) continue;
                $table = $section === 'hotel' ? $this->resolveTable(['booking_hotel_stays','booking_hotels','hotel_stays','booking_hotel_details','booking_accommodations','hotel_booking_details'], ['hotel_name','name','hotel_id'])
                    : ($section === 'air' ? $this->resolveTable($field === 'ticket_number' ? ['air_ticket_details'] : ['booking_itinerary_segments'], ['booking_id','booking_service_id'])
                    : ($section === 'transport' ? $this->resolveTable(['booking_transport_segments','booking_transports','transport_booking_details','booking_transport_details'], ['route_name','route_label','route','from_location'])
                    : ($section === 'visa' ? $this->resolveTable(['booking_visa_services'], ['booking_passenger_id','visa_type','application_reference']) : null)));
                $map = $section === 'hotel' ? $this->hotelFieldMap() : ($section === 'air' ? $this->airFieldMap() : ($section === 'transport' ? $this->transportFieldMap() : $this->visaFieldMap()));
                if ($table && isset($map[$field])) {
                    $cols = Schema::getColumnListing($table); $col = collect($map[$field])->first(fn (string $c): bool => in_array($c, $cols, true));
                    if ($col) {
                        $q = DB::table($table)->where('id', $recordId); if (in_array('booking_id', $cols, true)) $q->where('booking_id', $bookingId); elseif (in_array('booking_service_id', $cols, true)) $q->whereIn('booking_service_id', DB::table('booking_services')->where('booking_id', $bookingId)->pluck('id')->all());
                        $change['old_value'] = optional($q->first())->{$col};
                    }
                }
            }
            unset($change);
            $after = $mutator($before, $changes);
            $amendment = DB::table('booking_operational_amendments')->insertGetId([
                'booking_id' => $bookingId,
                'administrator_id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
                'reason' => trim($reason),
                'approval_status_before' => $before->status ?? null,
                'approval_status_after' => $after['approval_status_after'] ?? ($before->status ?? null),
                'travel_status_before' => $before->travel_status ?? null,
                'travel_status_after' => $after['travel_status_after'] ?? 'PendingTravel',
                'voucher_number_snapshot' => $before->voucher_no ?? $before->voucher_number ?? null,
                'status' => 'applied',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($changes as $change) {
                DB::table('booking_operational_amendment_details')->insert([
                    'amendment_id' => $amendment,
                    'section' => strtolower((string) $change['section']),
                    'field' => strtolower((string) $change['field']),
                    'old_value' => isset($change['old_value']) ? json_encode($change['old_value']) : null,
                    'new_value' => isset($change['new_value']) ? json_encode($change['new_value']) : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return (int) $amendment;
        });
    }

    /**
     * Apply only operational fields to an existing native row.  This adapter
     * deliberately refuses to create/delete rows and never writes commercial
     * columns (sale, cost, price, totals, vendor or accounting fields).
     */
    public function applyNativeOperationalChanges(int $bookingId, array $changes): void
    {
        foreach ($changes as $change) {
            $section = strtolower(trim((string) ($change['section'] ?? '')));
            if ($section === 'booking') continue;
            $recordId = (int) ($change['record_id'] ?? 0);
            if ($recordId <= 0) {
                throw ValidationException::withMessages(['changes' => 'A native record_id is required for '.$section.' operational amendments.']);
            }
            $field = strtolower(trim((string) ($change['field'] ?? '')));
            $value = $change['new_value'] ?? null;
            if ($section === 'hotel') {
                $this->updateExistingNativeRow($bookingId, $recordId, $this->resolveTable(['hotel_booking_details','booking_hotel_stays','booking_hotels','hotel_stays','booking_hotel_details','booking_accommodations'], ['hotel_name_snapshot','hotel_name','name','hotel_id']), $field, $value, $this->hotelFieldMap());
            } elseif ($section === 'air') {
                $tables = $field === 'ticket_number' ? ['air_ticket_details'] : ['booking_itinerary_segments'];
                $this->updateExistingNativeRow($bookingId, $recordId, $this->resolveTable($tables, ['booking_id','booking_service_id']), $field, $value, $this->airFieldMap());
            } elseif ($section === 'transport') {
                $transportTables = in_array($field, ['company_name','transport_company_id'], true)
                    ? ['transport_booking_details','booking_transport_details','booking_transport_segments','booking_transports']
                    : ['booking_transport_segments','booking_transports','transport_booking_details','booking_transport_details'];
                $this->updateExistingNativeRow($bookingId, $recordId, $this->resolveTable($transportTables, ['route_name','route_label','route','from_location','transport_company_id','transport_company_snapshot']), $field, $value, $this->transportFieldMap());
            } elseif ($section === 'visa') {
                $this->updateExistingNativeRow($bookingId, $recordId, $this->resolveTable(['booking_visa_services'], ['booking_passenger_id','visa_type','application_reference']), $field, $value, $this->visaFieldMap());
            }
        }
    }

    private function updateExistingNativeRow(int $bookingId, int $recordId, ?string $table, string $field, mixed $value, array $map): void
    {
        if ($table === null || ! Schema::hasTable($table)) {
            throw ValidationException::withMessages(['changes' => 'The native '.$table.' operational store is unavailable; amendment was not applied.']);
        }
        $columns = Schema::getColumnListing($table);
        if (! in_array('id', $columns, true) || (! in_array('booking_id', $columns, true) && ! in_array('booking_service_id', $columns, true))) throw ValidationException::withMessages(['changes' => 'The native '.$table.' store has no durable booking ownership key.']);
        $column = null;
        foreach (($map[$field] ?? []) as $candidate) if (in_array($candidate, $columns, true)) { $column = $candidate; break; }
        if ($column === null) throw ValidationException::withMessages(['changes' => 'The native '.$table.' store does not support operational field '.$field.'.']);
        $query = DB::table($table)->where('id', $recordId);
        if (in_array('booking_id', $columns, true)) $query->where('booking_id', $bookingId);
        else {
            $serviceIds = Schema::hasTable('booking_services') ? DB::table('booking_services')->where('booking_id', $bookingId)->pluck('id')->all() : [];
            if (! $serviceIds) throw ValidationException::withMessages(['changes' => 'No native booking service ownership could be established.']);
            $query->whereIn('booking_service_id', $serviceIds);
        }
        $row = $query->first();
        if (! $row) throw ValidationException::withMessages(['changes' => 'The selected native record does not belong to this booking.']);
        $updateQuery = DB::table($table)->where('id', $recordId);
        if (in_array('booking_id', $columns, true)) $updateQuery->where('booking_id', $bookingId); else $updateQuery->whereIn('booking_service_id', $serviceIds);
        if (in_array($field, ['departure_date','departure_time','arrival_date','arrival_time'], true) && in_array($column, ['departure_at','arrival_at'], true)) {
            $current = (string) ($row->{$column} ?? '');
            $stamp = strtotime($current) ?: time();
            $date = date('Y-m-d', $stamp); $time = date('H:i:s', $stamp);
            if (str_ends_with($field, '_date')) $date = (string) $value; else $time = strlen((string) $value) === 5 ? ((string) $value).':00' : (string) $value;
            $value = $date.' '.$time;
        }
        $update = [$column => $value]; if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        $updateQuery->update($update);
    }

    private function resolveTable(array $candidates, array $markers): ?string
    {
        foreach ($candidates as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table);
            if (collect($markers)->some(fn (string $m): bool => in_array($m, $columns, true))) return $table;
        }
        return null;
    }

    private function hotelFieldMap(): array { return [
        'hotel_name'=>['hotel_name','hotel_name_snapshot','name'],'room_type'=>['room_type','room'],'check_in'=>['check_in','check_in_date'],'check_out'=>['check_out','check_out_date'],
        'confirmation_no'=>['confirmation_no','confirmation_number','booking_reference','reference_no'],'confirmation_number'=>['confirmation_number','confirmation_no'],'city'=>['city','city_name','hotel_city_snapshot'],'board'=>['board','meal_plan'],
    ]; }
    private function airFieldMap(): array { return [
        'departure_date'=>['departure_date','departure_at'],'departure_time'=>['departure_time','departure_at'],'departure_at'=>['departure_at'],'arrival_date'=>['arrival_date','arrival_at'],'arrival_time'=>['arrival_time','arrival_at'],'arrival_at'=>['arrival_at'],'flight_number'=>['flight_number','flight_no'],'pnr'=>['pnr','record_locator'],'airline_name'=>['airline_name','airline','airline_name_snapshot'],'airline_code'=>['airline_code','iata_code','airline_iata_snapshot'],'ticket_number'=>['ticket_number','ticket_no','e_ticket_number','eticket_number','document_number','document_no'],
    ]; }
    private function transportFieldMap(): array { return [
        'company_name'=>['company_name','transport_company','transport_company_snapshot','provider_name'],'transport_company_id'=>['transport_company_id','company_id'],'route_name'=>['route_name','route_label','route','route_snapshot'],'from_location'=>['from_location','pickup_location','origin'],'to_location'=>['to_location','dropoff_location','destination'],'vehicle_type'=>['vehicle_type','vehicle','vehicle_type_snapshot'],'pickup_date'=>['pickup_date','travel_date','transport_date','date'],'pickup_time'=>['pickup_time','travel_time','time'],'provider_reference'=>['provider_reference','brn_number','brn','reference'],'driver_name'=>['driver_name'],'driver_cell'=>['driver_cell','driver_phone','driver_contact','driver_mobile'],'plate_number'=>['plate_number','vehicle_number','registration_no'],
    ]; }
    private function visaFieldMap(): array { return [
        'visa_type'=>['visa_type'],'application_reference'=>['application_reference','application_no','reference_no'],'status'=>['status','visa_status'],'issue_date'=>['issue_date','issued_at'],'expiry_date'=>['expiry_date','expires_at'],'confirmation_reference'=>['confirmation_reference','confirmation_no','reference_no'],
    ]; }
}
