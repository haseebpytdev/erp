<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\AirTicketDetail;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\HotelBookingDetail;
use App\Models\TransportBookingDetail;
use App\Models\VisaBookingDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class BookingPreviewController extends Controller
{
    public function show(Request $request, Booking $booking): View
    {
        $this->authorizeScope($request, $booking);

        $booking->load([
            'company',
            'branch',
            'customer',
            'agent',
            'passengers',
            'services.product',
            'services.passengers',
        ]);

        $activePassengers = $booking->passengers
            ->filter(fn ($passenger) => (bool) $passenger->is_active)
            ->values();

        $activeServices = $booking->services
            ->filter(fn ($service) => strtoupper((string) $service->status) !== 'CANCELLED')
            ->values();

        $serviceIds = $activeServices->pluck('id')->filter()->values();

        // Preview must remain available even if optional ERP-08 detail tables are
        // temporarily unavailable or contain legacy rows. Specialized sections
        // therefore degrade gracefully instead of taking the whole document down.
        $airTickets = collect();
        $hotelDetails = collect();
        $visaDetails = collect();
        $transportDetails = collect();
        $airlineNames = collect();
        $hotelRows = collect();
        $detailWarning = null;

        try {
            if ($serviceIds->isNotEmpty() && Schema::hasTable('air_ticket_details')) {
                $airTickets = AirTicketDetail::query()
                    ->whereIn('booking_service_id', $serviceIds->all())
                    ->get()
                    ->keyBy('booking_service_id');
            }
            if ($serviceIds->isNotEmpty() && Schema::hasTable('hotel_booking_details')) {
                $hotelDetails = HotelBookingDetail::query()
                    ->whereIn('booking_service_id', $serviceIds->all())
                    ->get()
                    ->keyBy('booking_service_id');
            }
            if ($serviceIds->isNotEmpty() && Schema::hasTable('visa_booking_details')) {
                $visaDetails = VisaBookingDetail::query()
                    ->whereIn('booking_service_id', $serviceIds->all())
                    ->get()
                    ->groupBy('booking_service_id');
            }
            if ($serviceIds->isNotEmpty() && Schema::hasTable('transport_booking_details')) {
                $transportDetails = TransportBookingDetail::query()
                    ->whereIn('booking_service_id', $serviceIds->all())
                    ->get()
                    ->keyBy('booking_service_id');
            }

            if ($airTickets->isNotEmpty() && Schema::hasTable('airlines')) {
                $airlineIds = $airTickets->pluck('airline_id')->filter()->unique()->values()->all();
                if ($airlineIds !== []) {
                    $airlineNames = Airline::query()->whereIn('id', $airlineIds)->pluck('name', 'id');
                }
            }

            if ($hotelDetails->isNotEmpty() && Schema::hasTable('hotels')) {
                $hotelIds = $hotelDetails->pluck('hotel_id')->filter()->unique()->values()->all();
                if ($hotelIds !== []) {
                    $hotelRows = Hotel::query()->whereIn('id', $hotelIds)->get(['id', 'name', 'city'])->keyBy('id');
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Booking preview specialized details were skipped.', [
                'booking_id' => $booking->id,
                'message' => $exception->getMessage(),
            ]);
            $airTickets = collect();
            $hotelDetails = collect();
            $visaDetails = collect();
            $transportDetails = collect();
            $airlineNames = collect();
            $hotelRows = collect();
            $detailWarning = 'Some optional travel detail sections could not be loaded. The booking confirmation below is still valid for the recorded booking, passengers, services and value.';
        }

        $passengerNames = $booking->passengers
            ->keyBy('id')
            ->map(fn ($passenger) => $passenger->fullName());

        return view('operations.bookings.preview', [
            'booking' => $booking,
            'activePassengers' => $activePassengers,
            'activeServices' => $activeServices,
            'bookingValue' => (float) $activeServices->sum(fn ($service) => (float) $service->line_total),
            'airTickets' => $airTickets,
            'hotelDetails' => $hotelDetails,
            'visaDetails' => $visaDetails,
            'transportDetails' => $transportDetails,
            'airlineNames' => $airlineNames,
            'hotelRows' => $hotelRows,
            'passengerNames' => $passengerNames,
            'detailWarning' => $detailWarning,
        ]);
    }

    private function authorizeScope(Request $request, Booking $booking): void
    {
        $user = $request->user();

        abort_unless($user, 403);

        if ($user->is_super_admin || $user->access_scope === 'ALL_BRANCHES') {
            $allowed = Branch::query()
                ->where('company_id', $booking->company_id)
                ->where('is_active', true)
                ->pluck('id');
        } else {
            $query = $user->branches()
                ->where('branches.company_id', $booking->company_id)
                ->where('branches.is_active', true);

            if ($user->access_scope === 'PRIMARY_BRANCH') {
                $query->wherePivot('is_primary', true);
            }

            $allowed = $query->pluck('branches.id');
        }

        abort_unless($allowed->contains($booking->branch_id), 403);
    }
}
