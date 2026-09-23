<?php

namespace App\Services\Reports;

use App\Services\Operations\ActiveBookingPassengerResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, operational Travel Reports authority. No monetary fields are exposed. */
final class TravelReportService
{
    /** Operational authorities: bookings; booking_passengers; booking_services,
     * booking_itinerary_segments and air_ticket_details; native hotel stays;
     * booking_visa_services; booking_transport_segments; Group Umrah unified
     * tables. Air rows are Ticket Group rows. Financial authority remains outside this service. */
    public function __construct(private readonly ActiveBookingPassengerResolver $passengers) {}
    public const REPORTS = [
        'bookings'=>'Booking Report','passengers'=>'Passenger Report','air'=>'Air / Ticketing Report',
        'hotels'=>'Hotel Report','visas'=>'Visa Report','transport'=>'Transport Report',
        'group-umrah'=>'Group Umrah Report','customers'=>'Customer-wise Report','suppliers'=>'Supplier / Vendor-wise Report',
        'branches'=>'Branch-wise Report','agents'=>'Agent / Salesperson Report','airlines'=>'Airline-wise Report','sectors'=>'Sector / Destination Report',
    ];
    public const MOVEMENTS = [
        'arrival'=>'Arrival Intimation','makkah-checkin'=>'Makkah Check-in','makkah-checkout'=>'Makkah Check-out',
        'madinah-checkin'=>'Madinah Check-in','madinah-checkout'=>'Madinah Check-out','departure'=>'Departure Intimation',
    ];

    public function definition(string $key): array
    {
        $title = self::REPORTS[$key] ?? self::MOVEMENTS[$key] ?? 'Travel Report';
        return ['key'=>$key,'title'=>$title,'description'=>'Operational travel activity; no financial data.','columns'=>$this->columns($key)];
    }

    public function rows(string $key, array $filters = [], int $perPage = 50)
    {
        $table = $this->tableFor($key);
        if (!$table || ! Schema::hasTable($table)) return collect();
        $columns = Schema::getColumnListing($table);
        $select = array_values(array_intersect($this->sourceColumns($key), $columns));
        if (!$select) $select = ['id'];
        $query = DB::table($table)->select($select);
        if ($key === 'passengers') {
            if (in_array('deleted_at',$columns,true)) $query->whereNull('deleted_at');
            if (in_array('is_active',$columns,true)) $query->where('is_active',true);
            if (in_array('active',$columns,true)) $query->where('active',true);
            if (in_array('status',$columns,true)) $query->whereNotIn('status',['inactive','deleted','removed','cancelled','canceled']);
        }
        $date = $this->firstColumn($columns, ['booking_date','travel_date','departure_date','created_at']);
        if ($date && !empty($filters['from'])) $query->whereDate($date, '>=', $filters['from']);
        if ($date && !empty($filters['to'])) $query->whereDate($date, '<=', $filters['to']);
        if ($this->firstColumn($columns, ['status']) && !empty($filters['status'])) $query->where($this->firstColumn($columns,['status']), $filters['status']);
        return $query->orderByDesc($this->firstColumn($columns,['id']) ?: $select[0])->paginate(max(25,min(100,$perPage)))->withQueryString();
    }

    public function counts(): array
    {
        $map=['bookings'=>'bookings','passengers'=>null,'air'=>'booking_services','hotels'=>null,'visas'=>'booking_visa_services','transport'=>'booking_transport_segments','group-umrah'=>'booking_group_package_unified'];
        $out=[]; foreach($map as $key=>$table) $out[$key]=($table && Schema::hasTable($table)) ? DB::table($table)->count() : 0;
        return $out;
    }

    public function exportRows(string $key, array $filters = []): iterable
    {
        $rows=$this->rows($key,$filters,100); return $rows instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $rows->items() : $rows;
    }

    private function tableFor(string $key): ?string
    {
        return match($key){
            'bookings','customers','branches','agents','suppliers','sectors'=> 'bookings',
            'passengers'=>Schema::hasTable('booking_passengers')?'booking_passengers':(Schema::hasTable('booking_travellers')?'booking_travellers':'booking_travelers'),
            'air','airlines'=>Schema::hasTable('booking_services')?'booking_services':null,
            'hotels'=>collect(['booking_hotel_stays','booking_hotels','hotel_stays','booking_hotel_details','booking_accommodations','hotel_booking_details'])->first(fn($t)=>Schema::hasTable($t)),
            'visas'=>'booking_visa_services', 'transport'=>collect(['booking_transport_segments','booking_transports','transport_booking_details','booking_transport_details'])->first(fn($t)=>Schema::hasTable($t)),
            'group-umrah'=>'booking_group_package_unified', default=>null,
        };
    }
    private function sourceColumns(string $key): array
    {
        return ['id','booking_id','booking_reference','booking_date','travel_date','departure_date','customer_id','customer_name','supplier_id','vendor_id','vendor_name','branch_id','agent_name','salesperson_name','status','first_name','last_name','title','gender','passport_no','nationality','date_of_birth','airline','airline_code','flight_number','from','to','segment_type','pnr','airline_pnr','booking_source','ticket_status','issue_date','city','hotel_name','confirmation_no','room_type','board','check_in','check_out','nights','visa_type','visa_number','expiry_date','route_name','vehicle_type','company_name','package_name','package_code','sort_order'];
    }
    private function columns(string $key): array
    {
        return match($key){
            'bookings'=>['Booking No.','Booking Date','Travel Date','Customer','Product(s)','Total Pax','Adult','Child','Infant','Sector / Route','Departure','Arrival','Check-in','Check-out','Nights','Status','Branch','Agent','Salesperson','Action / View'],
            'passengers'=>['Booking No.','Booking Date','Travel Date','Customer','Passenger Name','Gender / Title','Pax Type','Passport No.','Nationality','DOB','Product','Status','Branch','Agent / Salesperson','Action'],
            'air','airlines'=>['Booking No.','Customer','Airline','Sector / Segments','Departure','Arrival','PNR','Airline PNR','GDS / Source','Ticket Status','Issue Date','Passenger Count','Ticket Numbers','Vendor','Branch','Action'],
            'hotels'=>['Booking No.','Customer','City','Hotel','Vendor','Confirmation No.','Room Type','Board','Check-in','Check-out','Nights','Passenger Count','Status','Branch','Action'],
            'visas'=>['Booking No.','Customer','Passenger','Passport No.','Visa Type','Saudi Company','Pakistani IATA','Vendor / Company','Visa No.','Issue Date','Expiry Date','Status','Branch','Action'],
            'transport'=>['Booking No.','Customer','Travel Date','Route','Vehicle Type','Transport Company / Vendor','Passenger Count','Status','Branch','Action'],
            'group-umrah'=>['Booking No.','Booking Date','Departure Date','Return / Arrival Date','Customer','Package','Total Pax','Adult','Child','Infant','Flight / Sector','Makkah Hotel','Makkah Check-in','Makkah Check-out','Madinah Hotel','Madinah Check-in','Madinah Check-out','Transport','Saudi Company','Pakistani IATA','Status','Branch','Agent','Salesperson','Action'],
            default=>['Name','Bookings','Passengers','Air','Hotel','Visa','Transport','Group Umrah','Latest Booking Date','Latest Travel Date','Branch(es)','View'],
        };
    }
    private function firstColumn(array $columns,array $wanted): ?string { foreach($wanted as $w) if(in_array($w,$columns,true)) return $w; return null; }
}
