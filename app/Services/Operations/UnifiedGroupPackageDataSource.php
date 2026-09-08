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
                // Rate-card rows may use a generic `name` column for the
                // vehicle (for example, "Car").  A real origin/destination
                // pair is the Route authority and must win over that generic
                // label, otherwise the Route select hydrates with the Vehicle
                // value and cannot locate the matching rate.
                $routePair = trim($from . (($from !== '' && $to !== '') ? ' → ' : '') . $to);
                $label = ($from !== '' && $to !== '')
                    ? $routePair
                    : ($labelColumn ? trim((string) ($a[$labelColumn] ?? '')) : '');
                if ($label === '') $label = $routePair;
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

    /**
     * The GENERAL booking Transport editor consumes only the current effective
     * rate-card matrix. Generic transport tables deliberately do not participate
     * here: a card title or vehicle label must never become a Route option.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function effectiveTransportRateRoutes(string $companyName = '', ?string $effectiveDate = null, ?int $companyId = null): Collection
    {
        if (! Schema::hasTable('transport_rate_cards')) return collect();
        try {
            $columns = Schema::getColumnListing('transport_rate_cards');
            $idColumn = $this->firstColumn($columns, ['id', 'rate_detail_id', 'transport_rate_card_detail_id']);
            $fromColumn = $this->firstColumn($columns, ['from_location', 'pickup_location', 'origin', 'origin_name', 'from_city', 'from']);
            $toColumn = $this->firstColumn($columns, ['to_location', 'dropoff_location', 'destination', 'destination_name', 'to_city', 'to']);
            $vehicleColumn = $this->firstColumn($columns, ['vehicle_type', 'vehicle_name', 'vehicle', 'transport_type']);
            if (! $idColumn) return collect();
            $companyColumn = $this->firstColumn($columns, ['company_name', 'provider_name', 'transport_company', 'vendor_name', 'supplier_name']);
            $companyIdColumn = $this->firstColumn($columns, ['transport_company_id', 'company_id', 'vendor_id', 'supplier_id', 'service_provider_id']);
            $currencyColumn = $this->firstColumn($columns, ['currency_code', 'currency', 'supplier_currency_code', 'rate_currency']);
            $activeColumn = $this->firstColumn($columns, ['is_active', 'active']);
            $statusColumn = $this->firstColumn($columns, ['status', 'card_status']);
            $fromDateColumn = $this->firstColumn($columns, ['effective_from', 'valid_from', 'start_date', 'effective_date']);
            $toDateColumn = $this->firstColumn($columns, ['effective_to', 'valid_to', 'end_date', 'expiry_date']);
            $asOf = $effectiveDate ?: now()->toDateString();
            $companyKey = strtolower(trim($companyName));
            $rows = collect();
            $headers = [];
            foreach (DB::table('transport_rate_cards')->limit(4000)->get() as $object) {
                $row = (array) $object;
                if ($activeColumn && ! (bool) ($row[$activeColumn] ?? false)) continue;
                $status = strtolower(trim((string) ($statusColumn ? ($row[$statusColumn] ?? '') : '')));
                if (in_array($status, ['inactive', 'draft', 'deleted', 'removed', 'expired', 'cancelled', 'canceled'], true)) continue;
                $fromDate = trim((string) ($fromDateColumn ? ($row[$fromDateColumn] ?? '') : ''));
                $toDate = trim((string) ($toDateColumn ? ($row[$toDateColumn] ?? '') : ''));
                if ($fromDate !== '' && $fromDate > $asOf) continue;
                if ($toDate !== '' && $toDate < $asOf) continue;
                $company = trim((string) ($companyColumn ? ($row[$companyColumn] ?? '') : ''));
                if ($companyKey !== '' && strtolower($company) !== $companyKey) continue;
                if ($companyId !== null && $companyId > 0 && (int) ($companyIdColumn ? ($row[$companyIdColumn] ?? 0) : 0) !== $companyId) continue;
                $headerId = (int) ($row[$idColumn] ?? 0);
                if ($headerId <= 0) continue;
                $headers[$headerId] = [
                    'id' => $headerId,
                    'company_name' => $company,
                    'company_id' => (int) ($companyIdColumn ? ($row[$companyIdColumn] ?? 0) : 0),
                    'rate_currency' => strtoupper(trim((string) ($currencyColumn ? ($row[$currencyColumn] ?? '') : ''))),
                    'effective_from' => $fromDate,
                    'card_name' => trim((string) ($this->value($row, $columns, ['name', 'card_name', 'title', 'rate_card_name'], ''))),
                ];
                $from = trim((string) ($fromColumn ? ($row[$fromColumn] ?? '') : ''));
                $to = trim((string) ($toColumn ? ($row[$toColumn] ?? '') : ''));
                $vehicle = trim((string) ($vehicleColumn ? ($row[$vehicleColumn] ?? '') : ''));
                if ($from === '' || $to === '') continue;
                if ($vehicle === '') {
                    foreach (['car', 'coaster', 'gmc', 'hiace', 'starex', 'van', 'bus', 'sedan', 'suv'] as $vehicleName) {
                        [$matrixField, $matrixRate] = $this->transportMatrixRate($row, $columns, $vehicleName);
                        if ($matrixField === null || ! is_numeric($matrixRate)) continue;
                        $rows->push([
                            'id' => $headerId,
                            'source_table' => 'transport_rate_cards',
                            'rate_card_id' => $headerId,
                            'name' => $from.' → '.$to,
                            'display' => $from.' → '.$to,
                            'from_location' => $from,
                            'to_location' => $to,
                            'vehicle_type' => strtoupper($vehicleName) === 'GMC' ? 'GMC' : ucfirst($vehicleName),
                            'company_name' => $company,
                            'rate_amount' => (float) $matrixRate,
                            'rate_field' => $matrixField,
                            'rate_raw_value' => $matrixRate,
                            'rate_currency' => strtoupper(trim((string) ($currencyColumn ? ($row[$currencyColumn] ?? '') : ''))),
                            'effective_from' => $fromDate,
                        ]);
                    }
                    continue;
                }
                // A rate-card matrix can use either a generic rate column or a
                // vehicle-specific cell such as car_rate.  Prefer the selected
                // vehicle's cell, and never let a zero generic placeholder hide
                // its non-zero matrix value.
                [$rateColumn, $rate] = $this->transportMatrixRate($row, $columns, $vehicle);
                $rows->push([
                    'id' => (int) ($row[$idColumn] ?? 0),
                    'source_table' => 'transport_rate_cards',
                    'rate_card_id' => $headerId,
                    'name' => $from.' → '.$to,
                    'display' => $from.' → '.$to,
                    'from_location' => $from,
                    'to_location' => $to,
                    'vehicle_type' => $vehicle,
                    'company_name' => $company,
                    'rate_amount' => is_numeric($rate) ? (float) $rate : null,
                    'rate_field' => $rateColumn,
                    'rate_raw_value' => $rate,
                    'rate_currency' => strtoupper(trim((string) ($currencyColumn ? ($row[$currencyColumn] ?? '') : ''))),
                    'effective_from' => $fromDate,
                ]);
            }
            // Production's canonical structure is normalized:
            // card -> transport_rates -> route + vehicle type -> cost_amount.
            // Put this exact native authority first so it wins over any legacy
            // wide-column or generic discovery result for the same selection.
            $rows = $this->normalizedTransportRateRows($headers)
                ->merge($rows)
                ->merge($this->transportRateCardMatrixRows($headers));
            $latest = $rows->pluck('effective_from')->filter()->max();
            if ($latest !== null && $latest !== '') $rows = $rows->filter(static fn (array $row): bool => (string) $row['effective_from'] === (string) $latest);
            return $rows->unique(static fn (array $row): string => strtolower($row['company_name'].'|'.$row['name'].'|'.$row['vehicle_type'].'|'.$row['id']))->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Resolve the host's normalized Transport price pivot. This is deliberately
     * exact rather than a column-name guess: one rate row belongs to one active
     * card, route and vehicle type, and cost_amount is its source-currency cost.
     *
     * @param array<int,array<string,mixed>> $headers
     * @return Collection<int,array<string,mixed>>
     */
    private function normalizedTransportRateRows(array $headers): Collection
    {
        if ($headers === [] || ! Schema::hasTable('transport_rates') || ! Schema::hasTable('transport_routes') || ! Schema::hasTable('transport_vehicle_types')) return collect();
        try {
            $rateColumns = Schema::getColumnListing('transport_rates');
            $cardColumn = $this->firstColumn($rateColumns, ['transport_rate_card_id']);
            $routeColumn = $this->firstColumn($rateColumns, ['transport_route_id']);
            $vehicleColumn = $this->firstColumn($rateColumns, ['transport_vehicle_type_id']);
            $rateColumn = $this->firstColumn($rateColumns, ['cost_amount']);
            $idColumn = $this->firstColumn($rateColumns, ['id']);
            if (! $cardColumn || ! $routeColumn || ! $vehicleColumn || ! $rateColumn || ! $idColumn) return collect();

            $routeColumns = Schema::getColumnListing('transport_routes');
            $routeId = $this->firstColumn($routeColumns, ['id']);
            $routeName = $this->firstColumn($routeColumns, ['route_label', 'route_name', 'name', 'title']);
            $fromColumn = $this->firstColumn($routeColumns, ['pickup_location', 'from_location', 'origin', 'from_city']);
            $toColumn = $this->firstColumn($routeColumns, ['dropoff_location', 'to_location', 'destination', 'to_city']);
            $vehicleColumns = Schema::getColumnListing('transport_vehicle_types');
            $vehicleId = $this->firstColumn($vehicleColumns, ['id']);
            $vehicleName = $this->firstColumn($vehicleColumns, ['name', 'vehicle_type', 'vehicle_name', 'code', 'title']);
            if (! $routeId || ! $vehicleId || ! $vehicleName) return collect();

            $routes = [];
            foreach (DB::table('transport_routes')->get() as $object) {
                $row = (array) $object;
                $id = (int) ($row[$routeId] ?? 0);
                if ($id <= 0) continue;
                $from = trim((string) ($fromColumn ? ($row[$fromColumn] ?? '') : ''));
                $to = trim((string) ($toColumn ? ($row[$toColumn] ?? '') : ''));
                $name = trim((string) ($routeName ? ($row[$routeName] ?? '') : ''));
                if ($name === '') $name = trim($from.(($from !== '' && $to !== '') ? ' → ' : '').$to);
                if ($name !== '') $routes[$id] = ['name' => $name, 'from' => $from, 'to' => $to];
            }
            $vehicles = [];
            foreach (DB::table('transport_vehicle_types')->get() as $object) {
                $row = (array) $object;
                $id = (int) ($row[$vehicleId] ?? 0);
                $name = trim((string) ($row[$vehicleName] ?? ''));
                if ($id > 0 && $name !== '') $vehicles[$id] = $name;
            }

            $rows = collect();
            foreach (DB::table('transport_rates')->whereIn($cardColumn, array_keys($headers))->get() as $object) {
                $row = (array) $object;
                $cardId = (int) ($row[$cardColumn] ?? 0);
                $routeIdValue = (int) ($row[$routeColumn] ?? 0);
                $vehicleIdValue = (int) ($row[$vehicleColumn] ?? 0);
                $header = $headers[$cardId] ?? null;
                $route = $routes[$routeIdValue] ?? null;
                $vehicle = $vehicles[$vehicleIdValue] ?? null;
                if (! is_array($header) || ! is_array($route) || ! is_string($vehicle)) continue;
                $rate = $row[$rateColumn] ?? null;
                $rows->push([
                    'id' => (int) ($row[$idColumn] ?? 0),
                    'source_table' => 'transport_rates',
                    'rate_card_id' => $cardId,
                    'rate_card_name' => (string) ($header['card_name'] ?? ''),
                    'transport_route_id' => $routeIdValue,
                    'transport_vehicle_type_id' => $vehicleIdValue,
                    'name' => (string) $route['name'],
                    'display' => (string) $route['name'],
                    'from_location' => (string) $route['from'],
                    'to_location' => (string) $route['to'],
                    'vehicle_type' => $vehicle,
                    'company_name' => (string) ($header['company_name'] ?? ''),
                    'company_id' => (int) ($header['company_id'] ?? 0),
                    'rate_amount' => is_numeric($rate) ? (float) $rate : null,
                    'rate_field' => 'cost_amount',
                    'rate_raw_value' => $rate,
                    'rate_currency' => (string) ($header['rate_currency'] ?? ''),
                    'effective_from' => (string) ($header['effective_from'] ?? ''),
                ]);
            }
            return $rows;
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Some host installations keep Transport cards as headers and the route /
     * vehicle prices in a card-linked matrix table. Discover that native matrix
     * by schema rather than assuming the booking row already carries its IDs.
     *
     * @param array<int,array<string,mixed>> $headers
     * @return Collection<int,array<string,mixed>>
     */
    private function transportRateCardMatrixRows(array $headers): Collection
    {
        if ($headers === []) return collect();
        $tables = [
            'transport_rate_card_details', 'transport_rate_card_routes',
            'transport_rate_matrices', 'transport_rate_matrix',
            'transport_rate_details', 'transport_vendor_rate_card_details',
        ];
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                $key = strtolower($table);
                if ($table !== '' && str_contains($key, 'transport') && (str_contains($key, 'rate') || str_contains($key, 'card'))) $tables[] = $table;
            }
        } catch (\Throwable) {
        }

        $rows = collect();
        foreach (array_values(array_unique($tables)) as $table) {
            if ($table === 'transport_rate_cards' || ! Schema::hasTable($table)) continue;
            try {
                $columns = Schema::getColumnListing($table);
                $cardColumn = $this->firstColumn($columns, ['transport_rate_card_id', 'rate_card_id', 'transport_vendor_rate_card_id', 'card_id']);
                $idColumn = $this->firstColumn($columns, ['id', 'rate_detail_id', 'transport_rate_card_detail_id']);
                $fromColumn = $this->firstColumn($columns, ['from_location', 'pickup_location', 'origin', 'origin_name', 'from_city', 'from']);
                $toColumn = $this->firstColumn($columns, ['to_location', 'dropoff_location', 'destination', 'destination_name', 'to_city', 'to']);
                if (! $cardColumn || ! $idColumn || ! $fromColumn || ! $toColumn) continue;
                $vehicleColumn = $this->firstColumn($columns, ['vehicle_type', 'vehicle_name', 'vehicle', 'transport_type']);
                $currencyColumn = $this->firstColumn($columns, ['currency_code', 'currency', 'supplier_currency_code', 'rate_currency']);
                foreach (DB::table($table)->whereIn($cardColumn, array_keys($headers))->limit(8000)->get() as $object) {
                    $row = (array) $object;
                    $cardId = (int) ($row[$cardColumn] ?? 0);
                    $header = $headers[$cardId] ?? null;
                    if (! is_array($header)) continue;
                    $from = trim((string) ($row[$fromColumn] ?? ''));
                    $to = trim((string) ($row[$toColumn] ?? ''));
                    if ($from === '' || $to === '') continue;
                    $currency = strtoupper(trim((string) ($currencyColumn ? ($row[$currencyColumn] ?? '') : ($header['rate_currency'] ?? ''))));
                    $base = [
                        'id' => (int) ($row[$idColumn] ?? 0),
                        'source_table' => $table,
                        'rate_card_id' => $cardId,
                        'rate_card_name' => (string) ($header['card_name'] ?? ''),
                        'name' => $from.' → '.$to,
                        'display' => $from.' → '.$to,
                        'from_location' => $from,
                        'to_location' => $to,
                        'company_name' => (string) ($header['company_name'] ?? ''),
                        'company_id' => (int) ($header['company_id'] ?? 0),
                        'rate_currency' => $currency,
                        'effective_from' => (string) ($header['effective_from'] ?? ''),
                    ];
                    $vehicle = trim((string) ($vehicleColumn ? ($row[$vehicleColumn] ?? '') : ''));
                    if ($vehicle !== '') {
                        [$field, $rate] = $this->transportMatrixRate($row, $columns, $vehicle);
                        $rows->push($base + ['vehicle_type' => $vehicle, 'rate_amount' => is_numeric($rate) ? (float) $rate : null, 'rate_field' => $field, 'rate_raw_value' => $rate]);
                        continue;
                    }
                    // Wide native matrices expose one column per known vehicle;
                    // each non-null cell becomes its own exact vehicle row.
                    foreach (['car', 'coaster', 'gmc', 'hiace', 'starex', 'van', 'bus', 'sedan', 'suv'] as $vehicleName) {
                        [$field, $rate] = $this->transportMatrixRate($row, $columns, $vehicleName);
                        if ($field === null || ! is_numeric($rate)) continue;
                        $rows->push($base + ['vehicle_type' => strtoupper($vehicleName) === 'GMC' ? 'GMC' : ucfirst($vehicleName), 'rate_amount' => (float) $rate, 'rate_field' => $field, 'rate_raw_value' => $rate]);
                    }
                }
            } catch (\Throwable) {
                // Another host-specific matrix table must not disable a valid one.
            }
        }
        return $rows;
    }

    /**
     * Resolve the native value belonging to one rate-card matrix vehicle.
     *
     * @return array{0:?string,1:mixed}
     */
    private function transportMatrixRate(array $row, array $columns, string $vehicle): array
    {
        $vehicleKey = strtolower(trim($vehicle));
        $vehicleKey = preg_replace('/[^a-z0-9]+/', '_', $vehicleKey) ?? '';
        $vehicleKey = trim($vehicleKey, '_');

        $vehicleCandidates = $vehicleKey === '' ? [] : [
            $vehicleKey.'_rate', $vehicleKey.'_rate_sar', $vehicleKey.'_sar_rate',
            'rate_'.$vehicleKey, 'rate_'.$vehicleKey.'_sar',
            $vehicleKey.'_cost', $vehicleKey.'_cost_sar', $vehicleKey.'_vendor_rate',
            $vehicleKey.'_supplier_rate', $vehicleKey.'_price', $vehicleKey.'_amount',
            $vehicleKey,
        ];
        $genericCandidates = [
            'rate', 'rate_amount', 'amount', 'price', 'transport_rate', 'supplier_rate',
            'vendor_rate', 'cost_rate', 'supplier_amount', 'supplier_cost', 'vendor_cost',
            'cost_amount', 'cost_price', 'supplier_price', 'vendor_price', 'purchase_price',
            'cost', 'fare', 'net_rate', 'sar_rate', 'sr_rate', 'rate_sar', 'cost_sar',
        ];

        // Prefer a positive selected-vehicle matrix value. This is important
        // where a legacy generic rate field remains at 0 while the real matrix
        // stores the current Car/Coaster/etc. amount in a vehicle-specific cell.
        foreach (array_merge($vehicleCandidates, $genericCandidates) as $column) {
            if (! in_array($column, $columns, true) || ! array_key_exists($column, $row)) continue;
            $value = $row[$column];
            if (is_numeric($value) && (float) $value > 0) return [$column, $value];
        }

        // A zero is still meaningful when it is the only configured matrix
        // value, but it must be returned only after every positive native field
        // has been considered.
        foreach (array_merge($vehicleCandidates, $genericCandidates) as $column) {
            if (in_array($column, $columns, true) && array_key_exists($column, $row) && is_numeric($row[$column])) {
                return [$column, $row[$column]];
            }
        }

        return [null, null];
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
