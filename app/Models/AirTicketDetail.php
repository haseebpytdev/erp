<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class AirTicketDetail extends Model
{
    protected $fillable = [
        'booking_service_id','booking_passenger_id','pnr','ticket_number','ticket_status','airline_id','airline_name_snapshot','airline_iata_snapshot','airline_icao_snapshot','airline_accounting_prefix_snapshot','booking_source_id','booking_source_name_snapshot','booking_source_type_snapshot',
        'origin','destination','sector','flight_number','departure_at','arrival_at','cabin_class','fare_basis','baggage',
        'base_fare','airline_taxes','customer_service_fee','markup','discount','selling_total',
        'supplier_vendor_party_id','supplier_base_fare','supplier_taxes','supplier_charges','supplier_commission','supplier_discount',
        'supplier_incentive','supplier_other_charges','net_supplier_cost','supplier_cost_status','supplier_reference',
        'notes','created_by','updated_by',
    ];

    protected function casts(): array
    {
        return [
            'departure_at'=>'datetime','arrival_at'=>'datetime',
            'base_fare'=>'decimal:2','airline_taxes'=>'decimal:2','customer_service_fee'=>'decimal:2','markup'=>'decimal:2','discount'=>'decimal:2','selling_total'=>'decimal:2',
            'supplier_base_fare'=>'decimal:2','supplier_taxes'=>'decimal:2','supplier_charges'=>'decimal:2','supplier_commission'=>'decimal:2',
            'supplier_discount'=>'decimal:2','supplier_incentive'=>'decimal:2','supplier_other_charges'=>'decimal:2','net_supplier_cost'=>'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AirTicketDetail $ticket): void {
            if (!Schema::hasTable('air_ticket_details') || !Schema::hasColumn('air_ticket_details','airline_name_snapshot')) return;

            if ($ticket->airline_id && ($ticket->isDirty('airline_id') || trim((string)$ticket->airline_name_snapshot)==='')) {
                $airline=Airline::query()->find($ticket->airline_id);
                if ($airline) {
                    $ticket->airline_name_snapshot=$airline->name;
                    $ticket->airline_iata_snapshot=$airline->iata_code;
                    $ticket->airline_icao_snapshot=$airline->icao_code;
                    $ticket->airline_accounting_prefix_snapshot=Schema::hasColumn('airlines','accounting_prefix') ? $airline->accounting_prefix : null;
                }
            }

            if ($ticket->booking_source_id && ($ticket->isDirty('booking_source_id') || trim((string)$ticket->booking_source_name_snapshot)==='')) {
                $source=BookingSource::query()->find($ticket->booking_source_id);
                if ($source) {
                    $ticket->booking_source_name_snapshot=$source->name;
                    $ticket->booking_source_type_snapshot=$source->source_type;
                }
            }
        });
    }

    public function passenger(): BelongsTo { return $this->belongsTo(BookingPassenger::class,'booking_passenger_id'); }
    public function airline(): BelongsTo { return $this->belongsTo(Airline::class); }
    public function source(): BelongsTo { return $this->belongsTo(BookingSource::class,'booking_source_id'); }
    public function supplierVendor(): BelongsTo { return $this->belongsTo(Party::class,'supplier_vendor_party_id'); }
    public function commissions(): HasMany { return $this->hasMany(CommissionAllocation::class); }
}
