<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingTravelReadinessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class GeneralBookingOperationalSummaryController extends Controller
{
    public function show(Request $request, int $booking, BookingTravelReadinessResolver $readiness): JsonResponse
    {
        abort_unless(Schema::hasTable('bookings'), 404);
        $bookingRow = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($bookingRow, 404);

        $air = $this->snapshot(fn () => app(GeneralBookingAirProductController::class)->show($request, $booking)->getData(true));
        $hotel = $this->snapshot(fn () => app(GeneralBookingHotelProductController::class)->show($request, $booking)->getData(true));
        $transport = $this->snapshot(fn () => app(GeneralBookingTransportProductController::class)->show($request, $booking)->getData(true));
        $visa = $this->snapshot(fn () => app(GeneralBookingVisaProductController::class)->show($request, $booking)->getData(true));

        $totals = [
            'air' => $this->customerTotal($air),
            'hotel' => $this->customerTotal($hotel),
            'transport' => $this->customerTotal($transport),
            'visa' => $this->customerTotal($visa),
        ];
        $selected = $this->selectedProducts($request, $air, $hotel, $transport, $visa);
        $state = $readiness->resolve((array) $bookingRow, $selected, $air, $hotel, $transport, $visa);
        $currency = strtoupper(trim((string) (($air['capabilities']['booking_currency'] ?? null) ?: ($hotel['booking']['currency'] ?? null) ?: 'PKR')));

        return response()->json([
            'ok' => true,
            'booking_id' => $booking,
            'currency' => $currency ?: 'PKR',
            'product_customer_totals' => $totals,
            'booking_value' => array_sum($totals),
            'travel_status' => $state['status'],
            'travel_ready' => $state['ready'],
            'readiness_blockers' => $state['blockers'],
        ]);
    }

    private function snapshot(callable $callback): array
    {
        try {
            $value = $callback();
            return is_array($value) ? $value : [];
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function customerTotal(array $snapshot): float
    {
        return max(0, (float) ($snapshot['summary']['customer_total'] ?? 0));
    }

    private function selectedProducts(Request $request, array $air, array $hotel, array $transport, array $visa): array
    {
        $requested = array_filter(explode(',', (string) $request->query('selected_products', '')));
        $selected = array_map(static fn ($v): string => strtolower(trim((string) $v)), $requested);
        if ((array) ($air['itinerary'] ?? []) || (array) ($air['tickets'] ?? [])) $selected[] = 'air';
        if ((array) ($hotel['stays'] ?? [])) $selected[] = 'hotel';
        if ((array) ($transport['transports'] ?? [])) $selected[] = 'transport';
        if ((array) ($visa['visa_rows'] ?? [])) $selected[] = 'visa';
        return array_values(array_unique(array_intersect($selected, ['air', 'hotel', 'transport', 'visa'])));
    }
}
