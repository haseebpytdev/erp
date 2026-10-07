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
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class GeneralBookingAdditionalServiceProductController extends Controller
{
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
            'context' => new ProductWorkspaceContext($booking, $product, 'SUPPLEMENTARY', $batch),
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
