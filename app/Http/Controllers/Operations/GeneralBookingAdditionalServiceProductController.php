<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\GeneralBookingAdditionalServiceItemManager;
use App\Services\Operations\BookingEditLockResolver;
use App\Services\Operations\NativeBookingCustomerResolver;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Operations\ProductWorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class GeneralBookingAdditionalServiceProductController extends Controller
{
    private const PRODUCTS = ['air', 'hotel', 'transport', 'visa'];

    /** Native read contract over a supplementary batch; base rows are replaced
     * by the current batch snapshots before the response reaches the UI. */
    public function apiShow(Request $request, int $booking, int $batch, string $product, GeneralBookingAdditionalServiceItemManager $items): JsonResponse
    {
        $product = $this->productKey($product);
        $state = $items->editor($booking, $batch, $product);
        abort_if(($state['batch_missing'] ?? false) || ($state['schema_ready'] ?? true) === false, 404);
        $request->attributes->set('supplementary_product_read_only', true);
        $native = match ($product) {
            'air' => app(\App\Http\Controllers\Operations\GeneralBookingAirProductController::class)->show($request, $booking),
            'hotel' => app(\App\Http\Controllers\Operations\GeneralBookingHotelProductController::class)->show($request, $booking),
            'transport' => app(\App\Http\Controllers\Operations\GeneralBookingTransportProductController::class)->show($request, $booking),
            'visa' => app(\App\Http\Controllers\Operations\GeneralBookingVisaProductController::class)->show($request, $booking),
        };
        $payload = $native->getData(true);
        $snapshots = collect($state['items'] ?? [])->filter(fn (array $row): bool => strtolower((string) ($row['product_type'] ?? '')) === $product)->map(function (array $row): array {
            $snapshot = json_decode((string) ($row['product_snapshot'] ?? ''), true);
            return is_array($snapshot) ? $snapshot + ['supplementary_item_id' => (int) ($row['id'] ?? 0)] : [];
        })->filter(fn (array $row): bool => $row !== [])->values()->all();
        if ($product === 'air') {
            $payload = array_merge($payload, $this->airReadProjection($snapshots));
            $payload['summary'] = [];
        }
        elseif ($product === 'hotel') { $payload['stays'] = $snapshots; $payload['summary'] = []; }
        elseif ($product === 'transport') { $payload['transports'] = $snapshots; $payload['summary'] = []; }
        else { $payload['visa_rows'] = $snapshots; $payload['summary'] = []; }
        $payload['supplementary_context'] = ['batch_id' => $batch, 'batch_no' => (int) ($state['batch']['batch_no'] ?? 0), 'product' => $product, 'writable' => (bool) ($state['writable'] ?? false)];
        return response()->json($payload);
    }

    /** Accept the native renderer payload while writing only batch items. */
    public function apiStore(Request $request, int $booking, int $batch, string $product, GeneralBookingAdditionalServiceItemManager $items): JsonResponse
    {
        $product = $this->productKey($product);
        $state = $items->editor($booking, $batch, $product);
        abort_if(($state['batch_missing'] ?? false) || ($state['schema_ready'] ?? true) === false, 404);
        abort_unless(($state['writable'] ?? false) === true, 409, 'Only a writable supplementary Draft batch can be edited.');
        if ($product === 'air') {
            foreach ($this->projectAirPayload($booking, $request->all(), $items) as $projected) {
                $items->upsertAirProjected($booking, $batch, $projected['snapshot'], $projected['source_key']);
            }
            return $this->apiShow($request, $booking, $batch, $product, $items);
        }
        $rows = match ($product) { 'hotel' => $request->input('stays', []), 'transport' => $request->input('transports', []), 'visa' => $request->input('visas', $request->input('visa_rows', [])) };
        if (! is_array($rows)) $rows = [];
        $existing = collect($items->editor($booking, $batch, $product)['items'] ?? [])->filter(fn (array $row): bool => strtolower((string) ($row['product_type'] ?? '')) === $product)->values();
        foreach (array_values($rows) as $index => $row) { if (! is_array($row)) continue; $row = $this->nativeSnapshot($product, $row); $current = $existing->get($index); if ($current) $items->update($booking, $batch, (int) $current['id'], $product, $row); else { $editor = $items->editor($booking, $batch, $product); $items->create($booking, $batch, $product, $row + ['draft_item_token' => $editor['draft_item_token']], (int) ($request->user()?->id ?? 0)); } }
        return $this->apiShow($request, $booking, $batch, $product, $items);
    }

    private function nativeSnapshot(string $product, array $row): array
    {
        if ($product === 'transport') { $row['sale_price'] ??= $row['sale_amount'] ?? 0; $row['cost_price'] ??= $row['cost_amount'] ?? $row['cost_rate'] ?? 0; $row['from_location'] ??= $row['from'] ?? $row['route_name'] ?? ''; $row['to_location'] ??= $row['to'] ?? $row['route_name'] ?? ''; }
        if ($product === 'visa') { $row['sale_price'] ??= $row['sale_pkr'] ?? 0; $row['cost_price'] ??= $row['cost_rate'] ?? 0; }
        if ($product === 'air') { $row['sale_price'] ??= $row['sale_amount'] ?? $row['customer_total'] ?? 0; $row['cost_price'] ??= $row['cost_amount'] ?? $row['supplier_total'] ?? 0; }
        return $row;
    }

    /** Project the native Air payload into stable supplementary passenger/group snapshots. */
    private function projectAirPayload(int $booking, array $payload, GeneralBookingAdditionalServiceItemManager $items): array
    {
        $groups = is_array($payload['ticket_groups'] ?? null) && $payload['ticket_groups'] !== []
            ? array_values($payload['ticket_groups'])
            : [[
                'common' => is_array($payload['common'] ?? null) ? $payload['common'] : [],
                'segments' => is_array($payload['segments'] ?? null) ? $payload['segments'] : [],
                'tickets' => is_array($payload['tickets'] ?? null) ? $payload['tickets'] : [],
                'fare_commercials' => is_array($payload['fare_commercials'] ?? null) ? $payload['fare_commercials'] : [],
            ]];
        $out = [];
        foreach ($groups as $groupIndex => $group) {
            if (! is_array($group)) continue;
            $common = is_array($group['common'] ?? null) ? $group['common'] : [];
            $segments = is_array($group['segments'] ?? null) ? $group['segments'] : $this->segmentsForKeys($payload['segments'] ?? [], $group['segment_keys'] ?? []);
            $tickets = is_array($group['tickets'] ?? null) ? $group['tickets'] : [];
            $fares = is_array($group['fare_commercials'] ?? null) ? $group['fare_commercials'] : [];
            $groupKey = hash('sha256', json_encode([
                $common['supplier_id'] ?? $common['vendor_id'] ?? null,
                $common['pnr'] ?? null, $common['airline_pnr'] ?? null,
                $common['airline_id'] ?? null, $common['airline_code'] ?? null,
                array_map(fn (array $s): array => [$s['from'] ?? null, $s['to'] ?? null, $s['departure_at'] ?? null, $s['arrival_at'] ?? null, $s['flight_number'] ?? null], $segments),
            ], JSON_UNESCAPED_SLASHES));
            $counts = [];
            foreach ($tickets as $ticket) {
                if (! is_array($ticket)) continue;
                $type = strtoupper((string) ($items->passengerSnapshotFor($booking, (int) ($ticket['booking_passenger_id'] ?? 0))['fare_type'] ?? ''));
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
            foreach ($tickets as $ticket) {
                if (! is_array($ticket)) continue;
                $passengerId = (int) ($ticket['booking_passenger_id'] ?? 0);
                $passenger = $items->passengerSnapshotFor($booking, $passengerId);
                if (! $passenger) throw new \InvalidArgumentException('Passenger must be active and belong to this booking.');
                $fareType = strtoupper((string) ($passenger['fare_type'] ?? ''));
                $fare = collect($fares)->first(fn ($f): bool => strtoupper((string) ($f['fare_type'] ?? '')) === $fareType);
                if (! is_array($fare)) throw new \InvalidArgumentException('Air fare row is missing for the authoritative passenger type.');
                $paxCount = max(1, (int) ($fare['pax_count'] ?? $counts[$fareType] ?? 1));
                $basic = max(0, (float) ($fare['basic_rate'] ?? 0));
                $customerMinus = $this->discountAmount($basic, $fare['customer_minus_type'] ?? null, $fare['customer_minus_value'] ?? 0);
                $vendorMinus = $this->discountAmount($basic, $fare['vendor_minus_type'] ?? null, $fare['vendor_minus_value'] ?? 0);
                $customerNet = max(0, (float) ($fare['sale_price'] ?? $fare['selling_price'] ?? $basic) - $customerMinus);
                $vendorBaseNet = max(0, (float) ($fare['cost_price'] ?? $fare['purchase_price'] ?? $basic) - $vendorMinus);
                $vendorOther = (float) ($fare['vendor_other_cost'] ?? 0);
                $snapshot = array_merge($ticket, [
                    'booking_passenger_id' => $passengerId, 'passenger_snapshot' => $passenger,
                    'vendor_id' => (int) ($common['supplier_id'] ?? $common['vendor_id'] ?? $ticket['vendor_id'] ?? 0) ?: null,
                    'vendor_name' => $common['supplier_name'] ?? $common['vendor_name'] ?? null,
                    'airline_id' => $this->firstValue($common, ['airline_id']) ?? ($ticket['airline_id'] ?? null),
                    'airline_code' => $this->firstValue($common, ['airline_code']) ?? ($ticket['airline_code'] ?? null),
                    'airline_name' => $this->firstValue($common, ['airline']) ?? ($ticket['airline_name'] ?? null),
                    'pnr' => $common['pnr'] ?? $ticket['pnr'] ?? null,
                    'airline_pnr' => $common['airline_pnr'] ?? null, 'ticket_status' => $common['ticket_status'] ?? null,
                    'from' => $ticket['from'] ?? ($segments[0]['from'] ?? null), 'to' => $ticket['to'] ?? ($segments[0]['to'] ?? null),
                    'departure_at' => $ticket['departure_at'] ?? ($segments[0]['departure_at'] ?? null),
                    'arrival_at' => $ticket['arrival_at'] ?? ($segments[0]['arrival_at'] ?? null),
                    'segments' => $segments, 'itinerary' => $segments, 'common' => $common, 'group_common' => $common,
                    'fare_commercials' => $fares, 'ticket_groups' => [$group], 'native_air_group_key' => $groupKey,
                    'native_sale_price' => $customerNet, 'native_cost_price' => $vendorBaseNet,
                    'sale_price' => round($customerNet, 2), 'cost_price' => round($vendorBaseNet + ($vendorOther / $paxCount), 2),
                ]);
                $out[] = ['source_key' => 'air:'.$groupKey.':'.$passengerId, 'snapshot' => $snapshot];
            }
        }
        return $out;
    }

    private function airReadProjection(array $snapshots): array
    {
        $groups = [];
        foreach ($snapshots as $snapshot) {
            $key = (string) ($snapshot['native_air_group_key'] ?? 'legacy:'.hash('sha256', json_encode($snapshot['segments'] ?? [$snapshot], JSON_UNESCAPED_SLASHES)));
            $groups[$key] ??= ['common'=>$snapshot['common'] ?? $snapshot['group_common'] ?? [], 'segments'=>$snapshot['segments'] ?? $snapshot['itinerary'] ?? [], 'tickets'=>[], 'fare_commercials'=>$snapshot['fare_commercials'] ?? []];
            $groups[$key]['tickets'][] = $snapshot;
        }
        $groups = array_values($groups); $segments = [];
        foreach ($groups as $group) foreach ($group['segments'] as $segment) { $fingerprint = json_encode($segment, JSON_UNESCAPED_SLASHES); if (! collect($segments)->contains(fn ($s): bool => json_encode($s, JSON_UNESCAPED_SLASHES) === $fingerprint)) $segments[] = $segment; }
        return ['tickets'=>array_merge(...array_map(fn ($g) => $g['tickets'], $groups ?: [[]])), 'ticket_groups'=>$groups, 'segments'=>$segments, 'itinerary'=>$segments, 'common'=>$groups[0]['common'] ?? [], 'fare_commercials'=>$groups[0]['fare_commercials'] ?? []];
    }

    private function discountAmount(float $base, mixed $type, mixed $value): float
    { $value = max(0, (float) $value); return strtolower((string) $type) === 'percent' ? min($base, round($base * min(100, $value) / 100, 2)) : min($base, $value); }
    private function segmentsForKeys(mixed $segments, mixed $keys): array { if (! is_array($segments)) return []; if (! is_array($keys) || $keys === []) return array_values($segments); $wanted = array_fill_keys(array_map('strval', $keys), true); return array_values(array_filter($segments, fn ($s, $i): bool => isset($wanted[(string) ($s['key'] ?? $s['segment_key'] ?? $i)]), ARRAY_FILTER_USE_BOTH)); }
    private function firstValue(array $row, array $keys): mixed { foreach ($keys as $key) if (isset($row[$key]) && $row[$key] !== '') return $row[$key]; return null; }

    public function apiTransportSelection(Request $request, int $booking, int $batch, GeneralBookingAdditionalServiceItemManager $items): JsonResponse
    {
        $state = $items->editor($booking, $batch, 'transport');
        abort_if(($state['batch_missing'] ?? false) || ! ($state['writable'] ?? false), 409);
        return response()->json(['ok' => true, 'supplementary_context' => ['batch_id' => $batch, 'batch_no' => (int) ($state['batch']['batch_no'] ?? 0), 'writable' => (bool) ($state['writable'] ?? false)]]);
    }

    public function edit(Request $request, int $booking, int $batch, string $product, GeneralBookingAdditionalServiceItemManager $items, NativeErpLayoutResolver $layout, BookingEditLockResolver $locks, NativeBookingCustomerResolver $customer): View
    {
        $product = $this->productKey($product);
        $state = $items->editor($booking, $batch, $product, $request->integer('item') ?: null);
        abort_if(($state['batch_missing'] ?? false) || ($state['item_missing'] ?? false), 404);
        $bookingRow = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($bookingRow, 404);
        return view('operations.bookings.additional-services.product', [
            'layoutMeta' => $layout->resolve(),
            'bookingId' => $booking,
            'booking' => (array) $bookingRow,
            'customer' => $customer->resolve($booking),
            'lock' => $locks->fromRow((array) $bookingRow),
            'selectedProducts' => [],
            'batchId' => $batch,
            'state' => $state,
            'product' => $product,
            'context' => new ProductWorkspaceContext($booking, $product, 'SUPPLEMENTARY', $batch, (int) (($state['batch']['batch_no'] ?? 0))),
        ]);
    }

    public function store(Request $request, int $booking, int $batch, string $product, GeneralBookingAdditionalServiceItemManager $items): RedirectResponse
    {
        $product = $this->productKey($product);
        try { $result = $items->create($booking, $batch, $product, $request->except(['_token']), (int) ($request->user()?->id ?? 0)); }
        catch (\InvalidArgumentException $e) { return back()->withErrors(['product' => $e->getMessage()]); }
        catch (Throwable $e) { report($e); return back()->withErrors(['product' => 'The supplementary item could not be saved safely.']); }
        if (! ($result['ok'] ?? false)) return back()->withErrors(['product' => $result['message'] ?? 'Draft item could not be saved.'])->withInput();
        return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch]);
    }

    public function update(Request $request, int $booking, int $batch, string $product, int $item, GeneralBookingAdditionalServiceItemManager $items): RedirectResponse
    {
        $product = $this->productKey($product);
        try { $result = $items->update($booking, $batch, $item, $product, $request->except(['_token','_method'])); }
        catch (\InvalidArgumentException $e) { return back()->withErrors(['product' => $e->getMessage()]); }
        catch (Throwable $e) { report($e); return back()->withErrors(['product' => 'The supplementary item could not be updated safely.']); }
        if (! ($result['ok'] ?? false)) return back()->withErrors(['product' => $result['message'] ?? 'Draft item could not be updated.'])->withInput();
        return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch]);
    }

    public function destroy(int $booking, int $batch, string $product, int $item, GeneralBookingAdditionalServiceItemManager $items): RedirectResponse
    {
        $product = $this->productKey($product);
        try { $result = $items->delete($booking, $batch, $item, $product); }
        catch (\InvalidArgumentException $e) { return back()->withErrors(['product' => $e->getMessage()]); }
        catch (Throwable $e) { report($e); return back()->withErrors(['product' => 'The supplementary item could not be removed safely.']); }
        if (! ($result['ok'] ?? false)) return back()->withErrors(['product' => $result['message'] ?? 'Draft item could not be removed.']);
        return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch]);
    }

    private function productKey(string $product): string
    {
        $product = strtolower(trim($product));
        abort_unless(in_array($product, self::PRODUCTS, true), 404);
        return $product;
    }
}
