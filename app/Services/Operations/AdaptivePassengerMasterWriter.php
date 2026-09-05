<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdaptivePassengerMasterWriter
{
    /**
     * Resolve an existing saved passenger or create the new passenger in the
     * ERP's existing passenger data store where possible.
     *
     * The booking snapshot is always saved separately by the unified booking
     * controller; this service never overwrites an existing saved passenger.
     */
    public function resolve(array $row, int $bookingId): array
    {
        $source = (string) ($row['passenger_source'] ?? '');
        $id = (int) ($row['passenger_id'] ?? 0);

        if ($source !== '' && $id > 0 && $this->safeSource($source) && Schema::hasTable($source)) {
            try {
                if (DB::table($source)->where('id', $id)->exists()) {
                    return ['id' => $id, 'source' => $source, 'warning' => null];
                }
            } catch (\Throwable) {
                // Continue to duplicate detection / create.
            }
        }

        foreach ($this->masterCandidates() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->findDuplicate($table, $row);
            if ($existing) {
                return ['id' => $existing, 'source' => $table, 'warning' => null];
            }
        }

        foreach ($this->masterCandidates() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $newId = $this->insertInto($table, $row, $bookingId);
                if ($newId) {
                    return ['id' => $newId, 'source' => $table, 'warning' => null];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'id' => null,
            'source' => null,
            'warning' => 'New passenger was saved in this booking, but the ERP passenger master could not be updated automatically for this schema.',
        ];
    }

    private function masterCandidates(): array
    {
        return ['passengers', 'travellers', 'travelers'];
    }

    private function findDuplicate(string $table, array $row): ?int
    {
        $columns = Schema::getColumnListing($table);
        if (! in_array('id', $columns, true)) {
            return null;
        }

        $passportColumn = $this->firstColumn($columns, ['passport_no', 'passport_number']);
        $passport = trim((string) ($row['passport_no'] ?? ''));
        if ($passportColumn && $passport !== '') {
            $found = DB::table($table)->whereRaw('LOWER(' . $passportColumn . ') = ?', [strtolower($passport)])->value('id');
            if ($found) {
                return (int) $found;
            }
        }

        $firstColumn = $this->firstColumn($columns, ['first_name', 'given_name', 'name', 'passenger_name']);
        $dobColumn = $this->firstColumn($columns, ['date_of_birth', 'dob', 'birth_date']);
        $first = trim((string) ($row['first_name'] ?? ''));
        $dob = $row['date_of_birth'] ?? null;
        if ($firstColumn && $dobColumn && $first !== '' && $dob) {
            $found = DB::table($table)
                ->whereRaw('LOWER(' . $firstColumn . ') = ?', [strtolower($first)])
                ->whereDate($dobColumn, $dob)
                ->value('id');
            if ($found) {
                return (int) $found;
            }
        }

        return null;
    }

    private function insertInto(string $table, array $data, int $bookingId): ?int
    {
        $columns = Schema::getColumnListing($table);
        if (! in_array('id', $columns, true)) {
            return null;
        }

        $row = [];
        $this->put($row, $columns, ['title', 'salutation'], $data['title'] ?? null);
        $this->put($row, $columns, ['first_name', 'given_name'], $data['first_name'] ?? null);
        $this->put($row, $columns, ['last_name', 'surname', 'family_name'], $data['last_name'] ?? null);
        $this->put($row, $columns, ['name', 'passenger_name'], trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')));
        $this->put($row, $columns, ['date_of_birth', 'dob', 'birth_date'], $data['date_of_birth'] ?? null);
        $this->put($row, $columns, ['passport_no', 'passport_number'], $data['passport_no'] ?? null);
        $this->put($row, $columns, ['passport_expiry', 'passport_expiry_date'], $data['passport_expiry'] ?? null);
        $this->putFitted($row, $table, $columns, ['nationality', 'nationality_name', 'country'], $data['nationality'] ?? null);
        $this->put($row, $columns, ['booking_id'], $bookingId);
        $this->put($row, $columns, ['created_by', 'created_by_id', 'user_id'], Auth::id());

        foreach ([
            'status' => 'active',
            'is_active' => 1,
            'active' => 1,
        ] as $column => $value) {
            if (in_array($column, $columns, true) && ! array_key_exists($column, $row)) {
                $row[$column] = $value;
            }
        }

        if (in_array('created_at', $columns, true)) {
            $row['created_at'] = now();
        }
        if (in_array('updated_at', $columns, true)) {
            $row['updated_at'] = now();
        }

        if (! $row) {
            return null;
        }

        return (int) DB::table($table)->insertGetId($row);
    }

    private function safeSource(string $source): bool
    {
        return in_array($source, ['passengers', 'travellers', 'travelers', 'booking_passengers'], true);
    }

    private function putFitted(array &$row, string $table, array $columns, array $candidates, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        foreach ($candidates as $column) {
            if (! in_array($column, $columns, true)) {
                continue;
            }

            $string = trim((string) $value);
            $length = $this->columnLength($table, $column);
            if ($length !== null && $length <= 3 && $string !== '') {
                $string = $this->countryCode($string, $length);
            } elseif ($length !== null && mb_strlen($string) > $length) {
                $string = mb_substr($string, 0, $length);
            }

            $row[$column] = $string;
            return;
        }
    }

    private function columnLength(string $table, string $column): ?int
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return null;
        }

        try {
            $row = DB::selectOne('SHOW COLUMNS FROM `'.$table.'` LIKE ?', [$column]);
            $type = strtolower((string) ($row->Type ?? ''));
            if (preg_match('/(?:var)?char\((\d+)\)/', $type, $match)) {
                return (int) $match[1];
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function countryCode(string $value, int $length = 2): string
    {
        $upper = strtoupper(trim($value));
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

        return mb_substr($map[$key] ?? $upper, 0, $length);
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
