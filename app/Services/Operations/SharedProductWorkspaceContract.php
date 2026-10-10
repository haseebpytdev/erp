<?php

namespace App\Services\Operations;

use DateTimeImmutable;

/**
 * Single product-entry contract used by original and supplementary contexts.
 * Persistence remains outside this class so supplementary drafts never write
 * native booking product rows.
 */
final class SharedProductWorkspaceContract
{
    private const PRODUCTS = ['air', 'hotel', 'transport', 'visa'];

    public function fields(string $product): array
    {
        return match ($this->assertProduct($product)) {
            'air' => ['booking_passenger_id','airline_id','airline_code','airline_name','flight_number','pnr','airline_pnr','ticket_number','ticket_status','from','to','departure_at','arrival_at','booking_class','baggage','vendor_id','vendor_name','sale_price','cost_price','native_sale_price','native_cost_price','segments','itinerary','ticket_groups','tickets','fare_commercials','common','group_common','native_air_group_key','source_key','customer_total','supplier_total','currency_code','exchange_rate','notes'],
            'hotel' => ['vendor_id','city_id','city','hotel_id','hotel_name','room_type','board','check_in','check_out','sale_rate','cost_rate','confirmation_no','stays','rooms','currency_code','exchange_rate','notes'],
            'transport' => ['vendor_id','route_master_id','route_source_table','route_source_key','route_name','from_location','to_location','vehicle_master_id','vehicle_source_table','vehicle_type','quantity','service_date','company_name','driver_name','driver_cell','contact_number','plate_number','brn_number','sale_amount','sale_price','cost_rate','cost_price','cost_currency','exchange_rate','cost_amount','margin','notes'],
            'visa' => ['booking_passenger_id','country','visa_type','provider_type','visa_rate_card_id','saudi_company_id','saudi_company_name','pakistani_iata_id','pakistani_iata_name','vendor_id','application_reference','visa_number','status','issue_date','expiry_date','sale_pkr','sale_price','cost_rate','cost_price','currency_code','notes','visas'],
        };
    }

