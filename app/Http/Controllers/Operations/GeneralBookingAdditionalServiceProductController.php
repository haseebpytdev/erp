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
    /** Native read contract over a supplementary batch; base rows are replaced
     * by the current batch snapshots before the response reaches the UI. */
    public function apiShow(Request $request, int $booking, int $batch, string $product, GeneralBookingAdditionalServiceItemManager $items): JsonResponse
    {
        $state = $items->editor($booking, $batch, $product);
        abort_if(($state['batch_missing'] ?? false) || ($state['schema_ready'] ?? true) === false, 404);
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
        if ($product === 'air') { $payload['tickets'] = $snapshots; $payload['ticket_groups'] = $snapshots === [] ? [] : [['tickets' => $snapshots]]; $payload['itinerary'] = []; $payload['common'] = []; $payload['fare_commercials'] = []; $payload['summary'] = []; }
        elseif ($product === 'hotel') { $payload['stays'] = $snapshots; $payload['summary'] = []; }
        elseif ($product === 'transport') { $payload['transports'] = $snapshots; $payload['summary'] = []; }
        else { $payload['visa_rows'] = $snapshots; $payload['summary'] = []; }
        $payload['supplementary_context'] = ['batch_id' => $batch, 'batch_no' => (int) ($state['batch']['batch_no'] ?? 0), 'product' => $product];
        return response()->json($payload);
    }

    /** Accept the native renderer payload while writing only batch items. */
    public function apiStore(Request $request, int $booking, int $batch, string $product, GeneralBookingAdditionalServiceItemManager $items): JsonResponse
    {
        $rows = match ($product) { 'air' => $request->input('tickets', $request->input('ticket_groups.0.tickets', [])), 'hotel' => $request->input('stays', []), 'transport' => $request->input('transports', []), 'visa' => $request->input('visas', $request->input('visa_rows', [])) };
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

    public function apiTransportSelection(Request $request, int $booking, int $batch, GeneralBookingAdditionalServiceItemManager $items): JsonResponse
    {
        $state = $items->editor($booking, $batch, 'transport');
        abort_if(($state['batch_missing'] ?? false) || ! ($state['writable'] ?? false), 409);
        return response()->json(['ok' => true, 'supplementary_context' => ['batch_id' => $batch, 'batch_no' => (int) ($state['batch']['batch_no'] ?? 0)]]);
    }

    public function edit(Request $request, int $booking, int $batch, string $product, GeneralBookingAdditionalServiceItemManager $items, NativeErpLayoutResolver $layout, BookingEditLockResolver $locks, NativeBookingCustomerResolver $customer): View
    {
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
        try { $result = $items->create($booking, $batch, $product, $request->except(['_token']), (int) ($request->user()?->id ?? 0)); }
        catch (\InvalidArgumentException $e) { return back()->withErrors(['product' => $e->getMessage()]); }
        catch (Throwable $e) { report($e); return back()->withErrors(['product' => 'The supplementary item could not be saved safely.']); }
        if (! ($result['ok'] ?? false)) return back()->withErrors(['product' => $result['message'] ?? 'Draft item could not be saved.'])->withInput();
        return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch]);
    }

    public function update(Request $request, int $booking, int $batch, string $product, int $item, GeneralBookingAdditionalServiceItemManager $items): RedirectResponse
    {
        try { $result = $items->update($booking, $batch, $item, $product, $request->except(['_token','_method'])); }
        catch (\InvalidArgumentException $e) { return back()->withErrors(['product' => $e->getMessage()]); }
        catch (Throwable $e) { report($e); return back()->withErrors(['product' => 'The supplementary item could not be updated safely.']); }
        if (! ($result['ok'] ?? false)) return back()->withErrors(['product' => $result['message'] ?? 'Draft item could not be updated.'])->withInput();
        return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch]);
    }

    public function destroy(int $booking, int $batch, string $product, int $item, GeneralBookingAdditionalServiceItemManager $items): RedirectResponse
    {
        try { $result = $items->delete($booking, $batch, $item, $product); }
        catch (\InvalidArgumentException $e) { return back()->withErrors(['product' => $e->getMessage()]); }
        catch (Throwable $e) { report($e); return back()->withErrors(['product' => 'The supplementary item could not be removed safely.']); }
        if (! ($result['ok'] ?? false)) return back()->withErrors(['product' => $result['message'] ?? 'Draft item could not be removed.']);
        return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch]);
    }
}
