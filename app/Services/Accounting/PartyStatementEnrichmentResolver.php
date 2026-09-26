<?php

namespace App\Services\Accounting;

use App\Services\Operations\ActiveBookingPassengerResolver;
use App\Services\Operations\NativeProductServiceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Read-only, cached business-context projection for a journal row. */
final class PartyStatementEnrichmentResolver
{
    private array $sourceCache = [];
    private array $bookingCache = [];
    private array $productCache = [];
    private array $passengerCache = [];

    public function __construct(
        private readonly ActiveBookingPassengerResolver $passengers,
        private readonly NativeProductServiceResolver $products,
    ) {}

    /** Returns display metadata only; journal identity and amounts are untouched. */
    public function resolve(array $row): array
    {
        $sourceType = strtolower(trim((string) ($row['source_type'] ?? '')));
        $sourceId = (int) ($row['source_id'] ?? 0);
        $source = $this->source($sourceType, $sourceId);
        $bookingId = (int) ($source['booking_id'] ?? 0);
        $booking = $this->booking($bookingId);
        $bookingNo = $booking['number'] ?? ($bookingId > 0 ? 'Booking #'.$bookingId : '—');
        $product = $this->product($bookingId, $sourceType, $source);
        $party = $this->party($bookingId);
        $serviceRef = $this->serviceRef($bookingId, $product, $source);
        $description = $this->description($product, $bookingNo, $source, (string) ($row['reference'] ?? ''));
        return [
            'booking_no' => $bookingNo,
            'product' => $product,
            'party' => $party,
            'service_ref' => $serviceRef,
            'description' => $description,
        ];
    }

