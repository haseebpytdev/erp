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
                return DB::table($table)
                    ->where('booking_id', $booking)
                    ->where('id', $passenger)
                    ->delete();
            });
        } catch (\Throwable $e) {
            report($e);

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
}
