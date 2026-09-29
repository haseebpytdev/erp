<?php

namespace App\Services\Sales;

use App\Services\Operations\NativeProductServiceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only product context for native Sales Invoice presentation.
 *
 * The invoice line amount and accounting identity are deliberately never
 * changed here.  This class only projects already persisted booking/service
 * context into a concise, escaped-by-the-caller description.
 */
final class SalesInvoiceLineDescriptionResolver
{
    public function __construct(private readonly NativeProductServiceResolver $products) {}

    /** @return list<array{description:string,reference:string}> in native invoice-line order */
    public function resolve(int $invoiceId): array
    {
        if ($invoiceId <= 0) return [];
        $invoice = $this->invoice($invoiceId);
        if ($invoice === []) return [];
        $lines = $this->lines($invoiceId);
        if ($lines === []) return [];
        $bookingId = $this->firstInt($invoice, ['booking_id', 'source_booking_id', 'travel_booking_id']);
        $services = $bookingId > 0 ? $this->bookingServices($bookingId) : [];
        $out = [];
        foreach ($lines as $line) {
            $serviceId = $this->firstInt($line, ['source_booking_service_id', 'booking_service_id', 'service_id']);
            $service = $serviceId > 0 ? ($services[$serviceId] ?? $this->service($serviceId)) : [];
            $productId = $this->firstInt($line, ['product_service_id', 'product_id']) ?: $this->firstInt($service, ['product_service_id']);
            $family = $this->family($productId, $line, $service);
            $effectiveBookingId = $bookingId ?: $this->firstInt($service, ['booking_id']);
            $out[] = [
                'description' => $this->description($family, $effectiveBookingId, $service, $line),
                'reference' => $this->reference($family, $effectiveBookingId, $service, $line),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function invoice(int $id): array
    {
        foreach (['sales_invoices', 'sales_invoice_headers', 'invoices'] as $table) {
            if (! Schema::hasTable($table)) continue;
            try { $row = DB::table($table)->where('id', $id)->first(); if ($row) return (array) $row; } catch (Throwable) {}
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function lines(int $invoiceId): array
    {
        foreach (['sales_invoice_lines', 'sales_invoice_items', 'sales_invoice_details', 'invoice_lines', 'invoice_items'] as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = Schema::getColumnListing($table);
                $foreign = $this->firstColumn($columns, ['sales_invoice_id', 'invoice_id', 'header_id']);
                if (! $foreign) continue;
                $query = DB::table($table)->where($foreign, $invoiceId);
                $order = $this->firstColumn($columns, ['line_no', 'line_number', 'sequence', 'sort_order', 'position', 'id']);
                if (! $order) return [];
                $query->orderBy($order, 'asc');
                if ($order !== 'id' && in_array('id', $columns, true)) $query->orderBy('id', 'asc');
                $rows = $query->get()
                    ->map(static fn (object $row): array => (array) $row)->all();
                if ($rows !== []) return $rows;
            } catch (Throwable) {}
        }
        return [];
    }

    /** @return array<int,array<string,mixed>> */
    private function bookingServices(int $bookingId): array
    {
        if ($bookingId <= 0 || ! Schema::hasTable('booking_services')) return [];
        try { return DB::table('booking_services')->where('booking_id', $bookingId)->get()->mapWithKeys(static fn (object $r): array => [(int) $r->id => (array) $r])->all(); } catch (Throwable) { return []; }
    }

    /** @return array<string,mixed> */
    private function service(int $id): array
    {
        if ($id <= 0 || ! Schema::hasTable('booking_services')) return [];
        try { $row = DB::table('booking_services')->where('id', $id)->first(); return $row ? (array) $row : []; } catch (Throwable) { return []; }
    }

    private function family(int $productId, array $line, array $service): string
    {
        foreach (['air' => 'findAir', 'hotel' => 'findHotel', 'visa' => 'findVisa', 'transport' => 'findTransport'] as $family => $method) {
            try { $match = $this->products->{$method}(); if ($match && (int) ($match['id'] ?? 0) === $productId) return $family; } catch (Throwable) {}
        }
        $text = strtolower(implode(' ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $line + $service)));
        if (preg_match('/\bumrah|package\b/i', $text)) return 'umrah';
        if (preg_match('/\bvisa\b/i', $text)) return 'visa';
        if (preg_match('/\bhotel|accommodation\b/i', $text)) return 'hotel';
        if (preg_match('/\btransport|transfer\b/i', $text)) return 'transport';
        if (preg_match('/\bair|flight|ticket\b/i', $text)) return 'air';
        return 'other';
    }

    private function description(string $family, int $bookingId, array $service, array $line): string
    {
        $rows = $this->familyRows($family, $bookingId, $service);
        $value = match ($family) {
            'air' => $this->air($rows),
            'hotel' => $this->hotel($rows),
            'visa' => $this->visa($rows),
            'transport' => $this->transport($rows),
            'umrah' => $this->umrah($rows),
            default => '',
        };
        if ($value !== '') return $value;
        $native = $this->firstText($line, ['service_name', 'product_name', 'name', 'description']) ?: $this->firstText($service, ['service_name', 'product_name', 'name']);
        if ($native !== '') return $native;
        return match ($family) { 'air' => 'Air Ticket', 'hotel' => 'Hotel Accommodation', 'visa' => 'Visa', 'transport' => 'Transport', 'umrah' => 'Umrah Package', default => 'Other Service' };
    }

    /** @return list<array<string,mixed>> */
    private function familyRows(string $family, int $bookingId, array $service): array
    {
        if ($bookingId <= 0) return $service === [] ? [] : [$service];
        $tables = match ($family) {
            'air' => ['booking_itinerary_segments', 'air_ticket_details'],
            'hotel' => ['booking_hotel_stays', 'booking_hotels', 'hotel_stays', 'booking_hotel_details', 'booking_accommodations', 'hotel_booking_details'],
            'visa' => ['booking_visa_services'],
            'transport' => ['booking_transport_segments', 'booking_transports', 'transport_booking_details', 'booking_transport_details'],
            'umrah' => ['booking_group_umrah_contexts', 'booking_group_umrah_services'],
            default => [],
        };
        $rows = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = Schema::getColumnListing($table);
                $query = DB::table($table);
                if (in_array('booking_service_id', $columns, true) && (int) ($service['id'] ?? 0) > 0) $query->where('booking_service_id', (int) $service['id']);
                elseif (in_array('booking_id', $columns, true)) $query->where('booking_id', $bookingId);
                else continue;
                foreach ($query->get() as $row) $rows[] = (array) $row;
            } catch (Throwable) {}
            if ($rows !== [] && in_array($family, ['air', 'hotel', 'visa', 'transport', 'umrah'], true)) break;
        }
        return $rows;
    }

    private function air(array $rows): string
    {
        $ordered = $this->ordered($rows);
        $flights = []; $airlines = []; $groups = []; $current = [];
        foreach ($ordered as $row) {
            $airline = $this->firstText($row, ['airline_name', 'airline', 'carrier_name', 'carrier', 'airline_code', 'carrier_code']);
            $origin = $this->firstText($row, ['from_code', 'origin_code', 'from', 'origin', 'from_airport', 'departure_airport']);
            $destination = $this->firstText($row, ['to_code', 'destination_code', 'to', 'destination', 'to_airport', 'arrival_airport']);
            $flight = $this->firstText($row, ['flight_number', 'flight_no', 'flight']);
            if ($airline !== '') $airlines[] = $airline;
            if ($flight !== '') $flights[] = $flight;
            if ($origin !== '' && $destination !== '') {
                $origin = strtoupper($origin); $destination = strtoupper($destination);
                if ($current === [] || end($current) === $origin) {
                    if ($current === []) $current[] = $origin;
                    if (end($current) !== $destination) $current[] = $destination;
                } else {
                    $groups[] = $current; $current = [$origin, $destination];
                }
            }
        }
        if ($current !== []) $groups[] = $current;
        $airlines = array_values(array_unique($airlines));
        $route = implode(' / ', array_map(static fn (array $group): string => implode(' → ', $group), $groups));
        $airline = count($airlines) === 1 ? $airlines[0] : implode(' / ', $airlines);
        $primary = $route !== '' ? (($airline !== '' ? $airline.' — ' : 'Air Ticket — ').$route) : ($airline !== '' ? $airline.' — Air Ticket' : 'Air Ticket');
        return $flights === [] ? $primary : $primary."\n".implode(' / ', $flights);
    }

    private function hotel(array $rows): string
    {
        foreach ($rows as $row) {
            $parts = array_filter([$this->firstText($row, ['hotel_name', 'property_name', 'hotel', 'name']), $this->firstText($row, ['city', 'city_name', 'hotel_city', 'destination', 'location'])]);
            $in = $this->firstText($row, ['check_in', 'check_in_date', 'checkin', 'checkin_date']); $out = $this->firstText($row, ['check_out', 'check_out_date', 'checkout', 'checkout_date']);
            $dates = ($in !== '' || $out !== '') ? trim($this->dateLabel($in).' → '.$this->dateLabel($out), ' →') : '';
            if ($dates !== '') $parts[] = $dates;
            $nights = $this->firstText($row, ['nights', 'total_nights']); if ($nights !== '') $parts[] = $nights.' Nights';
            $room = $this->firstText($row, ['room_type', 'room_category', 'room', 'room_name', 'accommodation_type']);
            $occupancy = $this->occupancy($row);
            $board = $this->firstText($row, ['board', 'board_basis', 'meal_plan', 'meal', 'meal_basis']);
            if ($room !== '') $parts[] = $room;
            if ($occupancy !== '') $parts[] = $occupancy;
            if ($board !== '') $parts[] = $board;
            if ($parts !== []) {
                $primary = array_slice($parts, 0, 2);
                $secondary = array_slice($parts, 2);
                return implode(' — ', $primary).($secondary !== [] ? "\n".implode(' · ', $secondary) : '');
            }
        }
        return '';
    }

    private function visa(array $rows): string
    { foreach ($rows as $row) { $country=$this->firstText($row,['country','destination_country']); $type=$this->firstText($row,['visa_type','type']); $duration=$this->firstText($row,['duration','stay_duration']); $validity=$this->firstText($row,['validity_days']); if($validity!=='')$duration=$validity.' Days'; $primary=trim($country.($country!==''&&$type!==''?' — ':'').$type); if($primary!==''||$duration!=='')return trim($primary.($duration!==''?"\n".$duration:'')); } return ''; }
    private function transport(array $rows): string
    { foreach ($rows as $row) { $route = $this->firstText($row, ['route_label','route_name','route']); if ($route === '') { $from=$this->firstText($row,['pickup_location','from_location','origin','from_city','from']); $to=$this->firstText($row,['dropoff_location','to_location','destination','to_city','to']); $route=trim($from.' → '.$to,' →'); } $vehicle=$this->firstText($row,['vehicle_type','vehicle_name','vehicle']); $date=$this->dateLabel($this->firstText($row,['travel_date','departure_date','start_date','booking_date','date','pickup_date'])); $primary=$route!==''?$route:'Transport'; $secondary=implode(' · ',array_filter([$vehicle,$date])); return $secondary!==''?$primary."\n".$secondary:$primary; } return ''; }
    private function umrah(array $rows): string
    { foreach ($rows as $row) { $package=$this->firstText($row,['package_name','package','package_code','name']); $m=$this->firstText($row,['makkah_nights','makkah_night_count']); $d=$this->firstText($row,['madinah_nights','madinah_night_count']); $details=implode(' · ',array_filter([$m!==''?'Makkah '.$m.'N':'',$d!==''?'Madinah '.$d.'N':''])); if($package!==''||$details!=='')return trim(($package!==''?$package.' Umrah Package':'Umrah Package').($details!==''?"\n".$details:'')); } return ''; }

    private function reference(string $family, int $bookingId, array $service, array $line = []): string
    {
        if ($family === 'other') {
            return $this->firstText($line, ['service_reference', 'customer_reference', 'reference_no', 'booking_reference', 'voucher_no', 'confirmation_no'])
                ?: $this->firstText($service, ['service_reference', 'customer_reference', 'reference_no', 'booking_reference', 'voucher_no', 'confirmation_no']);
        }
        $rows = $this->familyRows($family, $bookingId, $service);
        $keys = match ($family) {
            'hotel' => ['confirmation_no', 'confirmation_number', 'brn', 'brn_number', 'booking_reference', 'voucher_no', 'reference_no'],
            'visa' => ['visa_number', 'visa_no', 'application_reference', 'application_no'],
            'transport' => ['brn_number', 'brn', 'provider_reference', 'booking_reference', 'reference', 'voucher_no', 'confirmation_no'],
            'umrah' => ['package_reference', 'package_booking_no', 'voucher_no', 'booking_reference'],
            default => ['service_reference', 'customer_reference', 'reference_no'],
        };
        foreach ($rows as $row) foreach ($keys as $key) {
            $value = $this->firstText($row, [$key]);
            if ($value !== '') return $value;
        }
        return '';
    }

    /** @return list<array<string,mixed>> */
    private function ordered(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            foreach (['sort_order', 'sequence', 'sequence_no', 'segment_order', 'position', 'id'] as $key) {
                $left = (int) ($a[$key] ?? 0); $right = (int) ($b[$key] ?? 0);
                if ($left !== $right) return $left <=> $right;
            }
            return 0;
        });
        return $rows;
    }

    private function firstColumn(array $columns, array $wanted): ?string { foreach ($wanted as $name) if (in_array($name, $columns, true)) return $name; return null; }
    private function firstText(array $row, array $wanted): string { foreach ($wanted as $name) { $v = trim((string) ($row[$name] ?? '')); if ($v !== '') return preg_replace('/\s+/', ' ', $v) ?: ''; } return ''; }
    private function firstInt(array $row, array $wanted): int { foreach ($wanted as $name) { $v = (int) ($row[$name] ?? 0); if ($v > 0) return $v; } return 0; }
    private function occupancy(array $row): string
    {
        $adults = $this->firstText($row, ['adult_count', 'adults']); $children = $this->firstText($row, ['child_count', 'children']);
        if ($adults !== '' && $children !== '') return $adults.' Adults · '.$children.' Child'.((int) $children === 1 ? '' : 'ren');
        if ($adults !== '') return $adults.' Adult'.((int) $adults === 1 ? '' : 's');
        if ($children !== '') return $children.' Child'.((int) $children === 1 ? '' : 'ren');
        $guestCount = $this->firstText($row, ['occupancy', 'occupancy_type', 'guest_count']);
        return $guestCount !== '' && ! is_numeric($guestCount) ? $guestCount : ($guestCount !== '' ? $guestCount.' Guests' : '');
    }
    private function dateLabel(string $value): string
    {
        if ($value === '') return '';
        try { return (new \DateTime($value))->format('d M Y'); } catch (Throwable) { return $value; }
    }
}
