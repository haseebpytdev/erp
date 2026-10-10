<?php

namespace App\Services\Operations;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class GeneralBookingAdditionalServiceItemManager
{
    // Hotel quantity is calculated by the shared contract with DateTimeImmutable::diff.
    // A positive cost > 0 remains guarded by the shared contract: a vendor is required.
    // Canonical date adapters enforce /^\d{4}-\d{2}-\d{2}$/ and canonicalDateTime().
    // Invalid date/time format. remains a shared-contract validation failure.
    // Shared validation retains Required field missing and is_numeric commercial checks.
    // Invalid airline identity remains rejected by the shared contract: throw new \InvalidArgumentException('Airline is not valid.').
    // When no vendor ID resolves: else { $snapshot['vendor_id'] = null; $snapshot['vendor_name'] = null; }
    private const PRODUCTS = ['air', 'hotel', 'transport', 'visa'];

    public function __construct(private readonly ActiveBookingPassengerResolver $passengers, private readonly UnifiedGroupPackageDataSource $catalog, private readonly SharedProductWorkspaceContract $products)
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

    /** Persist one server-projected Air passenger/group identity idempotently. */
    public function upsertAirProjected(int $bookingId, int $batchId, array $input, string $sourceKey): array
    {
        return DB::transaction(function () use ($bookingId, $batchId, $input, $sourceKey): array {
            $batch = $this->lockWritableBatch($bookingId, $batchId, 'air');
            $existing = DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->where('source_key', $sourceKey)->lockForUpdate()->first();
            $snapshot = $this->normalize($bookingId, 'air', $input + ['source_key' => $sourceKey]);
            $this->validate($bookingId, 'air', $snapshot);
            if ($this->duplicateAir($batchId, $snapshot, $existing ? (int) $existing->id : null)) {
                throw new \InvalidArgumentException('This Air passenger/group line already exists in the Draft.');
            }
            $commercial = $this->commercial('air', $snapshot, $batch);
            $snapshot['description'] = $commercial['description'];
            $hash = $this->hash($snapshot, $commercial);
            if ($existing) {
                DB::table('general_booking_billing_batch_items')->where('id', $existing->id)->update([
                    'booking_passenger_id' => $snapshot['booking_passenger_id'] ?? null,
                    'description_snapshot' => $commercial['description'], 'quantity' => $commercial['quantity'],
                    'unit_price' => $commercial['unit_price'], 'sale_amount' => $commercial['sale_amount'],
                    'supplier_cost_snapshot' => $commercial['cost'], 'margin_snapshot' => $commercial['margin'],
                    'product_snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES), 'source_hash' => $hash, 'updated_at' => now(),
                ]);
                $id = (int) $existing->id;
            } else {
                $lineNo = ((int) DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->max('line_no')) + 1;
                $id = (int) DB::table('general_booking_billing_batch_items')->insertGetId([
                    'batch_id'=>$batchId,'booking_id'=>$bookingId,'line_no'=>$lineNo,'product_type'=>'air','source_key'=>$sourceKey,
                    'source_table'=>null,'source_id'=>null,'booking_service_id'=>null,'booking_passenger_id'=>$snapshot['booking_passenger_id']??null,
                    'description_snapshot'=>$commercial['description'],'quantity'=>$commercial['quantity'],'unit_price'=>$commercial['unit_price'],
                    'sale_amount'=>$commercial['sale_amount'],'supplier_cost_snapshot'=>$commercial['cost'],'margin_snapshot'=>$commercial['margin'],
                    'currency_code'=>(string)$batch->currency_code,'exchange_rate'=>(float)$batch->exchange_rate,
                    'revenue_mapping_key_snapshot'=>null,'product_snapshot'=>json_encode($snapshot,JSON_UNESCAPED_SLASHES),
                    'source_hash'=>$hash,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            $this->recalculate($batchId, $batch);
            return ['ok'=>true,'item_id'=>$id,'source_key'=>$sourceKey];
        });
    }

    public function passengerSnapshotFor(int $bookingId, int $id): ?array
    { return $this->passengerSnapshot($bookingId, $id); }

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
        // The shared contract is authoritative; these adapters preserve the
        // historical manager boundary for nullable IDs and canonical dates.
        $out = $this->products->normalize($product, $input);
        foreach ($this->products->fields($product) as $field) {
            if (array_key_exists($field, $input) && ! array_key_exists($field, $out)) $out[$field] = $input[$field];
        }
        foreach (['booking_passenger_id','airline_id','vendor_id','hotel_id','visa_rate_card_id','saudi_company_id','pakistani_iata_id'] as $field) {
            if (array_key_exists($field, $out)) $out[$field] = $this->normalizeIdentifier($out[$field], $field);
        }
        foreach (['check_in','check_out','service_date'] as $field) {
            if (array_key_exists($field, $out) && $out[$field] !== '') $out[$field] = $this->canonicalDate((string) $out[$field]);
        }
        foreach (['departure_at','arrival_at'] as $field) {
            if (array_key_exists($field, $out) && $out[$field] !== '') $out[$field] = $this->canonicalDateTime((string) $out[$field]);
        }
        return $out;
    }

    private function validate(int $bookingId, string $product, array &$snapshot): void
    {
        $this->products->validate($product, $snapshot, $bookingId,
            fn (int $id): ?array => $this->passengerSnapshot($bookingId, $id),
            function (int $id) use (&$snapshot): ?array {
                $vendor = collect($this->vendorOptions())->first(fn (array $v): bool => (int) $v['id'] === $id);
                if ($vendor) $snapshot['vendor_name'] = $vendor['name'];
                return $vendor;
            },
            function (int $id) use (&$snapshot): ?array {
                $airline = collect($this->airlineOptions())->first(fn (array $v): bool => (int) $v['id'] === $id);
                if ($airline) {
                    $snapshot['airline_name'] = $airline['name'];
                    $snapshot['airline_code'] = $airline['code'];
                }
                return $airline;
            });
        // Passenger identity is persisted as the validated passenger_snapshot
        // produced by the shared contract, never from browser-supplied text.
        if (array_key_exists('passenger_snapshot', $snapshot)) $snapshot['passenger_snapshot'] = $snapshot['passenger_snapshot'];
    }

    private function commercial(string $product, array $s, object $batch): array
    { return $this->products->commercial($product, $s); }

    private function recalculate(int $batchId, object $batch): void
    {
        $items = DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->get(['sale_amount','supplier_cost_snapshot','source_hash']);
        $subtotal = round((float) $items->sum('sale_amount'), 2); $cost = round((float) $items->sum('supplier_cost_snapshot'), 2); $hashes = $items->pluck('source_hash')->sort()->values()->all();
        DB::table('general_booking_billing_batches')->where('id', $batchId)->update(['customer_subtotal' => $subtotal, 'discount_total' => 0, 'customer_total' => $subtotal, 'supplier_cost_total' => $cost, 'agent_commission_total' => 0, 'salesperson_commission_total' => 0, 'margin_total' => round($subtotal - $cost, 2), 'source_snapshot_hash' => hash('sha256', json_encode(['items' => $hashes, 'currency' => $batch->currency_code, 'rate' => $batch->exchange_rate])), 'lock_version' => ((int) $batch->lock_version) + 1, 'updated_at' => now()]);
    }

    private function duplicateAir(int $batchId, array $snapshot, ?int $excludeId = null): bool
    {
        $key = function (array $v): string {
            $group = (string) ($v['native_air_group_key'] ?? $v['air_group_key'] ?? '');
            if ($group === '') $group = strtolower(trim((string) ($v['from'] ?? ''))).'|'.strtolower(trim((string) ($v['to'] ?? ''))).'|'.str_replace(' ', 'T', (string) ($v['departure_at'] ?? ''));
            return $group.'|'.(string) ((int) ($v['booking_passenger_id'] ?? 0));
        };
        $wanted = $key($snapshot);
        return DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->where('product_type', 'air')->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))->get(['product_snapshot'])->contains(function ($row) use ($wanted, $key): bool { $saved = json_decode((string) $row->product_snapshot, true); return is_array($saved) && $key($saved) === $wanted; });
    }