    public function normalize(string $product, array $input): array
    {
        $product = $this->assertProduct($product);
        $out = [];
        foreach ($this->fields($product) as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = is_string($input[$field])
                    ? trim(preg_replace('/\s+/', ' ', $input[$field]) ?? $input[$field])
                    : $input[$field];
            }
        }
        foreach (['booking_passenger_id','airline_id','vendor_id','hotel_id','visa_rate_card_id','saudi_company_id','pakistani_iata_id'] as $id) {
            if (array_key_exists($id, $out)) $out[$id] = $this->normalizeIdentifier($out[$id], $id);
        }
        foreach (['sale_price','cost_price','sale_rate','cost_rate'] as $money) {
            if (array_key_exists($money, $out) && $out[$money] !== '') {
                if (! is_numeric($out[$money])) throw new \InvalidArgumentException('Commercial amounts must be numeric.');
                $out[$money] = round((float) $out[$money], 2);
            }
        }
        foreach (['check_in','check_out','service_date'] as $date) {
            if (array_key_exists($date, $out) && $out[$date] !== '') $out[$date] = $this->canonicalDate((string) $out[$date]);
        }
        foreach (['departure_at','arrival_at'] as $date) {
            if (array_key_exists($date, $out) && $out[$date] !== '') $out[$date] = $this->canonicalDateTime((string) $out[$date]);
        }
        return $out;
    }

    public function validate(string $product, array &$snapshot, int $bookingId, callable $passenger, callable $vendor, callable $airline): void
    {
        $product = $this->assertProduct($product);
        $required = match ($product) {
            'air' => ['booking_passenger_id','from','to','departure_at','sale_price'],
            'hotel' => ['city','hotel_name','room_type','board','check_in','check_out','sale_rate'],
            'transport' => ['from_location','to_location','vehicle_type','sale_price'],
            'visa' => ['booking_passenger_id','country','visa_type','sale_price'],
        };
        foreach ($required as $field) if (! array_key_exists($field, $snapshot) || $snapshot[$field] === '' || $snapshot[$field] === null) throw new \InvalidArgumentException('Required field missing: '.$field);
        foreach (['sale_price','cost_price','sale_rate','cost_rate'] as $field) if (array_key_exists($field, $snapshot) && $snapshot[$field] !== '' && ! is_numeric($snapshot[$field])) throw new \InvalidArgumentException('Commercial amounts must be numeric.');
        $sale = (float) ($snapshot['sale_price'] ?? $snapshot['sale_rate'] ?? 0);
        $cost = (float) ($snapshot['cost_price'] ?? $snapshot['cost_rate'] ?? 0);
        if ($sale < 0 || $cost < 0) throw new \InvalidArgumentException('Negative commercial amounts are not allowed.');
        if ($cost > 0 && empty($snapshot['vendor_id'])) throw new \InvalidArgumentException('A valid vendor is required when cost is positive.');
        if (in_array($product, ['air','visa'], true)) {
            $resolved = $passenger($bookingId, (int) ($snapshot['booking_passenger_id'] ?? 0));
            if (! $resolved) throw new \InvalidArgumentException('Passenger must be active and belong to this booking.');
            $snapshot['passenger_snapshot'] = $resolved;
        }
        if ($product === 'hotel') {
            $in = new DateTimeImmutable((string) $snapshot['check_in']);
            $out = new DateTimeImmutable((string) $snapshot['check_out']);
            if ($out <= $in || $in->diff($out)->days < 1) throw new \InvalidArgumentException('Hotel check-out must be after check-in.');
        }
        if ($snapshot['vendor_id'] ?? null) {
            $resolved = $vendor((int) $snapshot['vendor_id']);
            if (! $resolved) throw new \InvalidArgumentException('Vendor is not valid.');
            $snapshot['vendor_name'] = $resolved['name'];
        } else { $snapshot['vendor_id'] = null; $snapshot['vendor_name'] = null; }
        if ($product === 'air' && ($snapshot['airline_id'] ?? null)) {
            $resolved = $airline((int) $snapshot['airline_id']);
            if (! $resolved) throw new \InvalidArgumentException('Airline is not valid.');
            $snapshot['airline_name'] = $resolved['name']; $snapshot['airline_code'] = $resolved['code'];
        } elseif ($product === 'air') {
            $snapshot['airline_code'] = null;
            $snapshot['airline_name'] = trim((string) ($snapshot['airline_name'] ?? '')) ?: null;
        }
    }

    public function commercial(string $product, array $snapshot): array
    {
        $product = $this->assertProduct($product);
        $cost = (float) ($snapshot['cost_price'] ?? $snapshot['cost_rate'] ?? 0);
        $sale = (float) ($snapshot['sale_price'] ?? $snapshot['sale_rate'] ?? 0);
        $quantity = 1;
        if ($product === 'hotel') {
            $quantity = max(1, (new DateTimeImmutable((string) $snapshot['check_in']))->diff(new DateTimeImmutable((string) $snapshot['check_out']))->days);
            $sale *= $quantity; $cost *= $quantity;
        }
        $description = match ($product) {
            'air' => 'Air · '.($snapshot['from'] ?? '').' → '.($snapshot['to'] ?? '').' · '.($snapshot['passenger_snapshot']['name'] ?? 'Passenger'),
            'hotel' => 'Hotel · '.($snapshot['city'] ?? '').' · '.($snapshot['hotel_name'] ?? '').' · '.$quantity.' Nights',
            'transport' => 'Transport · '.($snapshot['from_location'] ?? '').' → '.($snapshot['to_location'] ?? '').' · '.($snapshot['vehicle_type'] ?? ''),
            'visa' => 'Visa · '.($snapshot['visa_type'] ?? '').' · '.($snapshot['passenger_snapshot']['name'] ?? 'Passenger'),
        };
        return ['quantity'=>$quantity,'unit_price'=>round($sale/$quantity,2),'sale_amount'=>round($sale,2),'cost'=>round($cost,2),'margin'=>round($sale-$cost,2),'description'=>$description];
    }

    private function assertProduct(string $product): string
    {
        if (! in_array($product, self::PRODUCTS, true)) throw new \InvalidArgumentException('Unsupported product workspace.');
        return $product;
    }
    public function normalizeIdentifier(mixed $value, string $field): ?int
    { if ($value === null || $value === '') return null; if (is_int($value) && $value > 0) return $value; if (is_string($value) && preg_match('/^[1-9]\d*$/', trim($value))) return (int) trim($value); throw new \InvalidArgumentException('Invalid '.$field.'.'); }
    public function canonicalDate(string $value): string
    { $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); $errors = DateTimeImmutable::getLastErrors(); if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || $date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) throw new \InvalidArgumentException('Invalid date format.'); return $date->format('Y-m-d'); }
    public function canonicalDateTime(string $value): string
    { $value = str_replace('T', ' ', $value); $format = strlen($value) > 16 ? '!Y-m-d H:i:s' : '!Y-m-d H:i'; $date = DateTimeImmutable::createFromFormat($format, $value); $errors = DateTimeImmutable::getLastErrors(); if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/', $value) || $date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) throw new \InvalidArgumentException('Invalid date/time format.'); return $date->format('Y-m-d H:i:s'); }
}
