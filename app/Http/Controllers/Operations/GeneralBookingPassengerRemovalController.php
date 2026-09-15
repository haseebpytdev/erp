<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingEditLockResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class GeneralBookingPassengerRemovalController extends Controller
{
    public function __construct(private readonly BookingEditLockResolver $locks) {}

    public function index(Request $request, int $booking): JsonResponse
    {
        $this->assertBookingExists($booking);
        $table = $this->bookingPassengerTable();
        $lock = $this->locks->resolve($booking);

        if (! $table) {
            return response()->json([
                'ok' => true,
                'editable' => ! (bool) ($lock['locked'] ?? false),
                'lock_reason' => (string) ($lock['reason'] ?? ''),
                'passengers' => [],
            ]);
        }

        $columns = Schema::getColumnListing($table);
        $rows = DB::table($table)
            ->where('booking_id', $booking)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->presentPassenger((array) $row, $columns))
            ->values();

        return response()->json([
            'ok' => true,
            'editable' => ! (bool) ($lock['locked'] ?? false),
            'lock_reason' => (string) ($lock['reason'] ?? ''),
            'passengers' => $rows,
        ]);
    }

    public function destroy(Request $request, int $booking, int $passenger): JsonResponse
    {
        $this->assertBookingExists($booking);

        $lock = $this->locks->resolve($booking);
        if ((bool) ($lock['locked'] ?? false)) {
            return response()->json([
                'message' => (string) ($lock['reason'] ?? 'Booking is locked. Reopen the booking before making changes.'),
                'error' => 'booking_locked',
                'booking_status' => (string) ($lock['status'] ?? ''),
            ], 423);
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

        $exists = DB::table($table)
            ->where('booking_id', $booking)
            ->where('id', $passenger)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'passenger' => 'The selected passenger does not belong to this booking or has already been removed.',
            ]);
        }

        try {
            $deleted = DB::transaction(function () use ($table, $booking, $passenger): int {
                return DB::table($table)
                    ->where('booking_id', $booking)
                    ->where('id', $passenger)
                    ->delete();
            });
        } catch (QueryException $e) {
            report($e);

            throw ValidationException::withMessages([
                'passenger' => 'This passenger is linked to saved product data and cannot be removed yet. Remove the passenger from the linked product data first, then try again.',
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
            'message' => 'Passenger removed from booking. Passenger Master was not changed.',
        ]);
    }

    private function assertBookingExists(int $booking): void
    {
        abort_unless(
            $booking > 0
            && Schema::hasTable('bookings')
            && DB::table('bookings')->where('id', $booking)->exists(),
            404
        );
    }

    private function bookingPassengerTable(): ?string
    {
        foreach (['booking_passengers', 'booking_travellers', 'booking_travelers'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            if (in_array('id', $columns, true) && in_array('booking_id', $columns, true)) {
                return $table;
            }
        }

        return null;
    }

    private function presentPassenger(array $row, array $columns): array
    {
        $name = $this->firstValue($row, ['name', 'passenger_name', 'full_name']);
        if ($name === '') {
            $name = trim(
                $this->firstValue($row, ['first_name', 'given_name'])
                .' '
                .$this->firstValue($row, ['last_name', 'surname', 'family_name'])
            );
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => $name,
            'passport_number' => $this->firstValue($row, ['passport_no', 'passport_number']),
            'fare_type' => strtoupper($this->firstValue($row, ['fare_as', 'fare_type', 'passenger_type', 'pax_type', 'age_type'])),
        ];
    }

    private function firstValue(array $row, array $candidates): string
    {
        foreach ($candidates as $column) {
            if (array_key_exists($column, $row)) {
                $value = trim((string) ($row[$column] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}
