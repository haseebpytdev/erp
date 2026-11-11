<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\BookingTransportSegment;
use App\Services\Operations\GroupPackageOperationalPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class BookingTransportController extends Controller
{
    public function store(Request $request, int $booking): RedirectResponse
    {
        $this->assertBookingExists($booking);
        $bookingRow = (array) DB::table('bookings')->where('id', $booking)->first();
        $isPackage = GroupPackageOperationalPolicy::isGroupPackage($bookingRow);
        $data = $this->validated($request, $isPackage);

        $data = $this->enforceCommercialBoundary($data, $isPackage);
        $data['booking_id'] = $booking;
        $data['sort_order'] = ((int) BookingTransportSegment::where('booking_id', $booking)->max('sort_order')) + 10;
        $data['rate_snapshot'] = $this->rateSnapshot($data['rate_card_id'] ?? null);

        BookingTransportSegment::create($data);

        return back()->with('success', 'Transport entry saved.');
    }

    public function update(Request $request, int $booking, BookingTransportSegment $transport): RedirectResponse
    {
        abort_unless((int) $transport->booking_id === $booking, 404);
        $this->assertBookingExists($booking);
        $bookingRow = (array) DB::table('bookings')->where('id', $booking)->first();
        $isPackage = GroupPackageOperationalPolicy::isGroupPackage($bookingRow);

        $data = $this->enforceCommercialBoundary($this->validated($request, $isPackage), $isPackage);
        if (array_key_exists('rate_card_id', $data) && $data['rate_card_id']) {
            $data['rate_snapshot'] = $this->rateSnapshot($data['rate_card_id']);
        }

        $transport->update($data);

        return back()->with('success', 'Transport entry updated.');
    }

    public function destroy(int $booking, BookingTransportSegment $transport): RedirectResponse
    {
        abort_unless((int) $transport->booking_id === $booking, 404);
        $transport->delete();

        return back()->with('success', 'Transport entry removed.');
    }

    private function validated(Request $request, bool $isPackage): array
    {
        $commercialRule = $isPackage ? ['nullable'] : ['nullable', 'numeric', 'min:0'];

        return $request->validate([
            'booking_service_id' => ['nullable', 'integer', 'min:1'],
            'rate_card_id' => ['nullable', 'integer', 'min:1'],
            'transport_date' => ['required', 'date'],
            'pickup_time' => ['nullable', 'date_format:H:i'],
            'pickup_location' => ['required', 'string', 'max:255'],
            'dropoff_location' => ['required', 'string', 'max:255'],
            'route_label' => ['nullable', 'string', 'max:255'],
            'vehicle_type' => ['nullable', 'string', 'max:120'],
            'vehicle_number' => ['nullable', 'string', 'max:120'],
            'driver_name' => ['nullable', 'string', 'max:160'],
            'driver_contact' => ['nullable', 'string', 'max:80'],
            'provider_reference' => ['nullable', 'string', 'max:120'],
            'passengers_count' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'supplier_party_id' => ['nullable', 'integer', 'min:1'],
            'sale_currency_code' => ['nullable', 'string', 'max:8'],
            'sale_amount' => $commercialRule,
            'supplier_currency_code' => ['nullable', 'string', 'max:8'],
            'supplier_amount' => $commercialRule,
            'fx_rate_to_pkr' => $isPackage ? ['nullable'] : ['nullable', 'numeric', 'gt:0'],
            'status' => ['required', Rule::in(['requested', 'booked', 'confirmed', 'completed', 'cancelled'])],
            'voucher_notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function enforceCommercialBoundary(array $data, bool $isPackage): array
    {
        if ($isPackage) {
            return [
                ...$data,
                'service_mode' => 'package',
                'sale_currency_code' => 'PKR',
                'sale_amount' => 0,
                'supplier_currency_code' => 'PKR',
                'supplier_amount' => 0,
                'fx_rate_to_pkr' => null,
                'supplier_amount_pkr' => 0,
                'commercial_locked' => true,
            ];
        }

        $supplier = (float) ($data['supplier_amount'] ?? 0);
        $supplierCurrency = strtoupper((string) ($data['supplier_currency_code'] ?? 'PKR'));
        $fx = $supplierCurrency === 'PKR' ? 1.0 : (float) ($data['fx_rate_to_pkr'] ?? 0);

        return [
            ...$data,
            'service_mode' => 'standalone',
            'sale_currency_code' => strtoupper((string) ($data['sale_currency_code'] ?? 'PKR')),
            'supplier_currency_code' => $supplierCurrency,
            'supplier_amount_pkr' => $supplierCurrency === 'PKR' ? $supplier : round($supplier * $fx, 2),
            'commercial_locked' => false,
        ];
    }

    private function rateSnapshot(?int $rateCardId): ?array
    {
        if (! $rateCardId || ! Schema::hasTable('transport_rate_cards')) {
            return null;
        }

        $row = DB::table('transport_rate_cards')->where('id', $rateCardId)->first();
        return $row ? (array) $row : null;
    }

    private function assertBookingExists(int $booking): void
    {
        if (! Schema::hasTable('bookings')) {
            abort(500, 'Bookings table is unavailable.');
        }

        abort_unless(DB::table('bookings')->where('id', $booking)->exists(), 404);
    }
}
