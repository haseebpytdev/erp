<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingPassengerFinder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Secure POST entry point for the existing read-only Booking Register Finder.
 * The native BookingController remains the sole register data authority.
 */
final class BookingFinderController extends Controller
{
    public function search(Request $request, BookingPassengerFinder $finder): Response
    {
        $data = ['bookingFinder' => $finder->search($request)];

        return response()->view('operations.bookings.finder', $data)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
