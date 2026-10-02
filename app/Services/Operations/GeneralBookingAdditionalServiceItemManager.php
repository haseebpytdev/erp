<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use DateTimeImmutable;
use Illuminate\Support\Str;

final class GeneralBookingAdditionalServiceItemManager
{
    private const PRODUCTS = ['air', 'hotel', 'transport', 'visa'];

    public function __construct(private readonly ActiveBookingPassengerResolver $passengers, private readonly UnifiedGroupPackageDataSource $catalog)
    {
    }

    public function editor(int $bookingId, int $batchId, string $product, ?int $itemId = null): array
    {
        $state = $this->batchState($bookingId, $batchId, $product);
        if ($itemId !== null && ($state['batch'] ?? null)) {
            $state['item'] = DB::table('general_booking_billing_batch_items')->where('id', $itemId)->where('batch_id', $batchId)->where('product_type', $product)->first();
            if (! $state['item']) return $state + ['item_missing' => true];
            $decoded = json_decode((string) ($state['item']->product_snapshot ?? ''), true);
            $state['form_values'] = is_array($decoded) ? $decoded : [];
        } else {
            $state['draft_item_token'] = $this->createToken($bookingId, $batchId, $product);
        }
        $state['passengers'] = in_array($product, ['air', 'visa'], true) ? $this->passengerOptions($bookingId) : [];
        $state['vendors'] = $this->vendorOptions();
        $state['airlines'] = $product === 'air' ? $this->airlineOptions() : [];
        return $state;
    }

