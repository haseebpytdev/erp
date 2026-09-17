<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingEditLockResolver;
use App\Services\Operations\BookingTravelReadinessResolver;
use App\Services\Operations\NativeBookingCustomerResolver;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

final class BookingProductsHubController extends Controller
{
    public function show(Request $request, int $booking, NativeErpLayoutResolver $layout, NativeBookingCustomerResolver $customer, BookingEditLockResolver $locks): View
    {
        abort_unless(Schema::hasTable('bookings'), 404);
        $row = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($row, 404);
        $snapshots = [
            'air' => $this->snapshot(fn () => app(GeneralBookingAirProductController::class)->show($request, $booking)->getData(true)),
            'hotel' => $this->snapshot(fn () => app(GeneralBookingHotelProductController::class)->show($request, $booking)->getData(true)),
            'transport' => $this->snapshot(fn () => app(GeneralBookingTransportProductController::class)->show($request, $booking)->getData(true)),
            'visa' => $this->snapshot(fn () => app(GeneralBookingVisaProductController::class)->show($request, $booking)->getData(true)),
        ];
        $selected = [];
        if (($snapshots['air']['itinerary'] ?? []) || ($snapshots['air']['tickets'] ?? [])) $selected[] = 'air';
        if ($snapshots['hotel']['stays'] ?? []) $selected[] = 'hotel';
        if (($snapshots['transport']['transports'] ?? []) || ($snapshots['transport']['services'] ?? [])) $selected[] = 'transport';
        if ($snapshots['visa']['visa_rows'] ?? []) $selected[] = 'visa';
        return view('operations.bookings.products-hub-v113304', [
            'layoutMeta' => $layout->resolve(), 'bookingId' => $booking, 'booking' => (array) $row,
            'customer' => $customer->resolve($booking), 'lock' => $locks->fromRow((array) $row),
            'snapshots' => $snapshots, 'selected' => array_values(array_unique($selected)),
        ]);
    }

    private function snapshot(callable $callback): array
    {
        try { $value = $callback(); return is_array($value) ? $value : []; } catch (Throwable) { return []; }
    }
}
