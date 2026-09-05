<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnifiedGroupPackageLegacyBridge
{
    public function sync(int $bookingId, array $data, $now): void
    {
        $this->syncPassengers($bookingId, $data['passengers'] ?? [], $now);
        $this->syncFlights($bookingId, $data['flights'] ?? [], $now);
        $this->syncHotels($bookingId, $data['hotels'] ?? [], $now);
        $this->syncTransport($bookingId, $data['transports'] ?? [], count($data['passengers'] ?? []), $now);
    }

    private function syncPassengers(int $bookingId, array $passengers, $now): void
    {
        if (! Schema::hasTable('booking_passengers')) {
            return;
        }

        $columns = Schema::getColumnListing('booking_passengers');
        if (! in_array('booking_id', $columns, true)) {
            return;
        }

        try {
            DB::table('booking_passengers')->where('booking_id', $bookingId)->delete();

            foreach ($passengers as $i => $row) {
                $insert = [];
                $this->put($insert, $columns, ['booking_id'], $bookingId);
                $this->put($insert, $columns, ['passenger_id', 'master_passenger_id'], $row['passenger_id'] ?? null);
                $this->put($insert, $columns, ['title', 'salutation'], $row['title'] ?? null);
                $this->put($insert, $columns, ['first_name', 'given_name'], $row['first_name'] ?? null);
                $this->put($insert, $columns, ['last_name', 'surname', 'family_name'], $row['last_name'] ?? null);
                $this->put($insert, $columns, ['name', 'passenger_name'], trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                $this->put($insert, $columns, ['date_of_birth', 'dob', 'birth_date'], $row['date_of_birth'] ?? null);
                $this->put($insert, $columns, ['passport_no', 'passport_number'], $row['passport_no'] ?? null);
                $this->put($insert, $columns, ['passport_expiry', 'passport_expiry_date'], $row['passport_expiry'] ?? null);
                $this->put($insert, $columns, ['nationality', 'nationality_name', 'country'], $row['nationality'] ?? null);
                $this->put($insert, $columns, ['fare_as', 'age_type', 'passenger_type', 'pax_type'], $row['fare_as'] ?? null);
                $this->put($insert, $columns, ['ticket_number', 'ticket_no'], $row['ticket_number'] ?? null);
                $this->put($insert, $columns, ['sort_order', 'sequence', 'sequence_no'], ($i + 1) * 10);
                $this->put($insert, $columns, ['status'], 'active');
                if (in_array('is_active', $columns, true)) {
                    $insert['is_active'] = 1;
                }
                if (in_array('created_at', $columns, true)) {
                    $insert['created_at'] = $now;
                }
                if (in_array('updated_at', $columns, true)) {
                    $insert['updated_at'] = $now;
                }

                if ($insert) {
                    DB::table('booking_passengers')->insert($insert);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function syncFlights(int $bookingId, array $flights, $now): void
    {
        if (! Schema::hasTable('booking_itinerary_segments')) {
            return;
        }

        try {
            DB::table('booking_itinerary_segments')->where('booking_id', $bookingId)->delete();

            foreach ($flights as $i => $row) {
                $departure = trim(($row['departure_date'] ?? '') . ' ' . ($row['departure_time'] ?? '00:00'));
                $arrival = ($row['arrival_date'] ?? null)
                    ? trim($row['arrival_date'] . ' ' . ($row['arrival_time'] ?? '00:00'))
                    : null;

                DB::table('booking_itinerary_segments')->insert([
                    'booking_id' => $bookingId,
                    'segment_type' => ($row['segment_type'] ?? '') === 'inbound' ? 'return' : ($row['segment_type'] ?? 'outbound'),
                    'from_code' => strtoupper((string) ($row['from_code'] ?? '')),
                    'to_code' => strtoupper((string) ($row['to_code'] ?? '')),
                    'airline_name' => $row['airline_name'] ?? null,
                    'flight_number' => $row['flight_number'] ?? null,
                    'departure_at' => $departure,
                    'arrival_at' => $arrival,
                    'pnr' => $row['pnr'] ?? null,
                    'cabin' => $row['cabin_class'] ?? null,
                    'baggage' => $row['baggage'] ?? null,
                    'status' => $row['status'] ?? 'booked',
                    'sort_order' => ($i + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function syncHotels(int $bookingId, array $hotels, $now): void
    {
        $table = $this->firstCompatibleTable(
            ['booking_hotel_stays', 'booking_hotels', 'hotel_stays'],
            ['booking_id']
        );
        if (! $table) {
            return;
        }

        $columns = Schema::getColumnListing($table);

        try {
            DB::table($table)->where('booking_id', $bookingId)->delete();

            foreach ($hotels as $i => $row) {
                $insert = [];
                $this->put($insert, $columns, ['booking_id'], $bookingId);
                $this->put($insert, $columns, ['hotel_id'], $row['hotel_id'] ?? null);
                $this->put($insert, $columns, ['city', 'city_name'], $row['city'] ?? null);
                $this->put($insert, $columns, ['hotel_name', 'name'], $row['hotel_name'] ?? null);
                $this->put($insert, $columns, ['check_in', 'check_in_date'], $row['check_in'] ?? null);
                $this->put($insert, $columns, ['check_out', 'check_out_date'], $row['check_out'] ?? null);
                $this->put($insert, $columns, ['nights', 'total_nights'], $row['nights'] ?? null);
                $this->put($insert, $columns, ['room_type'], $row['room_type'] ?? null);
                $this->put($insert, $columns, ['meal_plan', 'meal'], $row['meal_plan'] ?? null);
                $this->put($insert, $columns, ['rooms', 'room_count', 'number_of_rooms'], $row['rooms'] ?? 1);
                $this->put($insert, $columns, ['confirmation_no', 'voucher_no', 'confirmation_number'], $row['confirmation_no'] ?? null);
                $this->put($insert, $columns, ['status'], $row['status'] ?? 'requested');
                $this->put($insert, $columns, ['notes', 'remarks', 'special_requests'], $row['notes'] ?? null);
                $this->put($insert, $columns, ['sort_order', 'sequence'], ($i + 1) * 10);

                // Group Package hotel rows are operational only.
                foreach (['customer_price', 'sale_amount', 'supplier_cost', 'supplier_amount', 'agent_commission', 'salesperson_commission', 'net_margin'] as $commercialColumn) {
                    if (in_array($commercialColumn, $columns, true)) {
                        $insert[$commercialColumn] = 0;
                    }
                }
                if (in_array('commercial_locked', $columns, true)) {
                    $insert['commercial_locked'] = 1;
                }
                if (in_array('created_at', $columns, true)) {
                    $insert['created_at'] = $now;
                }
                if (in_array('updated_at', $columns, true)) {
                    $insert['updated_at'] = $now;
                }

                if ($insert) {
                    DB::table($table)->insert($insert);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function syncTransport(int $bookingId, array $transports, int $paxCount, $now): void
    {
        if (!Schema::hasTable('booking_transport_segments')) return;
        $columns=Schema::getColumnListing('booking_transport_segments');
        try {
            DB::table('booking_transport_segments')->where('booking_id',$bookingId)->delete();
            foreach ($transports as $i=>$row) {
                $insert=[]; $route=$row['route_name'] ?? trim((string)($row['from_location'] ?? '').(((string)($row['from_location'] ?? '')!=='' && (string)($row['to_location'] ?? '')!=='')?' → ':'').(string)($row['to_location'] ?? ''));
                $this->put($insert,$columns,['booking_id'],$bookingId); $this->put($insert,$columns,['service_mode'],'package');
                if (($row['route_source_table'] ?? null) === 'transport_rate_cards') {
                    $this->put($insert, $columns, ['rate_card_id'], $row['route_master_id'] ?? null);
                }
                $this->put($insert,$columns,['transport_date','pickup_date'],null); $this->put($insert,$columns,['pickup_time'],null);
                $this->put($insert,$columns,['pickup_location','from_location'],$row['from_location'] ?? null); $this->put($insert,$columns,['dropoff_location','to_location'],$row['to_location'] ?? null); $this->put($insert,$columns,['route_label','route_name','route'],$route);
                $this->put($insert,$columns,['vehicle_type'],$row['vehicle_type'] ?? null); $this->put($insert,$columns,['company_name','provider_name','transport_company'],$row['company_name'] ?? null); $this->put($insert,$columns,['contact_number','provider_contact','driver_contact','phone'],$row['contact_number'] ?? null);
                $this->put($insert,$columns,['provider_reference','brn_number','brn','reference'],$row['brn_number'] ?? ($row['provider_reference'] ?? null)); $this->put($insert,$columns,['passengers_count','pax_count'],max(1,$paxCount)); $this->put($insert,$columns,['status'],'requested'); $this->put($insert,$columns,['voucher_notes','notes','remarks'],$row['notes'] ?? null); $this->put($insert,$columns,['sort_order','sequence'],($i+1)*10);
                foreach(['sale_amount','supplier_amount','supplier_amount_pkr','customer_price','supplier_cost','agent_commission','salesperson_commission','net_margin'] as $cc) if(in_array($cc,$columns,true)) $insert[$cc]=0;
                if(in_array('sale_currency_code',$columns,true)) $insert['sale_currency_code']='PKR'; if(in_array('supplier_currency_code',$columns,true)) $insert['supplier_currency_code']='PKR'; if(in_array('commercial_locked',$columns,true)) $insert['commercial_locked']=1; if(in_array('created_at',$columns,true)) $insert['created_at']=$now; if(in_array('updated_at',$columns,true)) $insert['updated_at']=$now;
                if($insert) DB::table('booking_transport_segments')->insert($insert);
            }
        } catch (\Throwable $e) { report($e); }
    }

    private function firstCompatibleTable(array $tables, array $requiredColumns): ?string
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            if (! array_diff($requiredColumns, $columns)) {
                return $table;
            }
        }

        return null;
    }

    private function put(array &$row, array $columns, array $candidates, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                $row[$column] = $value;
                return;
            }
        }
    }
}