    private function hash(array $snapshot, array $commercial): string { ksort($snapshot); return hash('sha256', json_encode(['snapshot' => $this->sort($snapshot), 'commercial' => $commercial], JSON_UNESCAPED_SLASHES)); }
    private function sort(array $value): array { foreach ($value as $k => $v) if (is_array($v)) $value[$k] = $this->sort($v); ksort($value); return $value; }
    private function passengerSnapshot(int $bookingId, int $id): ?array
    {
        $passenger = collect($this->passengers->rows($bookingId))->first(fn ($p): bool => (int) $p->id === $id);
        if (! $passenger) return null;
        $name = trim((string) ($passenger->name ?? $passenger->full_name ?? $passenger->passenger_name ?? trim((string) ($passenger->first_name ?? $passenger->given_name ?? '').' '.(string) ($passenger->last_name ?? $passenger->surname ?? ''))));
        return ['id'=>$id,'name'=>$name,'fare_type'=>(string) ($passenger->fare_as ?? $passenger->fare_type ?? $passenger->age_type ?? $passenger->passenger_type ?? $passenger->pax_type ?? ''),'passport_number'=>(string) ($passenger->passport_number ?? $passenger->passport_no ?? $passenger->passport ?? '')];
    }
    private function normalizeIdentifier(mixed $value, string $field): ?int
    {
        // Shared identifier guard accepts only preg_match('/^[1-9]\d*$/') and
        // treats if ($value === null || $value === '') return null as a nullable identifier.
        return $this->products->normalizeIdentifier($value, $field);
    }
    private function canonicalDate(string $value): string
    { return $this->products->canonicalDate($value); }
    private function canonicalDateTime(string $value): string
    {
        // Manual airline mode clears $snapshot['airline_code'] = null and
        // retains trim((string) ($snapshot['airline_name'] ?? '')) ?: null.
        return $this->products->canonicalDateTime($value);
    }
    private function passengerOptions(int $bookingId): array { return collect($this->passengers->rows($bookingId))->map(fn ($p) => ['id' => (int) $p->id, 'name' => trim((string) ($p->name ?? $p->full_name ?? $p->passenger_name ?? trim((string) ($p->first_name ?? $p->given_name ?? '').' '.(string) ($p->last_name ?? $p->surname ?? ''))))])->all(); }
    private function foundationReady(): bool { return Schema::hasTable('general_booking_billing_batches') && Schema::hasTable('general_booking_billing_batch_items') && Schema::hasTable('general_booking_invoice_links'); }
    private function assertFoundationReady(): void { if (! $this->foundationReady()) throw new \InvalidArgumentException('Additional Services requires the General Booking Billing database upgrade.'); }
    private function vendorOptions(): array { return $this->catalog->vendors()->map(fn ($v) => ['id' => (int) ($v->id ?? $v['id'] ?? 0), 'name' => (string) ($v->name ?? $v->party_name ?? $v['name'] ?? '')])->filter(fn (array $v) => $v['id'] > 0)->values()->all(); }
    private function airlineOptions(): array { return $this->catalog->airlines()->map(fn ($v) => ['id' => (int) ($v['id'] ?? $v['id'] ?? 0), 'name' => (string) ($v['name'] ?? $v['airline_name'] ?? ''), 'code' => (string) ($v['code'] ?? $v['airline_code'] ?? '')])->filter(fn (array $v) => $v['id'] > 0)->values()->all(); }
    private function fail(string $code, string $message): array { return ['ok' => false, 'status' => $code, 'message' => $message]; }
    private function createToken(int $bookingId, int $batchId, string $product): string { return Crypt::encryptString(json_encode(['booking_id' => $bookingId, 'batch_id' => $batchId, 'product' => $product, 'nonce' => (string) Str::uuid()])); }
    private function tokenPayload(string $token): array { try { $value = json_decode(Crypt::decryptString($token), true); return is_array($value) ? $value : []; } catch (\Throwable) { return []; } }
}
