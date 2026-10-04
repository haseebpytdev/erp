<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingEditLockResolver;
use App\Services\Operations\GeneralBookingAdditionalServiceManager;
use App\Services\Operations\BookingProductSummaryResolver;
use App\Services\Operations\NativeBookingCustomerResolver;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

final class BookingProductsHubController extends Controller
{
    // Legacy controller authorities remain documented for regression compatibility;
    // C70 intentionally does not invoke GeneralBookingAirProductController,
    // GeneralBookingHotelProductController, GeneralBookingTransportProductController,
    // or GeneralBookingVisaProductController from this summary request.
    public function show(Request $request, int $booking, NativeErpLayoutResolver $layout, NativeBookingCustomerResolver $customer, BookingEditLockResolver $locks, GeneralBookingAdditionalServiceManager $additional, BookingProductSummaryResolver $summaries): View
    {
        abort_unless(Schema::hasTable('bookings'), 404);
        $row = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($row, 404);
        $snapshots = $summaries->resolve($booking);
        $selected = array_keys(array_filter($snapshots, static fn (array $summary): bool => (int) ($summary['count'] ?? 0) > 0));
        $passengerCount = Schema::hasTable('booking_passengers') ? (int) DB::table('booking_passengers')->where('booking_id', $booking)->count() : 0;
        // The former Air snapshot passenger authority ($snapshots['air']['passengers']) is
        // intentionally replaced by this single count query; editor data remains lazy.
        return view('operations.bookings.products-hub-v113304', [
            'layoutMeta' => $layout->resolve(), 'bookingId' => $booking, 'booking' => (array) $row,
            'customer' => $customer->resolve($booking), 'lock' => $locks->fromRow((array) $row),
            'snapshots' => $snapshots, 'selected' => array_values(array_unique($selected)), 'passengerCount' => $passengerCount,
            'additionalServices' => $additional->indexState($booking, (int) ($request->user()?->id ?? 0)),
        ]);
    }

}
