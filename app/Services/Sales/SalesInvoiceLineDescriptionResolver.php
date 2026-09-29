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

    /** @return list<string> descriptions in native invoice-line order */
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
            $out[] = $this->description($family, $effectiveBookingId, $service, $line);
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
                $rows = DB::table($table)->where($foreign, $invoiceId)->get()
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
        $native = [];
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
        $parts = [];
        foreach ($rows as $row) {
            $airline = $this->firstText($row, ['airline_name', 'airline', 'carrier_name', 'carrier', 'airline_code', 'carrier_code']);
            $origin = $this->firstText($row, ['from_code', 'origin_code', 'from', 'origin', 'from_airport', 'departure_airport']);
            $destination = $this->firstText($row, ['to_code', 'destination_code', 'to', 'destination', 'to_airport', 'arrival_airport']);
            $flight = $this->firstText($row, ['flight_number', 'flight_no', 'flight']);
            if ($origin !== '' && $destination !== '') $parts[] = implode(' · ', array_filter([$airline, strtoupper($origin.'-'.$destination), $flight]));
        }
        return implode(' / ', array_values(array_unique($parts)));
    }

    private function hotel(array $rows): string
    {
        foreach ($rows as $row) {
            $parts = array_filter([$this->firstText($row, ['hotel_name', 'property_name', 'hotel', 'name']), $this->firstText($row, ['city', 'hotel_city', 'destination'])]);
            $in = $this->firstText($row, ['check_in', 'checkin', 'check_in_date']); $out = $this->firstText($row, ['check_out', 'checkout', 'check_out_date']);
            if ($in !== '' || $out !== '') $parts[] = trim($in.'–'.$out);
            $nights = $this->firstText($row, ['nights', 'total_nights']); if ($nights !== '') $parts[] = $nights.' Nights';
            if ($parts !== []) return implode(' · ', $parts);
        }
        return '';
    }

    private function visa(array $rows): string
    { foreach ($rows as $row) { $parts = array_filter([$this->firstText($row, ['country', 'destination_country']), $this->firstText($row, ['visa_type', 'type']), $this->firstText($row, ['duration', 'validity_days', 'stay_duration'])]); if ($parts !== []) return implode(' · ', $parts); } return ''; }
    private function transport(array $rows): string
    { foreach ($rows as $row) { $route = $this->firstText($row, ['route']); if ($route === '') { $from=$this->firstText($row,['pickup','pickup_location','origin','from']); $to=$this->firstText($row,['dropoff','dropoff_location','destination','to']); $route=trim($from.' → '.$to,' →'); } $parts=array_filter([$route,$this->firstText($row,['vehicle_type','vehicle']),$this->firstText($row,['travel_date','date','pickup_date'])]); if($parts!==[]) return implode(' · ',$parts); } return ''; }
    private function umrah(array $rows): string
    { foreach ($rows as $row) { $parts=array_filter([$this->firstText($row,['package_name','package','package_code','name'])]); $m=$this->firstText($row,['makkah_nights','makkah_night_count']); $d=$this->firstText($row,['madinah_nights','madinah_night_count']); if($m!=='')$parts[]='Makkah '.$m.' nights'; if($d!=='')$parts[]='Madinah '.$d.' nights'; if($parts!==[])return implode(' · ',$parts); } return ''; }

    private function firstColumn(array $columns, array $wanted): ?string { foreach ($wanted as $name) if (in_array($name, $columns, true)) return $name; return null; }
    private function firstText(array $row, array $wanted): string { foreach ($wanted as $name) { $v = trim((string) ($row[$name] ?? '')); if ($v !== '') return preg_replace('/\s+/', ' ', $v) ?: ''; } return ''; }
    private function firstInt(array $row, array $wanted): int { foreach ($wanted as $name) { $v = (int) ($row[$name] ?? 0); if ($v > 0) return $v; } return 0; }
}
