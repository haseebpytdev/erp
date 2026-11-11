<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\BookingItinerarySegment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookingItineraryController extends Controller
{
    public function store(Request $request, int $booking): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertBookingExists($booking);

        $nextSort = ((int) BookingItinerarySegment::where('booking_id', $booking)->max('sort_order')) + 10;

        BookingItinerarySegment::create([
            ...$data,
            'booking_id' => $booking,
            'sort_order' => $data['sort_order'] ?? $nextSort,
        ]);

        return back()->with('success', 'Flight segment added.');
    }

    public function update(Request $request, int $booking, BookingItinerarySegment $segment): RedirectResponse
    {
        abort_unless((int) $segment->booking_id === $booking, 404);
        $segment->update($this->validated($request));

        return back()->with('success', 'Flight segment updated.');
    }

    public function destroy(int $booking, BookingItinerarySegment $segment): RedirectResponse
    {
        abort_unless((int) $segment->booking_id === $booking, 404);
        $segment->delete();

        return back()->with('success', 'Flight segment removed.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'booking_service_id' => ['nullable', 'integer', 'min:1'],
            'segment_type' => ['required', Rule::in(['outbound', 'return', 'connection', 'other'])],
            'from_code' => ['required', 'string', 'max:32'],
            'to_code' => ['required', 'string', 'max:32'],
            'airline_name' => ['nullable', 'string', 'max:120'],
            'airline_code' => ['nullable', 'string', 'max:12'],
            'flight_number' => ['nullable', 'string', 'max:32'],
            'departure_at' => ['required', 'date'],
            'arrival_at' => ['nullable', 'date', 'after_or_equal:departure_at'],
            'pnr' => ['nullable', 'string', 'max:64'],
            'cabin' => ['nullable', 'string', 'max:40'],
            'baggage' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(['requested', 'booked', 'confirmed', 'issued', 'cancelled'])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function assertBookingExists(int $booking): void
    {
        if (! DB::getSchemaBuilder()->hasTable('bookings')) {
            abort(500, 'Bookings table is unavailable.');
        }

        abort_unless(DB::table('bookings')->where('id', $booking)->exists(), 404);
    }
}