    private function source(string $type, int $id): array
    {
        $key = $type.'#'.$id;
        if (array_key_exists($key, $this->sourceCache)) return $this->sourceCache[$key];
        if ($id <= 0) return $this->sourceCache[$key] = [];
        $tables = str_contains($type, 'invoice') ? ['sales_invoices','sales_invoice_headers','invoices']
            : (str_contains($type, 'supplier_cost') ? ['supplier_costings']
            : (str_contains($type, 'advance_adjust') ? ['advance_adjustments'] : ['cash_vouchers']));
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = Schema::getColumnListing($table);
                $row = DB::table($table)->where('id', $id)->first();
                if (! $row) continue;
                $a = (array) $row;
                $booking = $this->first($a, $columns, ['booking_id']);
                $bookingId = (int) ($booking ?? 0);
                // Cash vouchers and reversals may point to a booking indirectly
                // through their persisted target/allocation document.
                if ($bookingId <= 0 && isset($a['target_type'], $a['target_id'])) {
                    $targetTable = str_contains(strtolower((string) $a['target_type']), 'supplier') ? 'supplier_costings' : (str_contains(strtolower((string) $a['target_type']), 'advance') ? 'advance_adjustments' : 'sales_invoices');
                    if (Schema::hasTable($targetTable)) try { $target = DB::table($targetTable)->where('id', (int) $a['target_id'])->first(); $bookingId = (int) (($target->booking_id ?? 0)); } catch (Throwable) { }
                }
                return $this->sourceCache[$key] = ['table' => $table, 'row' => $a, 'booking_id' => $bookingId];
            } catch (Throwable) { continue; }
        }
        return $this->sourceCache[$key] = [];
    }

    private function booking(int $id): array
    {
        if ($id <= 0) return [];
        if (array_key_exists($id, $this->bookingCache)) return $this->bookingCache[$id];
        foreach (['bookings','booking_headers','travel_bookings'] as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = Schema::getColumnListing($table); $row = DB::table($table)->where('id', $id)->first();
                if ($row) return $this->bookingCache[$id] = ['row' => (array) $row, 'number' => $this->first((array) $row, $columns, ['booking_no','booking_number','booking_ref','booking_reference','reference'])];
            } catch (Throwable) { continue; }
        }
        return $this->bookingCache[$id] = [];
    }

    private function product(int $bookingId, string $sourceType, array $source): string
    {
        if ($bookingId <= 0) {
            return str_contains($sourceType, 'cash') || str_contains($sourceType, 'payment') || str_contains($sourceType, 'receipt') ? 'Cash / Bank' : '—';
        }
        if (isset($this->productCache[$bookingId])) return $this->productCache[$bookingId];
        $names = [];
        if (Schema::hasTable('booking_services')) {
            try {
                $columns = Schema::getColumnListing('booking_services');
                $rows = DB::table('booking_services')->where('booking_id', $bookingId)->get();
                foreach ($rows as $row) {
                    $a = (array) $row; $name = $this->first($a, $columns, ['product_name','service_name','name','category','type']);
                    if ($name) $names[] = trim((string) $name);
                    $id = (int) ($a['product_service_id'] ?? 0);
                    if ($id > 0) $names[] = $this->nativeProductName($id);
                }
            } catch (Throwable) { /* unresolved product remains honest */ }
        }
        foreach (['booking_visa_services' => 'Visa', 'booking_group_umrah_contexts' => 'Umrah', 'booking_group_umrah_services' => 'Umrah'] as $table => $label) {
            if (! Schema::hasTable($table)) continue;
            try { if (DB::table($table)->where('booking_id', $bookingId)->exists()) $names[] = $label; } catch (Throwable) { }
        }
        $names = array_values(array_unique(array_filter($names, static fn ($v) => $v !== '' && $v !== '—')));
        return $this->productCache[$bookingId] = count($names) > 1 ? 'MULTI PRODUCT' : ($names[0] ?? '—');
    }

    private function nativeProductName(int $id): string
    {
        try {
            foreach (['product_services','product_service_master','product_service_masters','travel_product_services','service_products'] as $table) {
                if (! Schema::hasTable($table)) continue;
                $row = DB::table($table)->where('id', $id)->first();
                if ($row) return trim((string) (($row->name ?? null) ?: ($row->title ?? null) ?: ($row->service_name ?? null) ?: '—'));
            }
            // Invoke the native resolver contract so product identity never depends on fixed IDs.
            $this->products->findAir(); $this->products->findHotel(); $this->products->findTransport();
        } catch (Throwable) { }
        return '—';
    }

    private function party(int $bookingId): string
    {
        if ($bookingId <= 0) return '—';
        if (array_key_exists($bookingId, $this->passengerCache)) return $this->passengerCache[$bookingId];
        try {
            $rows = $this->passengers->rows($bookingId);
            $names = $rows->map(function (object $row): string { $a=(array)$row; return trim((string)(($a['name'] ?? '') ?: trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')))); })->filter()->values();
            if ($names->count() === 1) return $this->passengerCache[$bookingId] = (string) $names->first();
            if ($names->count() > 1) return $this->passengerCache[$bookingId] = (string) $names->first().' + '.($names->count()-1);
        } catch (Throwable) { }
        return $this->passengerCache[$bookingId] = '—';
    }

    private function serviceRef(int $bookingId, string $product, array $source): string
    {
        if ($bookingId <= 0) return $this->first((array) ($source['row'] ?? []), array_keys((array) ($source['row'] ?? [])), ['voucher_no','payment_no','receipt_no','reference']) ?? '—';
        $refs=[]; $lower=strtolower($product);
        $candidates = str_contains($lower,'air') ? ['air_ticket_details'] : (str_contains($lower,'hotel') ? ['booking_hotel_stays','booking_hotels','hotel_stays','booking_hotel_details','booking_accommodations','hotel_booking_details'] : (str_contains($lower,'transport') ? ['booking_transport_segments','booking_transports','transport_booking_details','booking_transport_details'] : (str_contains($lower,'visa') ? ['booking_visa_services'] : (str_contains($lower,'umrah') ? ['booking_group_umrah_contexts','booking_group_umrah_services'] : []))));
        foreach ($candidates as $table) { if (! Schema::hasTable($table)) continue; try { $columns=Schema::getColumnListing($table); $key=in_array('booking_id',$columns,true)?'booking_id':null; if(!$key) continue; foreach(DB::table($table)->where($key,$bookingId)->get() as $r){ $v=$this->first((array)$r,$columns,['pnr','ticket_number','ticket_no','e_ticket_number','document_number','confirmation_no','confirmation_number','brn','booking_reference','voucher_no','visa_number','application_reference','reference']); if($v) $refs[]=(string)$v; } } catch(Throwable){} }
        return implode(' · ', array_values(array_unique($refs))) ?: '—';
    }

    private function description(string $product, string $booking, array $source, string $reference): string
    { $row=(array)($source['row']??[]); if($product!=='—') return $product.' · '.$booking; foreach(['description','narration','remarks','memo','notes'] as $k) if(trim((string)($row[$k]??''))!=='') return (string)$row[$k]; return $reference ?: 'Journal'; }
    private function first(array $row, array $columns, array $wanted): mixed { foreach($wanted as $name) if(in_array($name,$columns,true) && isset($row[$name]) && trim((string)$row[$name])!=='') return $row[$name]; return null; }
}
