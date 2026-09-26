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
    private array $nativeProductIds = [];
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
        $bookingNo = $bookingId === -1 ? 'MULTIPLE' : ($booking['number'] ?? ($bookingId > 0 ? 'Booking #'.$bookingId : '—'));
        $product = $this->product($bookingId, $sourceType, $source);
        $party = $this->party($bookingId);
        $serviceRef = $this->serviceRef($bookingId, $product, $source);
        $description = $this->description($bookingId, $product, $bookingNo, $source, (string) ($row['reference'] ?? ''));
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
        $tables = $this->sourceTables($type);
        if ($tables === []) return $this->sourceCache[$key] = [];
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
                if ($bookingId <= 0 && $table === 'cash_vouchers' && Schema::hasTable('cash_voucher_allocations')) {
                    $allocationRows = DB::table('cash_voucher_allocations')->where('cash_voucher_id', $id)->get();
                    $resolved = [];
                    foreach ($allocationRows as $allocation) {
                        $allocation = (array) $allocation;
                        $targetType = strtolower((string) ($allocation['target_type'] ?? ''));
                        $targetId = (int) ($allocation['target_id'] ?? 0);
                        $targetTable = str_contains($targetType, 'supplier') ? 'supplier_costings' : (str_contains($targetType, 'advance') ? 'advance_adjustments' : (str_contains($targetType, 'invoice') ? 'sales_invoices' : ''));
                        if ($targetTable !== '' && $targetId > 0 && Schema::hasTable($targetTable)) try { $target = DB::table($targetTable)->where('id', $targetId)->first(); if ((int) ($target->booking_id ?? 0) > 0) $resolved[] = (int) $target->booking_id; } catch (Throwable) { }
                    }
                    $resolved = array_values(array_unique($resolved));
                    if (count($resolved) === 1) $bookingId = $resolved[0];
                    elseif (count($resolved) > 1) $bookingId = -1;
                }
                return $this->sourceCache[$key] = ['table' => $table, 'row' => $a, 'booking_id' => $bookingId];
            } catch (Throwable) { continue; }
        }
        return $this->sourceCache[$key] = [];
    }

    private function sourceTables(string $type): array
    {
        if (str_contains($type, 'sales_invoice') || str_contains($type, 'invoice')) return ['sales_invoices','sales_invoice_headers','invoices'];
        if (str_contains($type, 'cash_voucher') || str_contains($type, 'receipt') || str_contains($type, 'payment')) return ['cash_vouchers'];
        if (str_contains($type, 'supplier_cost')) return ['supplier_costings'];
        if (str_contains($type, 'advance_adjust')) return ['advance_adjustments'];
        return [];
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
            if (str_contains($sourceType, 'cash_voucher') || str_contains($sourceType, 'receipt') || str_contains($sourceType, 'payment')) {
                $kind = strtolower((string) (($source['row']['voucher_type'] ?? '') ?: ''));
                $label = str_contains($kind, 'receipt') ? 'RECEIPT' : (str_contains($kind, 'payment') ? 'PAYMENT' : (str_contains($kind, 'advance') ? 'ADVANCE' : 'CASH VOUCHER'));
                return str_contains($sourceType, 'reversal') ? $label.' REVERSAL' : $label;
            }
            return $bookingId === -1 ? 'MULTIPLE' : '—';
        }
        if (isset($this->productCache[$bookingId])) return $this->productCache[$bookingId];
        $identities = [];
        if (Schema::hasTable('booking_services')) {
            try {
                $columns = Schema::getColumnListing('booking_services');
                $rows = DB::table('booking_services')->where('booking_id', $bookingId)->get();
                foreach ($rows as $row) {
                    $a = (array) $row; $name = $this->first($a, $columns, ['product_name','service_name','name','category','type']);
                    if ($name) $identities[$this->canonicalProduct((string) $name)] = $this->displayProduct($this->canonicalProduct((string) $name));
                    $id = (int) ($a['product_service_id'] ?? 0);
                    if ($id > 0) { $canonical = $this->canonicalProductFromNativeId($id); $identities[$canonical] = $this->displayProduct($canonical); }
                }
            } catch (Throwable) { /* unresolved product remains honest */ }
        }
        foreach (['booking_visa_services' => 'Visa', 'booking_group_umrah_contexts' => 'Umrah', 'booking_group_umrah_services' => 'Umrah'] as $table => $label) {
            if (! Schema::hasTable($table)) continue;
            try { if (DB::table($table)->where('booking_id', $bookingId)->exists()) { $canonical = strtolower($label) === 'umrah' ? 'umrah_package' : strtolower($label); $identities[$canonical] = $this->displayProduct($canonical); } } catch (Throwable) { }
        }
        return $this->productCache[$bookingId] = count($identities) > 1 ? 'MULTI PRODUCT' : (array_values($identities)[0] ?? '—');
    }

    private function canonicalProduct(string $value): string
    { $v = strtolower(trim($value)); if (str_contains($v, 'air') || str_contains($v, 'flight') || str_contains($v, 'ticket')) return 'air'; if (str_contains($v, 'hotel') || str_contains($v, 'accommodation')) return 'hotel'; if (str_contains($v, 'visa')) return 'visa'; if (str_contains($v, 'transport') || str_contains($v, 'transfer')) return 'transport'; if (str_contains($v, 'umrah') || str_contains($v, 'package')) return 'umrah_package'; return 'other:'.preg_replace('/[^a-z0-9]+/', '-', $v); }
    private function displayProduct(string $key): string { return ['air'=>'AIR','hotel'=>'HOTEL','visa'=>'VISA','transport'=>'TRANSPORT','umrah_package'=>'UMRAH PACKAGE'][$key] ?? strtoupper(str_replace('other:', '', $key)); }
    private function canonicalProductFromNativeId(int $id): string
    { foreach ($this->nativeProductIds() as $key => $nativeId) if ($nativeId === $id) return $key; return 'other:'.$id; }
    private function nativeProductIds(): array
    { if ($this->nativeProductIds !== []) return $this->nativeProductIds; foreach (['air'=>'findAir','hotel'=>'findHotel','transport'=>'findTransport'] as $key => $method) try { $match = $this->products->{$method}(); if ($match && (int) ($match['id'] ?? 0) > 0) $this->nativeProductIds[$key] = (int) $match['id']; } catch (Throwable) { } return $this->nativeProductIds; }

    private function nativeProductName(int $id): string
    {
        try {
            foreach (['product_services','product_service_master','product_service_masters','travel_product_services','service_products'] as $table) {
                if (! Schema::hasTable($table)) continue;
                $row = DB::table($table)->where('id', $id)->first();
                if ($row) return trim((string) (($row->name ?? null) ?: ($row->title ?? null) ?: ($row->service_name ?? null) ?: '—'));
            }
            // Native IDs are resolved dynamically; no fixed product IDs are assumed.
            $this->nativeProductIds();
        } catch (Throwable) { }
        return '—';
    }

    private function party(int $bookingId): string
    {
        if ($bookingId <= 0) return '—';
        if (array_key_exists($bookingId, $this->passengerCache)) return $this->passengerCache[$bookingId];
        try {
            $rows = $this->passengers->rows($bookingId);
            $names = $rows->map(function (object $row): string { $a=(array)$row; $full = trim((string) (($a['full_name'] ?? '') ?: ($a['passenger_name'] ?? '') ?: ($a['name'] ?? '') ?: trim((($a['first_name'] ?? $a['given_name'] ?? '').' '.($a['last_name'] ?? $a['surname'] ?? $a['family_name'] ?? ''))))); return preg_replace('/\s+/', ' ', $full) ?: ''; })->filter()->values();
            if ($names->count() === 1) return $this->passengerCache[$bookingId] = (string) $names->first();
            if ($names->count() > 1) return $this->passengerCache[$bookingId] = (string) $names->first().' + '.($names->count()-1);
        } catch (Throwable) { }
        return $this->passengerCache[$bookingId] = '—';
    }

    private function serviceRef(int $bookingId, string $product, array $source): string
    {
        if ($bookingId <= 0) return $this->cashRef($source);
        $refs=[]; $lower=strtolower($product);
        $families = $product === 'MULTI PRODUCT' ? ['air','hotel','visa','transport','umrah_package'] : [$this->canonicalProduct($product)];
        foreach ($families as $family) foreach ($this->serviceTables($family, $bookingId) as [$table, $key]) { if (! Schema::hasTable($table)) continue; try { $columns=Schema::getColumnListing($table); $query=DB::table($table); if($table==='air_ticket_details' && $key==='booking_service_id' && Schema::hasTable('booking_services')) { $ids=DB::table('booking_services')->where('booking_id',$bookingId)->whereIn('product_service_id',array_values($this->nativeProductIds()))->pluck('id')->all(); if(!$ids) continue; $query->whereIn($key,$ids); } else { $query->where($key,$bookingId); } foreach($query->get() as $r){ $v=$this->first((array)$r,$columns,['pnr','ticket_number','ticket_no','e_ticket_number','document_number','confirmation_no','confirmation_number','brn','supplier_reference','booking_reference','voucher_no','visa_number','application_reference','reference']); if($v) $refs[]=strtoupper($family).' '.(string)$v; } } catch(Throwable){} }
        return implode(' · ', array_values(array_unique($refs))) ?: '—';
    }

    private function serviceTables(string $family, int $bookingId): array
    { if ($family === 'air') { $out=[]; if (Schema::hasTable('booking_services')) try { $ids=DB::table('booking_services')->where('booking_id',$bookingId)->whereIn('product_service_id',array_values($this->nativeProductIds()))->pluck('id')->all(); if($ids && Schema::hasTable('air_ticket_details')) $out[]=['air_ticket_details','booking_service_id']; } catch(Throwable){} return $out; } return match($family) { 'hotel'=>array_map(fn($t)=>[$t,'booking_id'],['booking_hotel_stays','booking_hotels','hotel_stays','booking_hotel_details','booking_accommodations','hotel_booking_details']), 'transport'=>array_map(fn($t)=>[$t,'booking_id'],['booking_transport_segments','booking_transports','transport_booking_details','booking_transport_details']), 'visa'=>[['booking_visa_services','booking_id']], 'umrah_package'=>[['booking_group_umrah_contexts','booking_id'],['booking_group_umrah_services','booking_id']], default=>[] }; }
    private function cashRef(array $source): string { $row=(array)($source['row']??[]); foreach(['transaction_reference','instrument_no','posting_reference','voucher_no'] as $k) if(trim((string)($row[$k]??''))!=='') return (string)$row[$k]; return '—'; }

    private function description(int $bookingId, string $product, string $booking, array $source, string $reference): string
    { $row=(array)($source['row']??[]); $family=$this->canonicalProduct($product); if($family==='air' && $bookingId>0 && Schema::hasTable('booking_services') && Schema::hasTable('air_ticket_details')) try { $ids=DB::table('booking_services')->where('booking_id',$bookingId)->whereIn('product_service_id',array_values($this->nativeProductIds()))->pluck('id')->all(); if($ids){$cols=Schema::getColumnListing('air_ticket_details');$r=DB::table('air_ticket_details')->whereIn('booking_service_id',$ids)->first();$origin=$this->first((array)$r,$cols,['origin','origin_code','from','from_airport']);$destination=$this->first((array)$r,$cols,['destination','destination_code','to','to_airport']);if($origin&&$destination)return strtoupper($origin.'-'.$destination);}} catch(Throwable){} foreach(['origin','origin_code','from','destination','destination_code','to','sector','route'] as $k) if(trim((string)($row[$k]??''))!=='') return strtoupper((string)$row[$k]); foreach(['description','narration','remarks','memo','notes'] as $k) if(trim((string)($row[$k]??''))!=='') return (string)$row[$k]; if($product!=='—') return $product.' context'; return $reference ?: 'Journal'; }
    private function first(array $row, array $columns, array $wanted): mixed { foreach($wanted as $name) if(in_array($name,$columns,true) && isset($row[$name]) && trim((string)$row[$name])!=='') return $row[$name]; return null; }
}
