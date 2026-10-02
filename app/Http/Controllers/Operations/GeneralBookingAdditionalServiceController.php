<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\GeneralBookingAdditionalServiceManager;
use App\Services\Operations\NativeBookingCustomerResolver;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

final class GeneralBookingAdditionalServiceController extends Controller
{
    public function index(Request $request, int $booking, GeneralBookingAdditionalServiceManager $manager, NativeErpLayoutResolver $layout, NativeBookingCustomerResolver $customer): View
    {
        $state = $manager->indexState($booking, (int) ($request->user()?->id ?? 0));
        abort_if(($state['booking_missing'] ?? false) || (! Schema::hasTable('bookings') && ! ($state['schema_ready'] ?? false)), 404);
        return view('operations.bookings.additional-services.index', [
            'layoutMeta' => $layout->resolve(), 'bookingId' => $booking, 'state' => $state,
            'customer' => $customer->resolve($booking),
        ]);
    }

    public function start(Request $request, int $booking, GeneralBookingAdditionalServiceManager $manager): RedirectResponse
    {
        try {
            $result = $manager->start($booking, (int) ($request->user()?->id ?? 0));
        } catch (Throwable $e) {
            return redirect()->route('bookings.additional-services.index', $booking)->withErrors(['additional_services' => 'Additional Services could not be started safely.']);
        }
        if (($result['ok'] ?? false) && ! empty($result['batch_id'])) {
            return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $result['batch_id']]);
        }
        return redirect()->route('bookings.additional-services.index', $booking)->withErrors(['additional_services' => $result['message'] ?? 'Additional Services is not available for this booking.']);
    }

    public function show(Request $request, int $booking, int $batch, GeneralBookingAdditionalServiceManager $manager, NativeErpLayoutResolver $layout, NativeBookingCustomerResolver $customer): View
    {
        $state = $manager->show($booking, $batch);
        abort_if(($state['booking_missing'] ?? false) || (($state['schema_ready'] ?? false) && ($state['batch_missing'] ?? false)), 404);
        return view('operations.bookings.additional-services.show', [
            'layoutMeta' => $layout->resolve(), 'bookingId' => $booking, 'batchId' => $batch, 'state' => $state,
            'customer' => $customer->resolve($booking),
        ]);
    }
}
