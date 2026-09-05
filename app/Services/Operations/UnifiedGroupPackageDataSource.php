<?php

namespace App\Services\Operations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UnifiedGroupPackageDataSource
{
    public function customers(): Collection
    {
        return $this->partiesByRole('customer');
    }

    public function vendors(): Collection
    {
        $vendors = $this->partiesByRole('vendor');
        if ($vendors->isNotEmpty()) {
            return $vendors;
        }

        // Some older Party Master rows do not classify supplier/customer roles.
        // Fall back to all parties so staff is never blocked from selecting the
        // package supplier.
        return $this->allParties();
    }

    public function branches(): Collection
    {
        if (! Schema::hasTable('branches')) {
            return collect();
        }

        $columns = Schema::getColumnListing('branches');
        $id = $this->firstColumn($columns, ['id']);
        $name = $this->firstColumn($columns, ['name', 'branch_name', 'title']);
        if (! $id || ! $name) {
            return collect();
        }

        return DB::table('branches')->orderBy($name)->get()->map(fn ($row): array => [
            'id' => data_get($row, $id),
            'name' => (string) data_get($row, $name),
        ]);
    }

    public function currencies(): Collection
    {
        foreach (['currencies', 'currency_master', 'travel_currencies'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $code = $this->firstColumn($columns, ['code', 'currency_code', 'iso_code']);
            if (! $code) {
                continue;
            }
            $name = $this->firstColumn($columns, ['name', 'currency_name', 'title']);

            return DB::table($table)->orderBy($code)->get()->map(fn ($row): array => [
                'code' => strtoupper((string) data_get($row, $code)),
                'name' => $name ? (string) data_get($row, $name) : strtoupper((string) data_get($row, $code)),
            ])->filter(fn (array $row) => $row['code'] !== '')->values();
        }

        return collect([
            ['code' => 'PKR', 'name' => 'Pakistan Rupee'],
            ['code' => 'SAR', 'name' => 'Saudi Riyal'],
            ['code' => 'USD', 'name' => 'US Dollar'],
            ['code' => 'AED', 'name' => 'UAE Dirham'],
        ]);
    }

    public function passengers(): Collection
    {
        $rows = collect();

        // Prefer current Passenger Master-style tables over historical booking
        // snapshots. Some installed ERPs use names such as passenger_profiles or
        // passenger_master; Schema discovery finds those dynamically. Keeping
        // booking_* sources last prevents an old booking snapshot from masking the
        // authoritative saved-passenger row during name/passport autocomplete.
        $candidateTables = $this->candidateTables(['passengers', 'travellers', 'travelers'], 'passenger');
        if (Schema::hasTable('booking_passengers')) {
            $candidateTables[] = 'booking_passengers';
        }
        $candidateTables = array_values(array_unique($candidateTables));
        usort($candidateTables, static function (string $left, string $right): int {
            $leftBooking = str_starts_with(strtolower($left), 'booking_') ? 1 : 0;
            $rightBooking = str_starts_with(strtolower($right), 'booking_') ? 1 : 0;
            return $leftBooking <=> $rightBooking;
        });

        foreach ($candidateTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            if (! in_array('id', $columns, true)) {
                continue;
            }

            try {
                $tableRows = DB::table($table)->orderByDesc('id')->limit(1600)->get();
            } catch (\Throwable) {
                continue;
            }

            foreach ($tableRows as $row) {
                $a = (array) $row;
                $first = (string) $this->value($a, $columns, ['first_name', 'given_name', 'name', 'passenger_name'], '');
                $last = (string) $this->value($a, $columns, ['last_name', 'surname', 'family_name'], '');
                $full = trim($first . ' ' . $last);
                $passport = (string) $this->value($a, $columns, ['passport_no', 'passport_number'], '');
                $dob = (string) $this->value($a, $columns, ['date_of_birth', 'dob', 'birth_date'], '');

                if ($full === '' && $passport === '') {
                    continue;
                }

                $rows->push([
                    'id' => (int) $a['id'],
                    'source_table' => $table,
                    'title' => (string) $this->value($a, $columns, ['title', 'salutation'], ''),
                    'first_name' => $first,
                    'last_name' => $last,
                    'name' => $full ?: ('Passenger #' . $a['id']),
                    'date_of_birth' => $dob,
                    'passport_no' => $passport,
                    'passport_expiry' => (string) $this->value($a, $columns, ['passport_expiry', 'passport_expiry_date'], ''),
                    'nationality' => (string) $this->value($a, $columns, ['nationality', 'nationality_name', 'country'], ''),
                    'fare_as' => strtoupper((string) $this->value($a, $columns, ['fare_as', 'age_type', 'passenger_type', 'pax_type'], '')),
                    'search_label' => '#' . $a['id'] . ' · ' . ($full ?: 'Passenger') . ($passport ? ' · ' . $passport : '') . ($dob ? ' · ' . $dob : ''),
                ]);
            }
        }

        // Deduplicate by passport first, then by name+DOB. Current Passenger
        // Master rows are deliberately encountered before booking snapshots, so
        // autocomplete keeps a reusable master identifier whenever one exists.
        $seen = [];

        return $rows->filter(function (array $row) use (&$seen): bool {
            $key = $row['passport_no'] !== ''
                ? 'P:' . strtolower(trim($row['passport_no']))
                : 'N:' . strtolower(trim($row['name'])) . '|' . $row['date_of_birth'];

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;
            return true;
        })->values();
    }

    public function hotels(): Collection
    {
        $rows = collect();

        foreach ($this->candidateTables(['hotels', 'travel_hotels'], 'hotel') as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $id = $this->firstColumn($columns, ['id']);
            $name = $this->firstColumn($columns, ['name', 'hotel_name', 'title']);
            if (! $id || ! $name) {
                continue;
            }

            foreach (DB::table($table)->orderBy($name)->limit(1200)->get() as $row) {
                $rows->push([
                    'id' => data_get($row, $id),
                    'name' => (string) data_get($row, $name),
                    'city' => (string) $this->value((array) $row, $columns, ['city', 'city_name', 'location'], ''),
                ]);
            }
        }

        return $rows->unique(fn (array $row) => strtolower($row['name'] . '|' . $row['city']))->values();
    }

    public function airlines(): Collection
    {
        $rows = collect();

        foreach ($this->candidateTables(['airlines', 'travel_airlines'], 'airline') as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $id = $this->firstColumn($columns, ['id']);
            $name = $this->firstColumn($columns, ['name', 'airline_name', 'title']);
            if (! $id || ! $name) {
                continue;
            }

            foreach (DB::table($table)->orderBy($name)->limit(600)->get() as $row) {
                $rows->push([
                    'id' => data_get($row, $id),
                    'name' => (string) data_get($row, $name),
                    'code' => (string) $this->value((array) $row, $columns, ['iata_code', 'code', 'airline_code'], ''),
                ]);
            }
        }

        return $rows->unique(fn (array $row) => strtolower($row['name'] . '|' . $row['code']))->values();
    }

    /**
     * Transport routes come primarily from transport_rate_cards and route/master
     * tables. When a dedicated master is absent, historical operational route
     * labels are used as a read-only backend fallback so staff still selects
     * existing ERP data instead of free-typing routes.
     */
    public function transportRoutes(): Collection
    {
        $rows = collect();
        $preferred = [
            'transport_rate_cards',
            'transport_routes',
            'travel_transport_routes',
            'transport_route_master',
            'transport_route_masters',
            'transport_master',
            'transport_masters',
            'travel_transports',
        ];

        foreach ($this->candidateTables($preferred, 'transport') as $table) {
            if (str_starts_with($table, 'booking_') || ! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $id = $this->firstColumn($columns, ['id', 'route_id', 'rate_card_id']);
            if (! $id) {
                continue;
            }

            $labelColumn = $this->firstColumn($columns, ['route_label', 'route_name', 'route', 'sector', 'name', 'title']);
            $fromColumn = $this->firstColumn($columns, ['from_location', 'pickup_location', 'origin', 'origin_name', 'from_city', 'from']);
            $toColumn = $this->firstColumn($columns, ['to_location', 'dropoff_location', 'destination', 'destination_name', 'to_city', 'to']);

            if (! $labelColumn && ! ($fromColumn && $toColumn)) {
                continue;
            }

            try {
                $tableRows = DB::table($table)->limit(1600)->get();
            } catch (\Throwable) {
                continue;
            }

            foreach ($tableRows as $row) {
                $a = (array) $row;
                $from = $fromColumn ? trim((string) ($a[$fromColumn] ?? '')) : '';
                $to = $toColumn ? trim((string) ($a[$toColumn] ?? '')) : '';
                $label = $labelColumn ? trim((string) ($a[$labelColumn] ?? '')) : '';
                if ($label === '') {
                    $label = trim($from . (($from !== '' && $to !== '') ? ' → ' : '') . $to);
                }
                if ($label === '') {
                    continue;
                }

                $rate = $this->value($a, $columns, [
                    'rate', 'rate_amount', 'amount', 'price', 'transport_rate', 'supplier_rate',
                    'vendor_rate', 'cost_rate', 'supplier_amount', 'supplier_cost', 'cost',
                    'fare', 'net_rate', 'sar_rate', 'sr_rate',
                ]);
                $currency = strtoupper((string) $this->value($a, $columns, [
                    'currency_code', 'currency', 'supplier_currency_code', 'rate_currency',
                ], ''));
                $vehicle = trim((string) $this->value($a, $columns, [
                    'vehicle_type', 'vehicle_name', 'vehicle', 'transport_type',
                ], ''));
                $company = trim((string) $this->value($a, $columns, [
                    'company_name', 'provider_name', 'transport_company', 'vendor_name', 'supplier_name',
                ], ''));
                $contact = trim((string) $this->value($a, $columns, [
                    'contact_number', 'provider_contact', 'phone', 'mobile', 'driver_contact',
                ], ''));
                $brn = trim((string) $this->value($a, $columns, [
                    'brn_number', 'brn', 'provider_reference', 'booking_reference', 'reference',
                ], ''));

                $rateText = '';
                if ($rate !== null && $rate !== '' && is_numeric($rate)) {
                    $rateText = trim(($currency !== '' ? $currency . ' ' : '') . number_format((float) $rate, 2));
                }

                $display = $label . ($rateText !== '' ? ' — ' . $rateText : '');

                $rows->push([
                    'id' => (int) ($a[$id] ?? 0),
                    'source_table' => $table,
                    'key' => $table . ':' . (string) ($a[$id] ?? ''),
                    'name' => $label,
                    'display' => $display,
                    'from_location' => $from,
                    'to_location' => $to,
                    'vehicle_type' => $vehicle,
                    'company_name' => $company,
                    'contact_number' => $contact,
                    'brn_number' => $brn,
                    'rate_amount' => ($rate !== null && $rate !== '' && is_numeric($rate)) ? (float) $rate : null,
                    'rate_currency' => $currency,
                ]);
            }
        }

        /* Backend fallback: previously saved operational routes. */
        if ($rows->isEmpty() && Schema::hasTable('booking_transport_segments')) {
            $columns = Schema::getColumnListing('booking_transport_segments');
            $labelColumn = $this->firstColumn($columns, ['route_label', 'route_name', 'route']);
            $fromColumn = $this->firstColumn($columns, ['pickup_location', 'from_location']);
            $toColumn = $this->firstColumn($columns, ['dropoff_location', 'to_location']);

            try {
                foreach (DB::table('booking_transport_segments')->orderByDesc('id')->limit(1200)->get() as $row) {
                    $a = (array) $row;
                    $from = $fromColumn ? trim((string) ($a[$fromColumn] ?? '')) : '';
                    $to = $toColumn ? trim((string) ($a[$toColumn] ?? '')) : '';
                    $label = $labelColumn ? trim((string) ($a[$labelColumn] ?? '')) : '';
                    if ($label === '') {
                        $label = trim($from . (($from !== '' && $to !== '') ? ' → ' : '') . $to);
                    }
                    if ($label === '') {
                        continue;
                    }

                    $rows->push([
                        'id' => (int) ($a['id'] ?? 0),
                        'source_table' => 'booking_transport_segments',
                        'key' => 'booking_transport_segments:' . (string) ($a['id'] ?? ''),
                        'name' => $label,
                        'display' => $label,
                        'from_location' => $from,
                        'to_location' => $to,
                        'vehicle_type' => trim((string) $this->value($a, $columns, ['vehicle_type'], '')),
                        'company_name' => trim((string) $this->value($a, $columns, ['company_name', 'provider_name'], '')),
                        'contact_number' => trim((string) $this->value($a, $columns, ['contact_number', 'driver_contact'], '')),
                        'brn_number' => trim((string) $this->value($a, $columns, ['brn_number', 'provider_reference'], '')),
                        'rate_amount' => null,
                        'rate_currency' => '',
                    ]);
                }
            } catch (\Throwable) {
                // Empty fallback is safe.
            }
        }

        return $rows
            ->filter(fn (array $row): bool => $row['name'] !== '')
            ->unique(fn (array $row): string => strtolower($row['name'] . '|' . $row['source_table'] . '|' . $row['id']))
            ->sortBy('display', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** Vehicle choices are master-driven. If no dedicated vehicle master is
     * available, derive the choices from route/rate-card and saved backend
     * vehicle types instead of allowing arbitrary free text. */
    public function transportVehicles(): Collection
    {
        $rows = collect();
        $preferred = [
            'transport_vehicles',
            'transport_vehicle_types',
            'vehicle_types',
            'travel_vehicles',
            'vehicles',
            'transport_rate_cards',
        ];

        foreach ($this->candidateTables($preferred, 'vehicle') as $table) {
            if (str_starts_with($table, 'booking_') || ! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $id = $this->firstColumn($columns, ['id', 'vehicle_id', 'type_id']);
            $name = $this->firstColumn($columns, ['vehicle_type', 'vehicle_name', 'name', 'title', 'type']);
            if (! $name) {
                continue;
            }

            try {
                foreach (DB::table($table)->limit(1000)->get() as $row) {
                    $a = (array) $row;
                    $vehicleName = trim((string) ($a[$name] ?? ''));
                    if ($vehicleName === '') {
                        continue;
                    }
                    $rowId = $id ? (int) ($a[$id] ?? 0) : 0;
                    $rows->push([
                        'id' => $rowId,
                        'source_table' => $table,
                        'key' => $table . ':' . $rowId . ':' . strtolower($vehicleName),
                        'name' => $vehicleName,
                    ]);
                }
            } catch (\Throwable) {
                // Try next backend source.
            }
        }

        /* transport_rate_cards is transport-oriented, so it may not have been
         * discovered by candidateTables(..., 'vehicle'). */
        if (Schema::hasTable('transport_rate_cards')) {
            $columns = Schema::getColumnListing('transport_rate_cards');
            $name = $this->firstColumn($columns, ['vehicle_type', 'vehicle_name', 'vehicle', 'transport_type']);
            if ($name) {
                try {
                    foreach (DB::table('transport_rate_cards')->limit(1200)->get() as $row) {
                        $a = (array) $row;
                        $vehicleName = trim((string) ($a[$name] ?? ''));
                        if ($vehicleName !== '') {
                            $rows->push([
                                'id' => (int) ($a['id'] ?? 0),
                                'source_table' => 'transport_rate_cards',
                                'key' => 'transport_rate_cards:' . (string) ($a['id'] ?? '') . ':' . strtolower($vehicleName),
                                'name' => $vehicleName,
                            ]);
                        }
                    }
                } catch (\Throwable) {}
            }
        }

        if (Schema::hasTable('booking_transport_segments')) {
            $columns = Schema::getColumnListing('booking_transport_segments');
            $name = $this->firstColumn($columns, ['vehicle_type']);
            if ($name) {
                try {
                    foreach (DB::table('booking_transport_segments')->whereNotNull($name)->orderByDesc('id')->limit(1000)->get() as $row) {
                        $a = (array) $row;
                        $vehicleName = trim((string) ($a[$name] ?? ''));
                        if ($vehicleName !== '') {
                            $rows->push([
                                'id' => 0,
                                'source_table' => 'booking_transport_segments',
                                'key' => 'booking_transport_segments:0:' . strtolower($vehicleName),
                                'name' => $vehicleName,
                            ]);
                        }
                    }
                } catch (\Throwable) {}
            }
        }

        /* Also derive any vehicle names embedded in route/rate masters. */
        foreach ($this->transportRoutes() as $route) {
            $vehicleName = trim((string) ($route['vehicle_type'] ?? ''));
            if ($vehicleName !== '') {
                $rows->push([
                    'id' => 0,
                    'source_table' => (string) ($route['source_table'] ?? 'transport_route'),
                    'key' => (string) ($route['source_table'] ?? 'transport_route') . ':0:' . strtolower($vehicleName),
                    'name' => $vehicleName,
                ]);
            }
        }

        return $rows
            ->filter(fn (array $row): bool => $row['name'] !== '')
            ->unique(fn (array $row): string => strtolower($row['name']))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function partiesByRole(string $role): Collection
    {
        foreach (['parties', $role === 'customer' ? 'customers' : 'vendors', 'suppliers'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $id = $this->firstColumn($columns, ['id']);
            $name = $this->firstColumn($columns, ['name', 'display_name', 'legal_name', 'customer_name', 'vendor_name', 'supplier_name', 'title']);
            if (! $id || ! $name) {
                continue;
            }

            $query = DB::table($table);
            if ($table === 'parties') {
                $typeColumn = $this->firstColumn($columns, ['party_type', 'type', 'category', 'role']);
                if ($typeColumn) {
                    if ($role === 'customer') {
                        $query->where(function ($q) use ($typeColumn): void {
                            $q->whereRaw('LOWER(' . $typeColumn . ") LIKE '%customer%'")
                                ->orWhereNull($typeColumn);
                        });
                    } else {
                        $query->where(function ($q) use ($typeColumn): void {
                            $q->whereRaw('LOWER(' . $typeColumn . ") LIKE '%vendor%'")
                                ->orWhereRaw('LOWER(' . $typeColumn . ") LIKE '%supplier%'")
                                ->orWhereRaw('LOWER(' . $typeColumn . ") LIKE '%service%'");
                        });
                    }
                }
            }

            $result = $query->orderBy($name)->limit(1200)->get()->map(fn ($row): array => [
                'id' => (int) data_get($row, $id),
                'name' => (string) data_get($row, $name),
            ])->filter(fn (array $row) => $row['name'] !== '')->values();

            if ($result->isNotEmpty()) {
                return $result;
            }
        }

        return collect();
    }

    private function allParties(): Collection
    {
        foreach (['parties', 'vendors', 'suppliers', 'customers'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $id = $this->firstColumn($columns, ['id']);
            $name = $this->firstColumn($columns, ['name', 'display_name', 'legal_name', 'vendor_name', 'supplier_name', 'customer_name', 'title']);
            if (! $id || ! $name) {
                continue;
            }

            return DB::table($table)->orderBy($name)->limit(1500)->get()->map(fn ($row): array => [
                'id' => (int) data_get($row, $id),
                'name' => (string) data_get($row, $name),
            ])->filter(fn (array $row) => $row['name'] !== '')->values();
        }

        return collect();
    }

    private function candidateTables(array $preferred, string $needle): array
    {
        $tables = $preferred;

        try {
            foreach (Schema::getTables() as $meta) {
                $name = is_array($meta) ? ($meta['name'] ?? null) : data_get($meta, 'name');
                if ($name && Str::contains(strtolower((string) $name), strtolower($needle))) {
                    $tables[] = (string) $name;
                }
            }
        } catch (\Throwable) {
            // Preferred list remains sufficient on older schema drivers.
        }

        return array_values(array_unique($tables));
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function value(array $row, array $columns, array $candidates, mixed $default = null): mixed
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true) && array_key_exists($candidate, $row) && $row[$candidate] !== null) {
                return $row[$candidate];
            }
        }

        return $default;
    }
}
