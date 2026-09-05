<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\AdaptivePassengerMasterWriter;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GeneralBookingPassengerQuickController extends Controller
{
    public function __construct(
        private readonly UnifiedGroupPackageDataSource $source,
        private readonly AdaptivePassengerMasterWriter $passengerWriter,
    ) {
    }

    /**
     * Lightweight Passenger Master lookup for the GENERAL booking quick-add row.
     *
     * The browser calls this while staff types a passenger name or passport.
     * It intentionally returns a small de-duplicated suggestion set only.
     */
    public function lookup(Request $request, int $booking): JsonResponse
    {
        $this->assertBookingExists($booking);

        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $needle = Str::lower($query);
        $compactNeedle = $this->compact($query);

        $rows = $this->source->passengers()
            ->filter(function (array $row) use ($needle, $compactNeedle): bool {
                $name = Str::lower(trim((string) ($row['name'] ?? '')));
                $passport = $this->compact((string) ($row['passport_no'] ?? ''));

                return ($name !== '' && str_contains($name, $needle))
                    || ($compactNeedle !== '' && $passport !== '' && str_contains($passport, $compactNeedle));
            })
            ->take(10)
            ->values()
            ->map(function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'source' => (string) ($row['source_table'] ?? ''),
                    'name' => trim((string) ($row['name'] ?? '')),
                    'fare_type' => $this->normalizeFareType((string) ($row['fare_as'] ?? '')),
                    'passport_number' => trim((string) ($row['passport_no'] ?? '')),
                    'dob' => $this->dateValue($row['date_of_birth'] ?? null),
                    'passport_expiry' => $this->dateValue($row['passport_expiry'] ?? null),
                    'nationality' => trim((string) ($row['nationality'] ?? '')),
                    'status' => 'ACTIVE',
                ];
            });

        return response()->json(['results' => $rows]);
    }

    /**
     * Update the CURRENT Passenger Master record before a saved traveller is
     * attached to this booking. Existing booking_passengers rows are never
     * touched, so historical booking snapshots keep the passport/document
     * details that were valid when those bookings were created.
     */
    public function updateMaster(Request $request, int $booking): JsonResponse
    {
        $this->assertBookingExists($booking);

        $data = $request->validate([
            'master_id' => ['required', 'integer', 'min:1'],
            'master_source' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'fare_type' => ['required', Rule::in(['ADULT', 'CHILD', 'INFANT', 'adult', 'child', 'infant'])],
            'passport_number' => ['nullable', 'string', 'max:100'],
            'dob' => ['nullable', 'date'],
            'passport_expiry' => ['nullable', 'date'],
            'nationality' => ['nullable', 'string', 'max:100'],
        ]);

        $table = (string) $data['master_source'];
        $id = (int) $data['master_id'];

        if (! $this->isSafePassengerMasterSource($table)
            || ! Schema::hasTable($table)
            || ! DB::table($table)->where('id', $id)->exists()) {
            throw ValidationException::withMessages([
                'passenger' => 'The saved passenger record could not be found. Please search the passenger again.',
            ]);
        }

        $columns = Schema::getColumnListing($table);
        [$firstName, $lastName] = $this->splitName((string) $data['name']);
        $fullName = trim($firstName . ' ' . ($lastName === '.' ? '' : $lastName));
        $updates = [];

        $this->putAllowEmpty($updates, $columns, ['first_name', 'given_name'], $firstName);
        $this->putAllowEmpty($updates, $columns, ['last_name', 'surname', 'family_name'], $lastName);
        $this->putAllowEmpty($updates, $columns, ['name', 'passenger_name', 'full_name'], $fullName !== '' ? $fullName : (string) $data['name']);
        $this->putAllowEmpty($updates, $columns, ['date_of_birth', 'dob', 'birth_date'], $data['dob'] ?? null);
        $this->putAllowEmpty($updates, $columns, ['passport_no', 'passport_number'], trim((string) ($data['passport_number'] ?? '')));
        $this->putAllowEmpty($updates, $columns, ['passport_expiry', 'passport_expiry_date'], $data['passport_expiry'] ?? null);
        $this->putAllowEmptyFitted($updates, $table, $columns, ['nationality', 'nationality_name', 'country'], trim((string) ($data['nationality'] ?? '')));
        $this->putAllowEmpty($updates, $columns, ['fare_as', 'age_type', 'passenger_type', 'pax_type'], $this->normalizeFareType((string) $data['fare_type']));

        if (in_array('updated_at', $columns, true)) {
            $updates['updated_at'] = now();
        }

        if ($updates) {
            DB::transaction(function () use ($table, $id, $updates): void {
                DB::table($table)->where('id', $id)->update($updates);
            });
        }

        return response()->json([
            'ok' => true,
            'message' => 'Saved passenger details updated for future use.',
        ]);
    }

    /**
     * ERP-11.3.137 — persist the effective Fare As override against the
     * EXISTING booking-passenger snapshot. The native passenger editor can
     * visually apply an override without every installed-base schema exposing
     * the same backing field to the controlled Air workspace. This bridge is
     * deliberately booking-scoped: Passenger Master and historical bookings
     * are never rewritten.
     */
    public function updateBookingFareType(Request $request, int $booking): JsonResponse
    {
        $this->assertBookingExists($booking);

        $data = $request->validate([
            'booking_passenger_id' => ['required', 'integer', 'min:1'],
            'fare_type' => ['required', Rule::in(['ADULT', 'CHILD', 'INFANT', 'adult', 'child', 'infant'])],
        ]);

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

        $passengerId = (int) $data['booking_passenger_id'];
        $exists = DB::table($table)
            ->where('booking_id', $booking)
            ->where('id', $passengerId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'booking_passenger_id' => 'The selected passenger does not belong to this booking.',
            ]);
        }

        $fareType = $this->normalizeFareType((string) $data['fare_type']);
        $updates = [];

        // Fare As is the preferred explicit override. If an installed schema
        // does not expose it, update the first effective fare column available.
        // Do NOT mirror an override into age_type / fare_type siblings when a
        // dedicated fare_as exists: a child by DOB can intentionally travel on
        // an ADULT fare without changing the underlying age classification.
        $preferred = $this->firstColumn($columns, ['fare_as', 'fare_type', 'passenger_type', 'pax_type', 'age_type']);
        if ($preferred) {
            $updates[$preferred] = $this->fareTypeValueForColumn($table, $preferred, $fareType);
        }

        if (! $updates) {
            throw ValidationException::withMessages([
                'fare_type' => 'No writable booking passenger fare field exists on this installation.',
            ]);
        }

        if (in_array('updated_at', $columns, true)) {
            $updates['updated_at'] = now();
        }
        foreach (['updated_by', 'updated_by_id'] as $column) {
            if (in_array($column, $columns, true)) {
                $updates[$column] = Auth::id();
            }
        }

        DB::transaction(function () use ($table, $booking, $passengerId, $updates): void {
            DB::table($table)
                ->where('booking_id', $booking)
                ->where('id', $passengerId)
                ->update($updates);
        });

        return response()->json([
            'ok' => true,
            'booking_passenger_id' => $passengerId,
            'fare_type' => $fareType,
            'message' => 'Passenger fare type updated.',
        ]);
    }

    /**
     * Authoritative quick-add endpoint for GENERAL bookings.
     *
     * It no longer depends on the unstable native passenger editor DOM. A
     * selected saved Passenger Master row is reused when supplied; otherwise
     * AdaptivePassengerMasterWriter resolves/creates the master record. A
     * booking-specific immutable/current snapshot is then inserted into the
     * native booking passenger table.
     */
    public function store(Request $request, int $booking): JsonResponse
    {
        $bookingRow = $this->assertBookingExists($booking);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'fare_type' => ['required', Rule::in(['ADULT', 'CHILD', 'INFANT', 'adult', 'child', 'infant'])],
            'passport_number' => ['nullable', 'string', 'max:100'],
            'dob' => ['nullable', 'date'],
            'passport_expiry' => ['nullable', 'date'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'master_id' => ['nullable', 'integer', 'min:1'],
            'master_source' => ['nullable', 'string', 'max:80'],
        ]);

        [$firstName, $lastName] = $this->splitName((string) $data['name']);
        $fareType = $this->normalizeFareType((string) $data['fare_type']);

        $masterPayload = [
            'passenger_id' => isset($data['master_id']) ? (int) $data['master_id'] : null,
            'passenger_source' => trim((string) ($data['master_source'] ?? '')),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $data['dob'] ?? null,
            'passport_no' => trim((string) ($data['passport_number'] ?? '')),
            'passport_expiry' => $data['passport_expiry'] ?? null,
            'nationality' => trim((string) ($data['nationality'] ?? '')),
            'fare_as' => $fareType,
        ];

        $resolved = $this->passengerWriter->resolve($masterPayload, $booking);
        $target = $this->bookingPassengerTable();

        if (! $target) {
            throw ValidationException::withMessages([
                'passenger' => 'The booking passenger store is not available on this installation.',
            ]);
        }

        $columns = Schema::getColumnListing($target);
        $duplicate = $this->findDuplicateBookingPassenger(
            $target,
            $columns,
            $booking,
            (int) ($resolved['id'] ?? 0),
            $masterPayload,
        );

        if ($duplicate) {
            return response()->json([
                'ok' => true,
                'already_exists' => true,
                'message' => 'This passenger is already added to the booking.',
                'passenger' => $this->responsePassenger($masterPayload, $duplicate),
            ]);
        }

        $insert = $this->snapshotInsert(
            $columns,
            $booking,
            $bookingRow,
            $masterPayload,
            (int) ($resolved['id'] ?? 0),
        );

        if (! $insert) {
            throw ValidationException::withMessages([
                'passenger' => 'The passenger could not be mapped to the booking passenger schema.',
            ]);
        }

        try {
            // ERP-11.3.104: adapt the snapshot to the LIVE booking-passenger
            // schema before insert. Production installations can carry required
            // native sequencing fields (notably passenger_no) that are absent
            // from older cumulative migrations. Resolve those deterministically
            // instead of discovering one required field per deployment attempt.
            $insert = $this->fillRequiredPassengerFields(
                $target,
                $columns,
                $insert,
                $booking,
                $bookingRow,
                $masterPayload,
            );
            $insert = $this->fitInsertForSchema($target, $insert);
            $id = DB::transaction(function () use ($target, $insert): int {
                return (int) DB::table($target)->insertGetId($insert);
            });
        } catch (\Throwable $e) {
            report($e);

            // Keep production diagnostics useful without exposing SQL values/PII.
            // This lets the next failure identify a schema contract immediately
            // instead of sending staff through another blind UI iteration.
            $column = $this->databaseErrorColumn($e->getMessage());
            $message = $column
                ? 'Passenger save was rejected by the booking passenger field: ' . $column . '.'
                : 'Passenger could not be saved to the booking passenger store.';

            throw ValidationException::withMessages([
                'passenger' => $message,
            ]);
        }

        return response()->json([
            'ok' => true,
            'already_exists' => false,
            'message' => 'Passenger added.',
            'passenger' => $this->responsePassenger($masterPayload, $id),
            'master' => [
                'id' => $resolved['id'] ?? null,
                'source' => $resolved['source'] ?? null,
            ],
        ]);
    }

    private function assertBookingExists(int $booking): object
    {
        if (! Schema::hasTable('bookings')) {
            abort(404);
        }

        $row = DB::table('bookings')->where('id', $booking)->first();
        if (! $row) {
            abort(404);
        }

        return $row;
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

    private function findDuplicateBookingPassenger(
        string $table,
        array $columns,
        int $booking,
        int $masterId,
        array $row,
    ): ?int {
        $base = DB::table($table)->where('booking_id', $booking);

        $masterColumn = $this->firstColumn($columns, [
            'passenger_id', 'master_passenger_id', 'traveller_id', 'traveler_id',
        ]);
        if ($masterColumn && $masterId > 0) {
            $id = (clone $base)->where($masterColumn, $masterId)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        $passportColumn = $this->firstColumn($columns, ['passport_no', 'passport_number']);
        $passport = trim((string) ($row['passport_no'] ?? ''));
        if ($passportColumn && $passport !== '') {
            $id = (clone $base)
                ->whereRaw('LOWER(' . $passportColumn . ') = ?', [strtolower($passport)])
                ->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        $nameColumn = $this->firstColumn($columns, ['name', 'passenger_name', 'full_name']);
        $dobColumn = $this->firstColumn($columns, ['date_of_birth', 'dob', 'birth_date']);
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($nameColumn && $dobColumn && $fullName !== '' && ! empty($row['date_of_birth'])) {
            $id = (clone $base)
                ->whereRaw('LOWER(' . $nameColumn . ') = ?', [strtolower($fullName)])
                ->whereDate($dobColumn, $row['date_of_birth'])
                ->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    private function snapshotInsert(
        array $columns,
        int $booking,
        object $bookingRow,
        array $data,
        int $masterId,
    ): array {
        $insert = [];
        $fullName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

        $this->put($insert, $columns, ['booking_id'], $booking);
        $this->put($insert, $columns, ['passenger_id', 'master_passenger_id', 'traveller_id', 'traveler_id'], $masterId ?: null);
        $this->put($insert, $columns, ['first_name', 'given_name'], $data['first_name'] ?? null);
        $this->put($insert, $columns, ['last_name', 'surname', 'family_name'], $data['last_name'] ?? null);
        $this->put($insert, $columns, ['name', 'passenger_name', 'full_name'], $fullName);
        $this->put($insert, $columns, ['date_of_birth', 'dob', 'birth_date'], $data['date_of_birth'] ?? null);
        $this->put($insert, $columns, ['passport_no', 'passport_number'], $data['passport_no'] ?? null);
        $this->put($insert, $columns, ['passport_expiry', 'passport_expiry_date'], $data['passport_expiry'] ?? null);
        $this->putFitted($insert, $this->bookingPassengerTable() ?? 'booking_passengers', $columns, ['nationality', 'nationality_name', 'country'], $data['nationality'] ?? null);
        $this->put($insert, $columns, ['fare_as', 'age_type', 'passenger_type', 'pax_type'], $data['fare_as'] ?? 'ADULT');
        $this->put($insert, $columns, ['status'], 'active');

        foreach (['is_active' => 1, 'active' => 1, 'is_lead' => 0, 'lead' => 0] as $column => $value) {
            if (in_array($column, $columns, true) && ! array_key_exists($column, $insert)) {
                $insert[$column] = $value;
            }
        }

        // Copy booking-scoped ownership/context fields when both schemas expose
        // them. This makes the bridge safe across older ERP installations where
        // one or more of these columns are non-null.
        $bookingArray = (array) $bookingRow;
        foreach ([
            'company_id', 'branch_id', 'customer_id', 'agent_id', 'salesperson_id',
            'currency_id', 'tenant_id', 'office_id',
        ] as $column) {
            if (
                in_array($column, $columns, true)
                && array_key_exists($column, $bookingArray)
                && $bookingArray[$column] !== null
                && ! array_key_exists($column, $insert)
            ) {
                $insert[$column] = $bookingArray[$column];
            }
        }

        foreach (['created_by', 'created_by_id', 'user_id'] as $column) {
            if (in_array($column, $columns, true) && ! array_key_exists($column, $insert) && Auth::id()) {
                $insert[$column] = Auth::id();
            }
        }

        $sortColumn = $this->firstColumn($columns, ['sort_order', 'sequence', 'sequence_no']);
        if ($sortColumn) {
            $current = (int) (DB::table($this->bookingPassengerTable())
                ->where('booking_id', $booking)
                ->max($sortColumn) ?? 0);
            $insert[$sortColumn] = $current + 10;
        }

        if (in_array('created_at', $columns, true)) {
            $insert['created_at'] = now();
        }
        if (in_array('updated_at', $columns, true)) {
            $insert['updated_at'] = now();
        }

        return $insert;
    }

    /**
     * Fill required native booking-passenger fields from the installed schema.
     *
     * The live ERP can predate/extend this cumulative package and may require
     * fields such as passenger_no even when older package migrations did not.
     * We only infer fields with an unambiguous booking/passenger meaning; we do
     * not fabricate commercial or unrelated values.
     */
    private function fillRequiredPassengerFields(
        string $table,
        array $columns,
        array $insert,
        int $booking,
        object $bookingRow,
        array $data,
    ): array {
        $metadata = $this->columnMetadata($table);
        $bookingData = (array) $bookingRow;
        $ordinal = $this->nextPassengerOrdinal($table, $booking, $columns);
        $passengerNumber = $this->nextPassengerNumber($table, $booking, $columns, $ordinal);
        $fullName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
        $fareType = $this->normalizeFareType((string) ($data['fare_as'] ?? 'ADULT'));

        // Known sequence aliases are safe to populate even when metadata lookup
        // is limited by the database driver.
        foreach (['passenger_no', 'passenger_number', 'pax_no', 'traveller_no', 'traveler_no'] as $field) {
            if (in_array($field, $columns, true) && ! array_key_exists($field, $insert)) {
                $insert[$field] = $passengerNumber;
            }
        }

        if (in_array('passenger_index', $columns, true) && ! array_key_exists('passenger_index', $insert)) {
            $insert['passenger_index'] = max(0, $ordinal - 1);
        }

        if (! $metadata) {
            return $insert;
        }

        $unresolved = [];

        foreach ($metadata as $field => $meta) {
            if (array_key_exists($field, $insert) || $this->columnCanBeOmitted($field, $meta)) {
                continue;
            }

            $lower = strtolower($field);
            $resolved = true;
            $value = null;

            if (in_array($lower, ['booking_id', 'travel_booking_id', 'source_booking_id'], true)) {
                $value = $booking;
            } elseif (in_array($lower, ['passenger_no', 'passenger_number', 'pax_no', 'traveller_no', 'traveler_no'], true)) {
                $value = $passengerNumber;
            } elseif ($lower === 'passenger_index') {
                $value = max(0, $ordinal - 1);
            } elseif (in_array($lower, ['line_no', 'line_number', 'sequence_no', 'sequence'], true)) {
                $value = $ordinal;
            } elseif ($lower === 'sort_order') {
                $value = $ordinal * 10;
            } elseif (in_array($lower, ['passenger_id', 'master_passenger_id', 'traveller_id', 'traveler_id'], true)) {
                $value = $insert[$field] ?? null;
            } elseif (in_array($lower, ['first_name', 'given_name'], true)) {
                $value = $data['first_name'] ?? null;
            } elseif (in_array($lower, ['last_name', 'surname', 'family_name'], true)) {
                $value = $data['last_name'] ?? '.';
            } elseif (in_array($lower, ['name', 'passenger_name', 'full_name'], true)) {
                $value = $fullName;
            } elseif (in_array($lower, ['date_of_birth', 'dob', 'birth_date'], true)) {
                $value = $data['date_of_birth'] ?? null;
            } elseif (in_array($lower, ['passport_no', 'passport_number'], true)) {
                $value = $data['passport_no'] ?? null;
            } elseif (in_array($lower, ['passport_expiry', 'passport_expiry_date'], true)) {
                $value = $data['passport_expiry'] ?? null;
            } elseif (in_array($lower, ['fare_as', 'age_type', 'passenger_type', 'pax_type'], true)) {
                $value = $fareType;
            } elseif (str_contains($lower, 'nationality') || $lower === 'country') {
                $value = $data['nationality'] ?? null;
            } elseif ($lower === 'status' || str_ends_with($lower, '_status')) {
                $value = 'active';
            } elseif (in_array($lower, ['company_id', 'branch_id', 'customer_id', 'agent_id', 'salesperson_id', 'currency_id', 'tenant_id', 'office_id'], true)) {
                $value = $bookingData[$lower] ?? null;
            } elseif (in_array($lower, ['created_by', 'created_by_id', 'updated_by', 'updated_by_id', 'user_id'], true)) {
                $value = Auth::id();
            } elseif ($lower === 'created_at' || $lower === 'updated_at') {
                $value = now();
            } elseif (str_starts_with($lower, 'is_') || in_array($this->metaType($meta), ['boolean', 'bool'], true)) {
                $value = 0;
            } else {
                $resolved = false;
            }

            if ($resolved && $value !== null && $value !== '') {
                $insert[$field] = $value;
                continue;
            }

            if (! $resolved || $value === null || $value === '') {
                $unresolved[] = $field;
            }
        }

        if ($unresolved) {
            throw ValidationException::withMessages([
                'passenger' => 'The live booking passenger schema has required field(s) that cannot be safely inferred: '
                    . implode(', ', array_slice($unresolved, 0, 10)) . '.',
            ]);
        }

        return $insert;
    }

    private function nextPassengerNumber(
        string $table,
        int $booking,
        array $columns,
        int $fallbackOrdinal,
    ): int|string {
        $field = $this->firstColumn($columns, [
            'passenger_no', 'passenger_number', 'pax_no', 'traveller_no', 'traveler_no',
        ]);

        if (! $field) {
            return $fallbackOrdinal;
        }

        try {
            $query = DB::table($table);

            // A unique single-column passenger number is a global native key;
            // otherwise passenger numbering is scoped to this booking.
            if (! $this->fieldHasGlobalUniqueIndex($table, $field)) {
                $query->where('booking_id', $booking);
            }

            $last = $query->whereNotNull($field)->orderByDesc('id')->value($field);
            $last = trim((string) ($last ?? ''));

            if ($last !== '') {
                if (ctype_digit($last)) {
                    return ((int) $last) + 1;
                }

                // Preserve native formats such as PAX-000123 / BP0009.
                if (preg_match('/^(.*?)(\\d+)$/', $last, $match)) {
                    $digits = (string) $match[2];
                    $next = (string) (((int) $digits) + 1);
                    return (string) $match[1] . str_pad($next, strlen($digits), '0', STR_PAD_LEFT);
                }
            }
        } catch (\Throwable) {
        }

        return $fallbackOrdinal;
    }

    private function fieldHasGlobalUniqueIndex(string $table, string $field): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (! is_array($index)) {
                    continue;
                }

                $unique = (bool) ($index['unique'] ?? false);
                $fields = array_values((array) ($index['columns'] ?? []));
                if ($unique && count($fields) === 1 && (string) $fields[0] === $field) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function nextPassengerOrdinal(string $table, int $booking, array $columns): int
    {
        // Prefer the native passenger number when it is numeric. Otherwise use
        // the current booking row count, which is deterministic for a new row.
        foreach (['passenger_no', 'passenger_number', 'pax_no', 'traveller_no', 'traveler_no'] as $field) {
            if (! in_array($field, $columns, true)) {
                continue;
            }

            try {
                $max = DB::table($table)->where('booking_id', $booking)->max($field);
                if ($max !== null && $max !== '' && is_numeric($max)) {
                    return max(1, ((int) $max) + 1);
                }
            } catch (\Throwable) {
            }
        }

        try {
            return ((int) DB::table($table)->where('booking_id', $booking)->count()) + 1;
        } catch (\Throwable) {
            return 1;
        }
    }

    private function columnMetadata(string $table): array
    {
        $result = [];

        try {
            foreach (Schema::getColumns($table) as $column) {
                if (! is_array($column)) {
                    continue;
                }

                $name = (string) ($column['name'] ?? $column['column_name'] ?? '');
                if ($name !== '') {
                    $result[$name] = $column;
                }
            }
        } catch (\Throwable) {
        }

        return $result;
    }

    private function columnCanBeOmitted(string $field, array $meta): bool
    {
        if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            return true;
        }

        $nullable = (bool) ($meta['nullable'] ?? $meta['is_nullable'] ?? false);
        $hasDefault = array_key_exists('default', $meta) && $meta['default'] !== null;
        $auto = (bool) ($meta['auto_increment'] ?? $meta['autoincrement'] ?? false);

        return $nullable || $hasDefault || $auto;
    }

    private function metaType(array $meta): string
    {
        return strtolower(trim((string) ($meta['type_name'] ?? $meta['type'] ?? '')));
    }

    private function responsePassenger(array $row, int $id): array
    {
        return [
            'id' => $id,
            'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'fare_type' => $this->normalizeFareType((string) ($row['fare_as'] ?? 'ADULT')),
            'passport_number' => trim((string) ($row['passport_no'] ?? '')),
            'dob' => $this->dateValue($row['date_of_birth'] ?? null),
            'passport_expiry' => $this->dateValue($row['passport_expiry'] ?? null),
            'nationality' => trim((string) ($row['nationality'] ?? '')),
            'status' => 'ACTIVE',
        ];
    }


    /**
     * Allow installed-base Passenger Master table names without ever permitting
     * historical booking snapshot tables to be rewritten. Examples seen across
     * installations include passengers, passenger_profiles, passenger_master,
     * travellers and travelers.
     */
    private function isSafePassengerMasterSource(string $table): bool
    {
        $table = strtolower(trim($table));
        if ($table === '' || str_starts_with($table, 'booking_')) {
            return false;
        }

        return str_contains($table, 'passenger')
            || str_contains($table, 'traveller')
            || str_contains($table, 'traveler');
    }

    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $parts = preg_split('/\s+/', $name) ?: [];

        if (count($parts) > 1) {
            $honorific = strtolower(rtrim((string) $parts[0], '.'));
            if (in_array($honorific, ['mr', 'mrs', 'ms', 'miss', 'master', 'dr'], true)) {
                array_shift($parts);
            }
        }

        if (! $parts) {
            return [$name, '.'];
        }
        if (count($parts) === 1) {
            return [$parts[0], '.'];
        }

        $last = (string) array_pop($parts);
        return [trim(implode(' ', $parts)), $last !== '' ? $last : '.'];
    }

    private function normalizeFareType(string $value): string
    {
        $value = strtoupper(trim($value));
        return in_array($value, ['ADULT', 'CHILD', 'INFANT'], true) ? $value : 'ADULT';
    }

    private function dateValue(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return (string) \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function compact(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }

    private function put(array &$row, array $columns, array $candidates, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                $row[$column] = $value;
                return;
            }
        }
    }

    /**
     * Fit values to the installed booking-passenger schema. The production
     * installed base includes schemas where nationality is ISO alpha-2
     * (VARCHAR(2)/CHAR(2)); the quick row intentionally shows a human readable
     * country label. Native forms normalized this implicitly, while the first
     * JSON bridge did not and could therefore fail with a data-too-long error.
     */
    private function fitInsertForSchema(string $table, array $insert): array
    {
        foreach ($insert as $column => $value) {
            $insert[$column] = $this->fitColumnValue($table, (string) $column, $value);
        }

        return $insert;
    }

    private function putFitted(
        array &$row,
        string $table,
        array $columns,
        array $candidates,
        mixed $value,
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                $row[$column] = $this->fitColumnValue($table, $column, $value);
                return;
            }
        }
    }

    private function putAllowEmptyFitted(
        array &$row,
        string $table,
        array $columns,
        array $candidates,
        mixed $value,
    ): void {
        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                $row[$column] = $this->fitColumnValue($table, $column, $value);
                return;
            }
        }
    }

    private function fitColumnValue(string $table, string $column, mixed $value): mixed
    {
        if ($value === null || ! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        $length = $this->columnLength($table, $column);

        if ($length !== null && $length <= 3 && str_contains(strtolower($column), 'national')) {
            return $this->countryCode($value, $length);
        }

        if ($length !== null && $length > 0 && mb_strlen($value) > $length) {
            return mb_substr($value, 0, $length);
        }

        return $value;
    }

    private function columnLength(string $table, string $column): ?int
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            // Table/column names come only from Schema::getColumnListing and
            // the controlled passenger-table candidates above.
            $rows = DB::select('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?', [$column]);
            $type = $rows ? (string) ($rows[0]->Type ?? '') : '';
            if (preg_match('/(?:var)?char\\((\\d+)\\)/i', $type, $match)) {
                return $cache[$key] = (int) $match[1];
            }
        } catch (\Throwable) {
            // Non-MySQL/fallback installations simply keep the original value.
        }

        return $cache[$key] = null;
    }

    private function countryCode(string $value, int $length = 2): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $upper = strtoupper($value);
        if (mb_strlen($upper) <= $length) {
            return $upper;
        }

        $key = strtolower(preg_replace('/[^a-z]+/i', '', $value) ?? $value);
        $map = [
            'pakistan' => 'PK', 'pakistani' => 'PK',
            'saudiarabia' => 'SA', 'saudi' => 'SA',
            'unitedarabemirates' => 'AE', 'uae' => 'AE', 'emirati' => 'AE',
            'india' => 'IN', 'indian' => 'IN',
            'bangladesh' => 'BD', 'bangladeshi' => 'BD',
            'afghanistan' => 'AF', 'afghan' => 'AF',
            'unitedkingdom' => 'GB', 'uk' => 'GB', 'british' => 'GB',
            'unitedstates' => 'US', 'usa' => 'US', 'american' => 'US',
            'canada' => 'CA', 'canadian' => 'CA',
            'australia' => 'AU', 'australian' => 'AU',
            'turkey' => 'TR', 'turkiye' => 'TR', 'turkish' => 'TR',
            'qatar' => 'QA', 'qatari' => 'QA',
            'oman' => 'OM', 'omani' => 'OM',
            'bahrain' => 'BH', 'bahraini' => 'BH',
            'kuwait' => 'KW', 'kuwaiti' => 'KW',
            'malaysia' => 'MY', 'malaysian' => 'MY',
            'indonesia' => 'ID', 'indonesian' => 'ID',
            'china' => 'CN', 'chinese' => 'CN',
        ];

        if (isset($map[$key])) {
            return mb_substr($map[$key], 0, $length);
        }

        // Do not let a human-readable country label break a CHAR(2) snapshot.
        return mb_substr($upper, 0, $length);
    }

    private function databaseErrorColumn(string $message): ?string
    {
        foreach ([
            "/column ['`]([^'`]+)['`]/i",
            "/field ['`]([^'`]+)['`]/i",
        ] as $pattern) {
            if (preg_match($pattern, $message, $match)) {
                return preg_replace('/[^a-z0-9_]+/i', '', (string) $match[1]) ?: null;
            }
        }

        return null;
    }

    /**
     * Fit ADULT / CHILD / INFANT to an installed enum/string representation.
     * Common native schemas use either full values or ADT / CHD / INF.
     */
    private function fareTypeValueForColumn(string $table, string $column, string $fareType): string
    {
        $fareType = $this->normalizeFareType($fareType);
        $aliases = [
            'ADULT' => ['ADULT', 'ADT'],
            'CHILD' => ['CHILD', 'CHD'],
            'INFANT' => ['INFANT', 'INF'],
        ];

        try {
            $rows = DB::select(
                'SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?',
                [$column]
            );
            $type = $rows ? (string) ($rows[0]->Type ?? '') : '';
            if (preg_match('/^enum\((.*)\)$/i', $type, $match)) {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string) $match[1], $enumMatches);
                $values = array_map(
                    static fn (string $value): string => stripcslashes($value),
                    (array) ($enumMatches[1] ?? [])
                );
                foreach ($aliases[$fareType] as $alias) {
                    foreach ($values as $value) {
                        if (strcasecmp($value, $alias) === 0) {
                            return $value;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Non-MySQL/fallback installs use the canonical full value.
        }

        return $fareType;
    }

    private function putAllowEmpty(array &$row, array $columns, array $candidates, mixed $value): void
    {
        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                $row[$column] = $value;
                return;
            }
        }
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                return $column;
            }
        }

        return null;
    }
}
