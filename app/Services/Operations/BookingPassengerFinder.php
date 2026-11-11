<?php

namespace App\Services\Operations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only booking finder used by the existing Booking Register overlay.
 *
 * The finder deliberately starts from booking-owned rows (not Passenger
 * Master rows) and applies the same company/branch boundary used by the
 * native BookingController. It never mutates booking, passenger or ticket
 * data.
 */
final class BookingPassengerFinder
{
    private const PASSPORT_COLUMNS = ['passport_no', 'passport_number', 'passport', 'passport_id', 'document_number'];
    private const TICKET_COLUMNS = ['ticket_number', 'ticket_no', 'e_ticket_number', 'eticket_number', 'document_number', 'document_no'];

    public function search(Request $request): array
    {
        // Sensitive Finder input is accepted only from the POST body. Legacy
        // GET parameters are deliberately ignored so identifiers cannot leak
        // through URLs, redirects, pagination links or referrer headers.
        $rawValue = $request->isMethod('POST') ? $request->input('booking_finder', '') : '';
        $modeValue = $request->isMethod('POST') ? $request->input('booking_finder_mode', 'auto') : 'auto';
        $raw = is_string($rawValue) ? trim($rawValue) : '';
        $mode = is_string($modeValue) ? strtolower(trim($modeValue)) : 'auto';
        if ($request->isMethod('POST') && ! is_string($rawValue)) {
            return ['active' => true, 'rows' => [], 'message' => '', 'error' => 'Enter a valid search value.'];
        }
        if ($raw === '' && $mode === 'auto') {
            return ['active' => false, 'rows' => [], 'message' => '', 'error' => ''];
        }
        if (mb_strlen($raw) < 2 || mb_strlen($raw) > 80) {
            return ['active' => true, 'rows' => [], 'message' => '', 'error' => 'Enter 2–80 characters to search.'];
        }
        if (! in_array($mode, ['auto', 'passport', 'ticket'], true)) {
            $mode = 'auto';
        }

        $scope = $this->scope($request);
        if ($scope === null) {
            return ['active' => true, 'rows' => [], 'message' => '', 'error' => 'Booking search is unavailable for this account scope.'];
        }
        if (! Schema::hasTable('bookings')) {
            return ['active' => true, 'rows' => [], 'message' => '', 'error' => 'Booking search is unavailable.'];
        }

        $rows = [];
        if (in_array($mode, ['auto', 'passport'], true)) {
            $rows = array_merge($rows, $this->passportRows($raw, $scope));
        }
        if (in_array($mode, ['auto', 'ticket'], true)) {
            $rows = array_merge($rows, $this->ticketRows($raw, $scope));
        }

        $unique = [];
        foreach ($rows as $row) {
            $key = ($row['booking_id'] ?? '').'|'.($row['passenger_id'] ?? '').'|'.($row['ticket'] ?? '').'|'.($row['match_type'] ?? '');
            $unique[$key] = $row;
        }
        $rows = array_values($unique);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['booking_date'] ?? ''), (string) ($b['booking_date'] ?? '')) * -1);
        $total = count($rows);
        // Pagination remains POST-only so the raw passport/ticket value is
        // never moved into a URL or query string.
        $pageInput = $request->isMethod('POST')
            ? $request->input('booking_finder_page', 1)
            : 1;
        $page = max(1, (int) $pageInput);
        $perPage = 25;
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'active' => true,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'query' => $raw,
            'mode' => $mode,
            'message' => $total === 0 ? 'No authorized bookings matched that value.' : '',
            'error' => '',
        ];
    }

    private function passportRows(string $value, array $scope): array
    {
        $table = $this->passengerTable();
        if ($table === null) return [];
        $columns = Schema::getColumnListing($table);
        $passport = $this->firstColumn($columns, self::PASSPORT_COLUMNS);
        if ($passport === null || ! in_array('booking_id', $columns, true)) return [];
        $bookingColumns = Schema::getColumnListing('bookings');
        $query = DB::table($table.' as bp')->join('bookings as b', 'b.id', '=', 'bp.booking_id');
        $this->applyScope($query, $scope, $bookingColumns, 'b');
        $query->selectRaw($this->projection('b', $bookingColumns, 'bp', $columns, $passport, null));
        $query->whereRaw($this->normalizedExpression('bp', $passport).' = ?', [$this->normalize($value)]);
        $rows = $query->orderByDesc('b.booking_date')->orderByDesc('b.id')->limit(250)->get();
        return $rows->map(fn (object $row): array => $this->baseRow($row, 'Passport', $this->mask((string) ($row->passport ?? '')), ''))->all();
    }

    private function ticketRows(string $value, array $scope): array
    {
        if (! Schema::hasTable('air_ticket_details') || ! Schema::hasTable('booking_services')) return [];
        $ticketColumns = Schema::getColumnListing('air_ticket_details');
        $ticket = $this->firstColumn($ticketColumns, self::TICKET_COLUMNS);
        if ($ticket === null || ! in_array('booking_service_id', $ticketColumns, true)) return [];
        $bookingColumns = Schema::getColumnListing('bookings');
        $passengerTable = $this->passengerTable();
        $query = DB::table('air_ticket_details as atd')
            ->join('booking_services as bs', 'bs.id', '=', 'atd.booking_service_id')
            ->join('bookings as b', 'b.id', '=', 'bs.booking_id');
        $passengerColumns = $passengerTable !== null ? Schema::getColumnListing($passengerTable) : [];
        // A ticket passenger may only be displayed when the installed
        // passenger table proves ownership by the same booking.  Joining by
        // passenger id alone is not sufficient because it can expose a
        // passenger record belonging to another booking.
        $passengerJoin = $passengerTable !== null
            && in_array('booking_passenger_id', $ticketColumns, true)
            && in_array('booking_id', $passengerColumns, true);
        if ($passengerJoin) {
            $query->leftJoin($passengerTable.' as bp', function ($join) use ($passengerColumns): void {
                $join->on('bp.id', '=', 'atd.booking_passenger_id');
                if (in_array('booking_id', $passengerColumns, true)) $join->whereColumn('bp.booking_id', 'bs.booking_id');
            });
        }
        $this->applyScope($query, $scope, $bookingColumns, 'b');
        $query->selectRaw($this->projection('b', $bookingColumns, $passengerJoin ? 'bp' : null, $passengerColumns, $passengerJoin ? $this->firstColumn($passengerColumns, self::PASSPORT_COLUMNS) : null, $ticket));
        $query->whereRaw($this->normalizedExpression('atd', $ticket).' = ?', [$this->normalize($value)]);
        $rows = $query->orderByDesc('b.booking_date')->orderByDesc('b.id')->limit(250)->get();
        return $rows->map(fn (object $row): array => $this->baseRow($row, 'Ticket', $this->mask((string) ($row->passport ?? '')), $this->mask((string) ($row->ticket ?? ''))))->all();
    }

    private function baseRow(object $row, string $match, string $passport, string $ticket): array
    {
        $passengerId = (int) ($row->passenger_id ?? 0);
        $passengerName = trim((string) ($row->passenger_name ?? ''));
        if ($match === 'Ticket' && ($passengerId <= 0 || $passengerName === '' || $passengerName === 'Passenger')) {
            $passengerName = 'Passenger attribution unavailable';
        } elseif ($passengerName === '') {
            $passengerName = 'Passenger name unavailable';
        }
        return [
            'booking_id' => (int) ($row->booking_id ?? 0),
            'passenger_id' => $passengerId,
            'passenger_name' => $passengerName,
            'passport' => $passport,
            'ticket' => $ticket,
            'booking_reference' => (string) ($row->booking_reference ?? ('Booking #'.(int) ($row->booking_id ?? 0))),
            'booking_date' => (string) ($row->booking_date ?? ''),
            'booking_status' => (string) ($row->booking_status ?? ''),
            'product' => 'Air',
            'match_type' => $match,
            'url' => url('/operations/bookings/'.(int) ($row->booking_id ?? 0)),
        ];
    }

    private function scope(Request $request): ?array
    {
        $user = $request->user();
        if (! $user) return null;
        // Match the native BookingController's company authority: the
        // current company is resolved from the native Company source, not a
        // user-supplied query parameter or finder input.
        if (! Schema::hasTable('companies')) return null;
        $companyId = (int) DB::table('companies')->orderBy('id')->value('id');
        if ($companyId <= 0) return null;
        $branchIds = [];
        $accessScope = (string) ($user->access_scope ?? '');
        if (($user->is_super_admin ?? false) || $accessScope === 'ALL_BRANCHES') {
            if (Schema::hasTable('branches')) {
                $branchIds = DB::table('branches')->where('company_id', $companyId)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
            }
        } elseif (! in_array($accessScope, ['PRIMARY_BRANCH', 'ASSIGNED_BRANCHES'], true)) {
            // Native branch scope values are case-sensitive.  Unknown values
            // fail closed instead of expanding access through normalization.
            return null;
        } elseif (method_exists($user, 'branches')) {
            $branchQuery = $user->branches()->where('branches.company_id', $companyId)->where('branches.is_active', true);
            if ($accessScope === 'PRIMARY_BRANCH') {
                $branchQuery->wherePivot('is_primary', true);
            }
            $branchIds = $branchQuery->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        }
        return $branchIds === [] ? null : ['company_id' => $companyId, 'branch_ids' => $branchIds];
    }

    private function applyScope($query, array $scope, array $bookingColumns, string $alias): void
    {
        if (! in_array('company_id', $bookingColumns, true) || ! in_array('branch_id', $bookingColumns, true)) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->where($alias.'.company_id', $scope['company_id'])->whereIn($alias.'.branch_id', $scope['branch_ids']);
    }

    private function passengerTable(): ?string
    {
        foreach (['booking_passengers', 'booking_travellers', 'booking_travelers'] as $table) if (Schema::hasTable($table)) return $table;
        return null;
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate;
        return null;
    }

    private function normalizedExpression(string $alias, string $column): string
    {
        return "UPPER(REPLACE(REPLACE(TRIM(COALESCE(`{$alias}`.`{$column}`, '')), ' ', ''), '-', ''))";
    }

    private function projection(string $bookingAlias, array $bookingColumns, ?string $passengerAlias, array $passengerColumns, ?string $passport, ?string $ticket): string
    {
        $bookingRef = $this->pick($bookingAlias, $bookingColumns, ['booking_no', 'booking_reference', 'reference', 'id']);
        $bookingDate = $this->pick($bookingAlias, $bookingColumns, ['booking_date', 'created_at', 'date']);
        $bookingStatus = $this->pick($bookingAlias, $bookingColumns, ['status', 'booking_status', 'workflow_status']);
        $name = "'Passenger'";
        $passengerId = '0';
        if ($passengerAlias !== null && $passengerColumns !== []) {
            $nameParts = [];
            foreach (['first_name', 'given_name', 'middle_name', 'last_name', 'surname'] as $column) {
                if (in_array($column, $passengerColumns, true)) $nameParts[] = "NULLIF(TRIM(`{$passengerAlias}`.`{$column}`), '')";
            }
            if (in_array('name', $passengerColumns, true)) $nameParts[] = "NULLIF(TRIM(`{$passengerAlias}`.`name`), '')";
            if ($nameParts !== []) $name = 'COALESCE('.implode(',', $nameParts).", 'Passenger')";
            if (in_array('id', $passengerColumns, true)) $passengerId = "COALESCE(`{$passengerAlias}`.`id`, 0)";
        }
        $parts = ["b.id as booking_id", "{$passengerId} as passenger_id", "{$name} as passenger_name", "{$bookingRef} as booking_reference", "{$bookingDate} as booking_date", "{$bookingStatus} as booking_status"];
        if ($passport !== null) $parts[] = "`bp`.`{$passport}` as passport";
        if ($ticket !== null) $parts[] = "`atd`.`{$ticket}` as ticket";
        return implode(', ', $parts);
    }

    private function pick(string $alias, array $columns, array $candidates): string
    {
        foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return "`{$alias}`.`{$candidate}`";
        return "''";
    }

    private function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/u', '', trim($value)));
    }

    private function mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        $keep = min(4, max(1, (int) ceil(strlen($value) / 3)));
        return str_repeat('•', max(0, strlen($value) - $keep)).substr($value, -$keep);
    }
}
