<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class GeneralBookingPassengerRemoveController extends Controller
{
    public function __invoke(int $booking, int $passenger): JsonResponse
    {
        if (! Schema::hasTable('bookings') || ! DB::table('bookings')->where('id', $booking)->exists()) {
            abort(404);
        }

        $table = $this->bookingPassengerTable();
        if (! $table) {
            throw ValidationException::withMessages([
                'passenger' => 'The booking passenger store is not available on this installation.',
            ]);
        }

        $columns = Schema::getColumnListing($table);
        if (! in_array('id', $columns, true) || ! in_array('booking_id', $columns, true)) {
            throw ValidationException::withMessages([
                'passenger' => 'The booking passenger store cannot be safely updated on this installation.',
            ]);
        }

        $row = DB::table($table)
            ->where('booking_id', $booking)
            ->where('id', $passenger)
            ->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'passenger' => 'The selected passenger does not belong to this booking.',
            ]);
        }

        try {
            $deleted = DB::transaction(function () use ($table, $booking, $passenger): int {
                $this->cleanupBookingPassengerDependencies($booking, $passenger);

                return DB::table($table)
                    ->where('booking_id', $booking)
                    ->where('id', $passenger)
                    ->delete();
            });
        } catch (\Throwable $e) {
            report($e);

            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'passenger' => 'This passenger is already linked to saved booking services. Remove those passenger assignments first, then try again.',
            ]);
        }

        if ($deleted !== 1) {
            throw ValidationException::withMessages([
                'passenger' => 'The passenger could not be removed from this booking.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'booking_passenger_id' => $passenger,
            'message' => 'Passenger removed from booking.',
        ]);
    }

    private function bookingPassengerTable(): ?string
    {
        foreach (['booking_passengers', 'booking_travellers', 'booking_travelers'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            if (in_array('booking_id', $columns, true)) {
                return $table;
            }
        }

        return null;
    }

    private function cleanupBookingPassengerDependencies(int $booking, int $passenger): void
    {
        if (Schema::hasTable('booking_visa_services')) {
            $rows = DB::table('booking_visa_services')
                ->where('booking_id', $booking)
                ->where('booking_passenger_id', $passenger)
                ->get(['id', 'status']);
            $this->assertDraftDependencies($rows, 'Visa service');
            if ($rows->isNotEmpty()) {
                DB::table('booking_visa_services')->whereIn('id', $rows->pluck('id')->all())->delete();
            }
        }

        foreach (['booking_service_passengers', 'booking_service_passenger_links', 'booking_booking_service_passengers'] as $linkTable) {
            if (! Schema::hasTable($linkTable)) continue;
            $columns = Schema::getColumnListing($linkTable);
            $passengerColumn = in_array('booking_passenger_id', $columns, true)
                ? 'booking_passenger_id'
                : (in_array('passenger_id', $columns, true) ? 'passenger_id' : null);
            if (! $passengerColumn) continue;

            $query = DB::table($linkTable)->where($passengerColumn, $passenger);
            if (in_array('booking_id', $columns, true)) {
                $query->where('booking_id', $booking);
            } elseif (in_array('booking_service_id', $columns, true) && Schema::hasTable('booking_services')) {
                $serviceIds = DB::table('booking_services')->where('booking_id', $booking)->pluck('id')->all();
                if (! $serviceIds) continue;
                $query->whereIn('booking_service_id', $serviceIds);
            }
            $query->delete();
        }

        $this->cleanupAirTicketDetails($booking, $passenger);
    }

    private function cleanupAirTicketDetails(int $booking, int $passenger): void
    {
        if (! Schema::hasTable('air_ticket_details')) return;
        $columns = Schema::getColumnListing('air_ticket_details');
        $passengerColumn = collect(['booking_passenger_id', 'passenger_id', 'traveller_id', 'traveler_id'])
            ->first(fn (string $column): bool => in_array($column, $columns, true));
        if (! $passengerColumn) return;

        $query = DB::table('air_ticket_details')->where($passengerColumn, $passenger);
        if (in_array('booking_id', $columns, true)) {
            $query->where('booking_id', $booking);
        } elseif (in_array('booking_service_id', $columns, true) && Schema::hasTable('booking_services')) {
            $serviceIds = DB::table('booking_services')->where('booking_id', $booking)->pluck('id')->all();
            if (! $serviceIds) return;
            $query->whereIn('booking_service_id', $serviceIds);
        } else {
            return;
        }

        $rows = $query->get();
        $this->assertDraftDependencies($rows, 'Passenger ticket');
        if ($rows->isNotEmpty() && in_array('id', $columns, true)) {
            DB::table('air_ticket_details')->whereIn('id', $rows->pluck('id')->all())->delete();
        }
    }

    private function assertDraftDependencies(iterable $rows, string $label): void
    {
        $terminal = ['issued', 'posted', 'paid', 'settled', 'closed', 'approved', 'completed', 'refunded', 'void', 'voided', 'cancelled', 'canceled'];
        foreach ($rows as $row) {
            $status = strtolower((string) ($row->status ?? $row->ticket_status ?? ''));
            if (in_array($status, $terminal, true)) {
                throw ValidationException::withMessages([
                    'passenger' => $label.' is already issued or otherwise irreversible; remove is blocked.',
                ]);
            }
        }
    }
}