    public function create(int $bookingId, int $batchId, string $product, array $input, int $userId = 0): array
    {
        $token = $this->tokenPayload((string) ($input['draft_item_token'] ?? ''));
        if (($token['booking_id'] ?? null) !== $bookingId || ($token['batch_id'] ?? null) !== $batchId || ($token['product'] ?? null) !== $product) throw new \InvalidArgumentException('The Draft form token is invalid for this route.');
        $sourceKey = 'supp-draft:'.$product.':'.$token['nonce'];
        return DB::transaction(function () use ($bookingId, $batchId, $product, $input, $sourceKey): array {
            $batch = $this->lockWritableBatch($bookingId, $batchId, $product);
            $existing = DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->where('source_key', $sourceKey)->first();
            if ($existing) return ['ok' => true, 'item_id' => (int) $existing->id, 'reused' => true];
            $snapshot = $this->normalize($bookingId, $product, $input);
            $this->validate($bookingId, $product, $snapshot);
            if ($product === 'air' && $this->duplicateAir($batchId, $snapshot)) throw new \InvalidArgumentException('This Air passenger/sector/departure line already exists in the Draft.');
            $lineNo = ((int) DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->max('line_no')) + 1;
            $commercial = $this->commercial($product, $snapshot, $batch);
            $snapshot['description'] = $commercial['description'];
            $hash = $this->hash($snapshot, $commercial);
            $now = now();
            $id = DB::table('general_booking_billing_batch_items')->insertGetId([
                'batch_id' => $batchId, 'booking_id' => $bookingId, 'line_no' => $lineNo, 'product_type' => $product,
                'source_key' => $sourceKey, 'source_table' => null, 'source_id' => null,
                'booking_service_id' => null, 'booking_passenger_id' => $snapshot['booking_passenger_id'] ?? null,
                'description_snapshot' => $commercial['description'], 'quantity' => $commercial['quantity'],
                'unit_price' => $commercial['unit_price'], 'sale_amount' => $commercial['sale_amount'],
                'supplier_cost_snapshot' => $commercial['cost'], 'margin_snapshot' => $commercial['margin'],
                'currency_code' => (string) $batch->currency_code, 'exchange_rate' => (float) $batch->exchange_rate,
                'revenue_mapping_key_snapshot' => null, 'product_snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                'source_hash' => $hash, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->recalculate($batchId, $batch);
            return ['ok' => true, 'item_id' => (int) $id];
        });
    }

    public function update(int $bookingId, int $batchId, int $itemId, string $product, array $input): array
    {
        return DB::transaction(function () use ($bookingId, $batchId, $itemId, $product, $input): array {
            $batch = $this->lockWritableBatch($bookingId, $batchId, $product);
            $item = DB::table('general_booking_billing_batch_items')->where('id', $itemId)->where('batch_id', $batchId)->lockForUpdate()->first();
            if (! $item || strtolower((string) $item->product_type) !== $product) return $this->fail('item_missing', 'Draft item was not found.');
            $existing = json_decode((string) ($item->product_snapshot ?? ''), true);
            $existing = is_array($existing) ? $existing : [];
            if ($product === 'air' && array_key_exists('airline_id', $input) && ($input['airline_id'] === null || $input['airline_id'] === '')) {
                $input['airline_code'] = null;
                if (! array_key_exists('airline_name', $input)) $input['airline_name'] = null;
            }
            $snapshot = $this->normalize($bookingId, $product, array_replace($existing, $input));
            $this->validate($bookingId, $product, $snapshot);
            if ($product === 'air' && $this->duplicateAir($batchId, $snapshot, $itemId)) throw new \InvalidArgumentException('This Air passenger/sector/departure line already exists in the Draft.');
            $commercial = $this->commercial($product, $snapshot, $batch);
            $snapshot['description'] = $commercial['description'];
            DB::table('general_booking_billing_batch_items')->where('id', $itemId)->update([
                'booking_passenger_id' => $snapshot['booking_passenger_id'] ?? null, 'description_snapshot' => $commercial['description'],
                'quantity' => $commercial['quantity'], 'unit_price' => $commercial['unit_price'], 'sale_amount' => $commercial['sale_amount'],
                'supplier_cost_snapshot' => $commercial['cost'], 'margin_snapshot' => $commercial['margin'],
                'product_snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES), 'source_hash' => $this->hash($snapshot, $commercial), 'updated_at' => now(),
            ]);
            $this->recalculate($batchId, $batch);
            return ['ok' => true, 'item_id' => $itemId];
        });
    }

    public function delete(int $bookingId, int $batchId, int $itemId, string $product): array
    {
        return DB::transaction(function () use ($bookingId, $batchId, $itemId, $product): array {
            $batch = $this->lockWritableBatch($bookingId, $batchId, $product);
            $item = DB::table('general_booking_billing_batch_items')->where('id', $itemId)->where('batch_id', $batchId)->lockForUpdate()->first();
            if (! $item || strtolower((string) $item->product_type) !== $product) return $this->fail('item_missing', 'Draft item was not found.');
            DB::table('general_booking_billing_batch_items')->where('id', $itemId)->delete();
            $this->recalculate($batchId, $batch);
            return ['ok' => true];
        });
    }

    private function batchState(int $bookingId, int $batchId, string $product): array
    {
        if (! in_array($product, self::PRODUCTS, true) || ! $this->foundationReady()) return ['schema_ready' => false, 'message' => 'Additional Services requires the General Booking Billing database upgrade.'];
        $batch = DB::table('general_booking_billing_batches')->where('id', $batchId)->where('booking_id', $bookingId)->first();
        if (! $batch) return ['batch_missing' => true, 'schema_ready' => true];
        $items = DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->orderBy('line_no')->get();
        return ['schema_ready' => true, 'batch' => (array) $batch, 'items' => $items->map(fn ($i) => (array) $i)->all(), 'product' => $product, 'draft_item_token' => $this->createToken($bookingId, $batchId, $product), 'writable' => strtolower((string) $batch->batch_type) === 'supplementary' && strtolower((string) $batch->status) === 'draft' && ! DB::table('general_booking_invoice_links')->where('batch_id', $batchId)->exists()];
    }

    private function lockWritableBatch(int $bookingId, int $batchId, string $product): object
    {
        if (! in_array($product, self::PRODUCTS, true)) throw new \InvalidArgumentException('Unsupported supplementary product.');
        $this->assertFoundationReady();
        $batch = DB::table('general_booking_billing_batches')->where('id', $batchId)->where('booking_id', $bookingId)->lockForUpdate()->first();
        if (! $batch) throw new \InvalidArgumentException('Supplementary batch does not belong to this booking.');
        if (strtolower((string) $batch->batch_type) !== 'supplementary' || strtolower((string) $batch->status) !== 'draft') throw new \InvalidArgumentException('Only a supplementary Draft batch can be edited.');
        if (DB::table('general_booking_invoice_links')->where('batch_id', $batchId)->exists()) throw new \InvalidArgumentException('Invoiced batches are read-only.');
        return $batch;
    }

    private function normalize(int $bookingId, string $product, array $input): array
    {
        $fields = match ($product) {
            'air' => ['booking_passenger_id','airline_id','airline_code','airline_name','flight_number','pnr','from','to','departure_at','arrival_at','booking_class','baggage','vendor_id','sale_price','cost_price'],
            'hotel' => ['vendor_id','city','hotel_id','hotel_name','room_type','board','check_in','check_out','sale_rate','cost_rate','confirmation_no'],
            'transport' => ['vendor_id','route_source_key','from_location','to_location','vehicle_type','service_date','company_name','driver_name','contact_number','plate_number','brn_number','sale_price','cost_price'],
            default => ['booking_passenger_id','country','visa_type','provider_type','visa_rate_card_id','saudi_company_id','saudi_company_name','pakistani_iata_id','pakistani_iata_name','vendor_id','application_reference','sale_price','cost_price'],
        };
        $out = []; foreach ($fields as $field) if (array_key_exists($field, $input)) $out[$field] = is_string($input[$field]) ? trim(preg_replace('/\s+/', ' ', $input[$field]) ?? $input[$field]) : $input[$field];
        foreach (['booking_passenger_id','airline_id','vendor_id','hotel_id','visa_rate_card_id','saudi_company_id','pakistani_iata_id'] as $id) if (array_key_exists($id, $out)) $out[$id] = $this->normalizeIdentifier($out[$id], $id);
        foreach (['sale_price','cost_price','sale_rate','cost_rate'] as $money) if (array_key_exists($money, $out) && $out[$money] !== '') { if (! is_numeric($out[$money])) throw new \InvalidArgumentException('Commercial amounts must be numeric.'); $out[$money] = round((float) $out[$money], 2); }
        foreach (['check_in','check_out','service_date'] as $date) if (array_key_exists($date, $out) && $out[$date] !== '') $out[$date] = $this->canonicalDate((string) $out[$date]);
        foreach (['departure_at','arrival_at'] as $date) if (array_key_exists($date, $out) && $out[$date] !== '') $out[$date] = $this->canonicalDateTime((string) $out[$date]);
        return $out;
    }

    private function validate(int $bookingId, string $product, array &$snapshot): void
    {
        $required = match ($product) { 'air' => ['booking_passenger_id','from','to','departure_at','sale_price'], 'hotel' => ['city','hotel_name','room_type','board','check_in','check_out','sale_rate'], 'transport' => ['from_location','to_location','vehicle_type','sale_price'], default => ['booking_passenger_id','country','visa_type','sale_price'] };
        foreach ($required as $field) if (! array_key_exists($field, $snapshot) || $snapshot[$field] === '' || $snapshot[$field] === null) throw new \InvalidArgumentException('Required field missing: '.$field);
        foreach (['sale_price','cost_price','sale_rate','cost_rate'] as $field) if (array_key_exists($field, $snapshot) && $snapshot[$field] !== '' && ! is_numeric($snapshot[$field])) throw new \InvalidArgumentException('Commercial amounts must be numeric.');
        $sale = (float) ($snapshot['sale_price'] ?? $snapshot['sale_rate'] ?? 0); $cost = (float) ($snapshot['cost_price'] ?? $snapshot['cost_rate'] ?? 0);
        if ($sale < 0 || $cost < 0) throw new \InvalidArgumentException('Negative commercial amounts are not allowed.');
        if ($cost > 0 && empty($snapshot['vendor_id'])) throw new \InvalidArgumentException('A valid vendor is required when cost is positive.');
        if (in_array($product, ['air','visa'], true)) {
            $id = (int) ($snapshot['booking_passenger_id'] ?? 0); $passenger = collect($this->passengers->rows($bookingId))->first(fn ($p) => (int) $p->id === $id);
            if (! $passenger) throw new \InvalidArgumentException('Passenger must be active and belong to this booking.');
            $name = trim((string) ($passenger->name ?? $passenger->full_name ?? $passenger->passenger_name ?? trim((string) ($passenger->first_name ?? $passenger->given_name ?? '').' '.(string) ($passenger->last_name ?? $passenger->surname ?? ''))));
            $snapshot['passenger_snapshot'] = ['id' => $id, 'name' => $name, 'fare_type' => (string) ($passenger->fare_as ?? $passenger->fare_type ?? $passenger->age_type ?? $passenger->passenger_type ?? $passenger->pax_type ?? ''), 'passport_number' => (string) ($passenger->passport_number ?? $passenger->passport_no ?? $passenger->passport ?? '')];
        }
        if ($product === 'hotel') { $in = new DateTimeImmutable((string) $snapshot['check_in']); $out = new DateTimeImmutable((string) $snapshot['check_out']); if ($out <= $in || $in->diff($out)->days < 1) throw new \InvalidArgumentException('Hotel check-out must be after check-in.'); }
        if ($snapshot['vendor_id'] ?? null) { $vendor = collect($this->vendorOptions())->first(fn (array $v) => (int) $v['id'] === (int) $snapshot['vendor_id']); if (! $vendor) throw new \InvalidArgumentException('Vendor is not valid.'); $snapshot['vendor_name'] = $vendor['name']; }
        else { $snapshot['vendor_id'] = null; $snapshot['vendor_name'] = null; }
        if ($product === 'air' && ($snapshot['airline_id'] ?? null)) { $airline = collect($this->airlineOptions())->first(fn (array $v) => (int) $v['id'] === (int) $snapshot['airline_id']); if (! $airline) throw new \InvalidArgumentException('Airline is not valid.'); $snapshot['airline_name'] = $airline['name']; $snapshot['airline_code'] = $airline['code']; }
        elseif ($product === 'air') { $snapshot['airline_code'] = null; $snapshot['airline_name'] = trim((string) ($snapshot['airline_name'] ?? '')) ?: null; }
    }

    private function commercial(string $product, array $s, object $batch): array
    {
        $cost = (float) ($s['cost_price'] ?? $s['cost_rate'] ?? 0); $sale = (float) ($s['sale_price'] ?? $s['sale_rate'] ?? 0); $quantity = 1;
        if ($product === 'hotel') { $quantity = max(1, (new DateTimeImmutable((string) $s['check_in']))->diff(new DateTimeImmutable((string) $s['check_out']))->days); $sale *= $quantity; $cost *= $quantity; }
        $description = match ($product) { 'air' => 'Air · '.($s['from'] ?? '').' → '.($s['to'] ?? '').' · '.($s['passenger_snapshot']['name'] ?? 'Passenger'), 'hotel' => 'Hotel · '.($s['city'] ?? '').' · '.($s['hotel_name'] ?? '').' · '.$quantity.' Nights', 'transport' => 'Transport · '.($s['from_location'] ?? '').' → '.($s['to_location'] ?? '').' · '.($s['vehicle_type'] ?? ''), default => 'Visa · '.($s['visa_type'] ?? '').' · '.($s['passenger_snapshot']['name'] ?? 'Passenger') };
        return ['quantity' => $quantity, 'unit_price' => round($sale / $quantity, 2), 'sale_amount' => round($sale, 2), 'cost' => round($cost, 2), 'margin' => round($sale - $cost, 2), 'description' => $description];
    }

    private function recalculate(int $batchId, object $batch): void
    {
        $items = DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->get(['sale_amount','supplier_cost_snapshot','source_hash']);
        $subtotal = round((float) $items->sum('sale_amount'), 2); $cost = round((float) $items->sum('supplier_cost_snapshot'), 2); $hashes = $items->pluck('source_hash')->sort()->values()->all();
        DB::table('general_booking_billing_batches')->where('id', $batchId)->update(['customer_subtotal' => $subtotal, 'discount_total' => 0, 'customer_total' => $subtotal, 'supplier_cost_total' => $cost, 'agent_commission_total' => 0, 'salesperson_commission_total' => 0, 'margin_total' => round($subtotal - $cost, 2), 'source_snapshot_hash' => hash('sha256', json_encode(['items' => $hashes, 'currency' => $batch->currency_code, 'rate' => $batch->exchange_rate])), 'lock_version' => ((int) $batch->lock_version) + 1, 'updated_at' => now()]);
    }

    private function duplicateAir(int $batchId, array $snapshot, ?int $excludeId = null): bool
    {
        $key = fn (array $v): string => (string) ((int) ($v['booking_passenger_id'] ?? 0)).'|'.strtolower(preg_replace('/\s+/', ' ', trim((string) ($v['from'] ?? '')))).'|'.strtolower(preg_replace('/\s+/', ' ', trim((string) ($v['to'] ?? '')))).'|'.str_replace(' ', 'T', (string) ($v['departure_at'] ?? ''));
        $wanted = $key($snapshot);
        return DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->where('product_type', 'air')->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))->get(['product_snapshot'])->contains(function ($row) use ($wanted, $key): bool { $saved = json_decode((string) $row->product_snapshot, true); return is_array($saved) && $key($saved) === $wanted; });
    }

