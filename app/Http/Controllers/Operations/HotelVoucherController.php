<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HotelVoucherController extends Controller
{
    public function show(Request $request, Booking $booking): RedirectResponse
    {
        return redirect()->route('operations.bookings.travel-voucher.show',$booking);
    }

    public function stay(Request $request, Booking $booking, BookingService $service): RedirectResponse
    {
        abort_unless((int)$service->booking_id===(int)$booking->id,404);
        return redirect()->route('operations.bookings.travel-voucher.show',[
            'booking'=>$booking,
            'hotel_service_id'=>$service->id,
        ]);
    }
}