    private function hash(array $snapshot, array $commercial): string { ksort($snapshot); return hash('sha256', json_encode(['snapshot' => $this->sort($snapshot), 'commercial' => $commercial], JSON_UNESCAPED_SLASHES)); }
    private function sort(array $value): array { foreach ($value as $k => $v) if (is_array($v)) $value[$k] = $this->sort($v); ksort($value); return $value; }
    private function passengerOptions(int $bookingId): array { return collect($this->passengers->rows($bookingId))->map(fn ($p) => ['id' => (int) $p->id, 'name' => trim((string) ($p->name ?? $p->full_name ?? $p->passenger_name ?? trim((string) ($p->first_name ?? $p->given_name ?? '').' '.(string) ($p->last_name ?? $p->surname ?? ''))))])->all(); }
    private function foundationReady(): bool { return Schema::hasTable('general_booking_billing_batches') && Schema::hasTable('general_booking_billing_batch_items') && Schema::hasTable('general_booking_invoice_links'); }
    private function assertFoundationReady(): void { if (! $this->foundationReady()) throw new \InvalidArgumentException('Additional Services requires the General Booking Billing database upgrade.'); }
    private function normalizeIdentifier(mixed $value, string $field): ?int { if ($value === null || $value === '') return null; if (is_int($value) && $value > 0) return $value; if (is_string($value) && preg_match('/^[1-9]\d*$/', trim($value))) return (int) trim($value); throw new \InvalidArgumentException('Invalid '.$field.'.'); }
    private function vendorOptions(): array { return $this->catalog->vendors()->map(fn ($v) => ['id' => (int) ($v->id ?? $v['id'] ?? 0), 'name' => (string) ($v->name ?? $v->party_name ?? $v['name'] ?? '')])->filter(fn (array $v) => $v['id'] > 0)->values()->all(); }
    private function airlineOptions(): array { return $this->catalog->airlines()->map(fn ($v) => ['id' => (int) ($v['id'] ?? $v['id'] ?? 0), 'name' => (string) ($v['name'] ?? $v['airline_name'] ?? ''), 'code' => (string) ($v['code'] ?? $v['airline_code'] ?? '')])->filter(fn (array $v) => $v['id'] > 0)->values()->all(); }
    private function canonicalDate(string $value): string { $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); $errors = DateTimeImmutable::getLastErrors(); if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || $date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) throw new \InvalidArgumentException('Invalid date format.'); return $date->format('Y-m-d'); }
    private function canonicalDateTime(string $value): string { $value = str_replace('T', ' ', $value); $format = strlen($value) > 16 ? '!Y-m-d H:i:s' : '!Y-m-d H:i'; $date = DateTimeImmutable::createFromFormat($format, $value); $errors = DateTimeImmutable::getLastErrors(); if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/', $value) || $date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) throw new \InvalidArgumentException('Invalid date/time format.'); return $date->format('Y-m-d H:i:s'); }
    private function fail(string $code, string $message): array { return ['ok' => false, 'status' => $code, 'message' => $message]; }
    private function createToken(int $bookingId, int $batchId, string $product): string { return Crypt::encryptString(json_encode(['booking_id' => $bookingId, 'batch_id' => $batchId, 'product' => $product, 'nonce' => (string) Str::uuid()])); }
    private function tokenPayload(string $token): array { try { $value = json_decode(Crypt::decryptString($token), true); return is_array($value) ? $value : []; } catch (\Throwable) { return []; } }
}
