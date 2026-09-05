<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use App\Services\Operations\LegacyVisaTravelMasterRepository;
use App\Services\Operations\BookingCommercialCompletenessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ERP-11.3.142 — GENERAL / MULTI-SERVICE Visa product.
 *
 * Saudi Company and Pakistani IATA are reporting dimensions only:
 * Saudi Company -> Pakistani IATA -> ERP Vendor Account.
 * Financial liability remains on the linked Vendor Account only.
 */
final class GeneralBookingVisaProductController extends Controller
{
    /** @var array<string,float|null> */
    private array $exchangeRateToPkrCache = [];

    /** @var list<array{id:int,name:string}>|null */
    private ?array $vendorOptionsCache = null;

    public function __construct(private readonly LegacyVisaTravelMasterRepository $visaMasters)
    {
    }

    public function show(Request $request, int $booking): JsonResponse
    {
        $this->assertBooking($booking);
        $this->assertSchema();

        $passengers = $this->bookingPassengers($booking);
        $rows = DB::table('booking_visa_services')
            ->where('booking_id', $booking)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->presentRow((array) $row, $passengers))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'booking_id' => $booking,
            'currency' => 'PKR',
            'passengers' => $passengers,
            'visa_rows' => $rows,
            'saudi_companies' => $this->saudiCompanies(),
            'pakistani_iatas' => $this->pakistaniIatas(),
            'rates' => $this->rateCards(),
            'vendors' => $this->vendorOptions(),
            'statuses' => $this->statusOptions(),
            'summary' => $this->summary($rows),
            'setup_url' => route('travel-masters.visa-management', ['booking' => $booking]),
        ]);
    }

    public function store(Request $request, int $booking): JsonResponse
    {
        $bookingRow = $this->assertBooking($booking);
        $this->assertSchema();

        $data = $request->validate([
            // ERP-11.3.154: an explicitly empty collection is a valid atomic
            // removal of the booking's last Visa passenger.
            'visas' => ['present', 'array', 'max:250'],
            'visas.*.booking_passenger_id' => ['required', 'integer', 'min:1'],
            'visas.*.visa_rate_card_id' => ['nullable', 'integer', 'min:0'],
            'visas.*.country' => ['nullable', 'string', 'max:120'],
            'visas.*.visa_type' => ['nullable', 'string', 'max:120'],
            'visas.*.saudi_company_id' => ['nullable', 'integer', 'min:0'],
            'visas.*.application_reference' => ['nullable', 'string', 'max:180'],
            'visas.*.visa_number' => ['nullable', 'string', 'max:180'],
            'visas.*.status' => ['nullable', 'string', 'max:40'],
            'visas.*.issue_date' => ['nullable', 'date'],
            'visas.*.expiry_date' => ['nullable', 'date'],
            'visas.*.sale_pkr' => ['required', 'numeric', 'min:0'],
            'visas.*.cost_currency' => ['nullable', 'string', 'max:12'],
            'visas.*.cost_rate' => ['nullable', 'numeric', 'min:0'],
            'visas.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $passengers = collect($this->bookingPassengers($booking))->keyBy('id');
        if ($passengers->isEmpty()) {
            throw ValidationException::withMessages(['visa' => 'Add at least one booking passenger before saving Visa data.']);
        }

        $seen = [];
        $normalized = [];
        foreach ((array) $data['visas'] as $index => $raw) {
            $passengerId = (int) ($raw['booking_passenger_id'] ?? 0);
            if (! $passengers->has($passengerId)) {
                throw ValidationException::withMessages(["visas.$index.booking_passenger_id" => 'Selected passenger does not belong to this booking.']);
            }
            if (isset($seen[$passengerId])) {
                throw ValidationException::withMessages(["visas.$index.booking_passenger_id" => 'A passenger can appear only once in the Visa product.']);
            }
            $seen[$passengerId] = true;

            $rateId = max(0, (int) ($raw['visa_rate_card_id'] ?? 0));
            $today = now()->toDateString();
            $rate = $rateId > 0
                ? DB::table('visa_rate_cards')
                    ->where('id', $rateId)
                    ->where('is_active', true)
                    ->where('effective_from', '<=', $today)
                    ->where(function ($query) use ($today): void {
                        $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
                    })
                    ->first()
                : null;

            if (! $rate) {
                throw ValidationException::withMessages(["visas.$index.visa_rate_card_id" => 'Select a currently effective Visa Rate from Travel Masters → Visa Management.']);
            }

            $saudiMasterTable = trim((string) ($rate->saudi_master_table ?? ''));
            $saudiMasterId = (int) ($rate->saudi_master_id ?? 0);
            $saudi = $saudiMasterTable !== '' && $saudiMasterId > 0
                ? $this->visaMasters->findSaudiByKey($saudiMasterTable.':'.$saudiMasterId)
                : null;
            if (! $saudi || ! (bool) ($saudi['is_active'] ?? true) || ! (bool) ($saudi['link_complete'] ?? false)) {
                throw ValidationException::withMessages(["visas.$index.visa_rate_card_id" => 'Visa Rate does not have a complete Saudi Company → Pakistani IATA → Vendor link. Update the native Travel Masters link and recreate the Visa Rate.']);
            }

            $saudiId = (int) ($saudi['id'] ?? 0);
            $iataId = (int) ($saudi['pakistani_iata_id'] ?? 0);
            $vendorId = (int) ($saudi['vendor_id'] ?? 0);

            $saudiName = trim((string) ($rate->saudi_company_name_snapshot ?? '')) ?: trim((string) ($saudi['name'] ?? ''));
            $iataName = trim((string) ($rate->pakistani_iata_name_snapshot ?? '')) ?: trim((string) ($saudi['pakistani_iata_name'] ?? ''));

            $country = trim((string) ($rate->country ?? ($raw['country'] ?? 'Saudi Arabia')));
            $visaType = trim((string) ($rate->visa_type ?? ($raw['visa_type'] ?? 'Umrah')));
            if ($country === '') $country = 'Saudi Arabia';
            if ($visaType === '') $visaType = 'Umrah';

            $currency = $this->normalizeCurrency((string) ($rate->cost_currency ?? ($raw['cost_currency'] ?? 'SAR')));
            $costRate = round((float) ($rate->cost_rate ?? ($raw['cost_rate'] ?? 0)), 4);
            $exchangeRate = $this->exchangeRateToPkr($currency);
            if ($exchangeRate === null || $exchangeRate <= 0) {
                throw ValidationException::withMessages(["visas.$index.cost_currency" => 'No active '.$currency.' to PKR exchange rate is available in Currency Rates.']);
            }
            $vendorCost = round($costRate * $exchangeRate, 2);
            $sale = round((float) ($raw['sale_pkr'] ?? ($rate->default_sale_pkr ?? 0)), 2);

            $normalizedRow = [
                'booking_id' => $booking,
                'booking_passenger_id' => $passengerId,
                'visa_rate_card_id' => $rateId,
                'country' => $country,
                'visa_type' => $visaType,
                'saudi_company_id' => $saudiId,
                'pakistani_iata_id' => $iataId,
                'vendor_id' => $vendorId,
                'application_reference' => trim((string) ($raw['application_reference'] ?? '')) ?: null,
                'visa_number' => trim((string) ($raw['visa_number'] ?? '')) ?: null,
                'status' => $this->normalizeStatus((string) ($raw['status'] ?? 'pending')),
                'issue_date' => $raw['issue_date'] ?? null,
                'expiry_date' => $raw['expiry_date'] ?? null,
                'sale_pkr' => $sale,
                'cost_currency' => $currency,
                'cost_rate' => $costRate,
                'exchange_rate' => round($exchangeRate, 8),
                'vendor_cost_pkr' => $vendorCost,
                'margin_pkr' => round($sale - $vendorCost, 2),
                'notes' => trim((string) ($raw['notes'] ?? '')) ?: null,
            ];
            foreach ([
                'saudi_master_table' => (string) ($saudi['source_table'] ?? $saudiMasterTable),
                'saudi_master_id' => $saudiId,
                'pakistani_iata_master_table' => (string) ($saudi['pakistani_iata_source_table'] ?? ''),
                'pakistani_iata_master_id' => $iataId,
                'saudi_company_name_snapshot' => $saudiName,
                'pakistani_iata_name_snapshot' => $iataName,
            ] as $column => $value) {
                if (Schema::hasColumn('booking_visa_services', $column)) {
                    $normalizedRow[$column] = $value ?: null;
                }
            }
            $normalized[] = $normalizedRow;
        }

        $vendorErrors = app(BookingCommercialCompletenessResolver::class)->visaVendorErrors($normalized);
        if ($vendorErrors) {
            throw ValidationException::withMessages(['visa_vendor' => $vendorErrors]);
        }

        DB::transaction(function () use ($booking, $normalized, $bookingRow): void {
            $keep = array_map(static fn (array $row): int => (int) $row['booking_passenger_id'], $normalized);
            $stale = DB::table('booking_visa_services')->where('booking_id', $booking);
            if ($keep) $stale->whereNotIn('booking_passenger_id', $keep);
            $stale->delete();

            foreach ($normalized as $row) {
                DB::table('booking_visa_services')->updateOrInsert(
                    ['booking_id' => $booking, 'booking_passenger_id' => $row['booking_passenger_id']],
                    $row + ['updated_at' => now(), 'created_at' => now()]
                );
            }

            $this->syncBookingServiceBestEffort($booking, $bookingRow, $this->summary($normalized));
        });

        $fresh = DB::table('booking_visa_services')->where('booking_id', $booking)->orderBy('id')->get()
            ->map(fn (object $row): array => $this->presentRow((array) $row, $passengers->values()->all()))->values()->all();

        return response()->json([
            'ok' => true,
            'message' => 'Visa Data saved.',
            'visa_rows' => $fresh,
            'summary' => $this->summary($fresh),
            'saudi_companies' => $this->saudiCompanies(),
            'pakistani_iatas' => $this->pakistaniIatas(),
            'rates' => $this->rateCards(),
        ]);
    }

    private function assertBooking(int $booking): object
    {
        abort_unless(Schema::hasTable('bookings'), 404);
        $row = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function assertSchema(): void
    {
        if (! Schema::hasTable('booking_visa_services') || ! Schema::hasTable('visa_rate_cards')) {
            throw ValidationException::withMessages(['visa' => 'Visa product schema is not installed. Open System Health & Updates and run Safe Database Upgrade.']);
        }
    }

    /** @return list<array<string,mixed>> */
    private function bookingPassengers(int $booking): array
    {
        foreach (['booking_passengers', 'booking_travellers', 'booking_travelers'] as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table);
            if (! in_array('booking_id', $columns, true) || ! in_array('id', $columns, true)) continue;
            $sort = $this->firstColumn($columns, ['passenger_index', 'passenger_no', 'sort_order', 'sequence', 'id']) ?? 'id';
            return DB::table($table)->where('booking_id', $booking)->orderBy($sort)->get()->map(function (object $row) use ($columns): array {
                $data = (array) $row;
                $name = $this->firstString($data, ['name', 'passenger_name', 'full_name']);
                if ($name === '') $name = trim(implode(' ', array_filter([$this->firstString($data, ['title']), $this->firstString($data, ['first_name', 'given_name']), $this->firstString($data, ['last_name', 'surname'])])));
                return [
                    'id' => (int) ($data['id'] ?? 0),
                    'name' => $name !== '' ? $name : 'Passenger '.(int) ($data['id'] ?? 0),
                    'passport_number' => $this->firstString($data, ['passport_number', 'passport_no', 'passport']),
                    'fare_type' => strtoupper($this->firstString($data, ['fare_as', 'fare_type', 'passenger_type', 'pax_type', 'age_type']) ?: 'ADULT'),
                    'status' => strtoupper($this->firstString($data, ['status']) ?: 'ACTIVE'),
                ];
            })->values()->all();
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function pakistaniIatas(): array
    {
        return array_values(array_filter($this->visaMasters->pakistaniIatas(), static fn (array $row): bool => (bool) ($row['is_active'] ?? true)));
    }

    /** @return list<array<string,mixed>> */
    private function saudiCompanies(): array
    {
        return array_values(array_filter($this->visaMasters->saudiCompanies(), static fn (array $row): bool => (bool) ($row['is_active'] ?? true)));
    }

    /** @return list<array<string,mixed>> */
    private function rateCards(): array
    {
        $today = now()->toDateString();
        return DB::table('visa_rate_cards')->where('is_active', true)
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today): void { $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today); })
            ->orderBy('country')->orderBy('visa_type')->orderByDesc('effective_from')->get()->map(function (object $row): ?array {
                $data = (array) $row;
                $saudiTable = trim((string) ($data['saudi_master_table'] ?? ''));
                $saudiId = (int) ($data['saudi_master_id'] ?? 0);
                $saudi = $saudiTable !== '' && $saudiId > 0
                    ? $this->visaMasters->findSaudiByKey($saudiTable.':'.$saudiId)
                    : null;
                if (! $saudi || ! (bool) ($saudi['is_active'] ?? true) || ! (bool) ($saudi['link_complete'] ?? false)) {
                    return null;
                }
                $currency = $this->normalizeCurrency((string) ($data['cost_currency'] ?? 'SAR'));
                $fx = $this->exchangeRateToPkr($currency);
                $costRate = round((float) ($data['cost_rate'] ?? 0), 4);
                $saudiName = trim((string) ($data['saudi_company_name_snapshot'] ?? '')) ?: trim((string) ($saudi['name'] ?? ''));
                $iataName = trim((string) ($data['pakistani_iata_name_snapshot'] ?? '')) ?: trim((string) ($saudi['pakistani_iata_name'] ?? ''));
                $vendor = collect($this->vendorOptions())->firstWhere('id', (int) ($saudi['vendor_id'] ?? 0));
                $vendorName = is_array($vendor) ? (string) ($vendor['name'] ?? '') : '';
                return [
                    'id' => (int) $data['id'], 'country' => (string) $data['country'], 'visa_type' => (string) $data['visa_type'],
                    'saudi_company_id' => (int) ($saudi['id'] ?? 0), 'saudi_company_name' => $saudiName,
                    'pakistani_iata_id' => (int) ($saudi['pakistani_iata_id'] ?? 0), 'pakistani_iata_name' => $iataName,
                    'vendor_id' => (int) ($saudi['vendor_id'] ?? 0), 'vendor_name' => $vendorName,
                    'cost_currency' => $currency, 'cost_rate' => $costRate, 'exchange_rate' => $fx !== null ? round($fx, 8) : null,
                    'vendor_cost_pkr' => $fx !== null ? round($costRate * $fx, 2) : null,
                    'default_sale_pkr' => round((float) ($data['default_sale_pkr'] ?? 0), 2),
                    'effective_from' => (string) $data['effective_from'], 'effective_to' => $data['effective_to'] ? (string) $data['effective_to'] : null,
                    'display' => trim((string) $data['country']).' · '.trim((string) $data['visa_type']).' · '.$saudiName.' · '.$currency.' '.number_format($costRate, 2),
                ];
            })->filter()->values()->all();
    }

    /** @return array<string,mixed> */
    private function presentRow(array $row, array $passengers): array
    {
        $pax = collect($passengers)->firstWhere('id', (int) ($row['booking_passenger_id'] ?? 0)) ?: [];
        $vendorRow = collect($this->vendorOptions())->firstWhere('id', (int) ($row['vendor_id'] ?? 0));
        $vendorName = is_array($vendorRow) ? (string) ($vendorRow['name'] ?? '') : '';
        $saudi = null;
        $saudiTable = trim((string) ($row['saudi_master_table'] ?? ''));
        $saudiId = (int) ($row['saudi_master_id'] ?? $row['saudi_company_id'] ?? 0);
        if ($saudiTable !== '' && $saudiId > 0) {
            $saudi = $this->visaMasters->findSaudiByKey($saudiTable.':'.$saudiId);
        } elseif ($saudiId > 0) {
            $matches = array_values(array_filter(
                $this->visaMasters->saudiCompanies(),
                static fn (array $candidate): bool => (int) ($candidate['id'] ?? 0) === $saudiId
            ));
            if (count($matches) === 1) $saudi = $matches[0];
        }
        return $row + [
            'passenger_name' => (string) ($pax['name'] ?? ''),
            'passport_number' => (string) ($pax['passport_number'] ?? ''),
            'saudi_company_name' => trim((string) ($row['saudi_company_name_snapshot'] ?? '')),
            'pakistani_iata_name' => trim((string) ($row['pakistani_iata_name_snapshot'] ?? '')),
            'vendor_name' => (string) $vendorName,
            'saudi_company_footer' => trim((string) ($saudi['voucher_footer'] ?? '')),
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,float> */
    private function summary(array $rows): array
    {
        $customer = 0.0; $vendor = 0.0;
        foreach ($rows as $row) { $customer += (float) ($row['sale_pkr'] ?? 0); $vendor += (float) ($row['vendor_cost_pkr'] ?? 0); }
        return ['customer_total' => round($customer, 2), 'vendor_total' => round($vendor, 2), 'margin' => round($customer - $vendor, 2)];
    }

    /** @return list<array{id:int,name:string}> */
    private function vendorOptions(): array
    {
        if ($this->vendorOptionsCache !== null) {
            return $this->vendorOptionsCache;
        }

        try {
            return $this->vendorOptionsCache = app(UnifiedGroupPackageDataSource::class)->vendors()->map(static fn (array $row): array => ['id' => (int) ($row['id'] ?? 0), 'name' => trim((string) ($row['name'] ?? ''))])
                ->filter(static fn (array $row): bool => $row['id'] > 0 && $row['name'] !== '')->unique('id')->sortBy('name')->values()->all();
        } catch (Throwable) {
            return $this->vendorOptionsCache = [];
        }
    }

    /** @return list<string> */
    private function statusOptions(): array
    {
        return ['pending', 'submitted', 'approved', 'issued', 'rejected', 'cancelled'];
    }

    private function normalizeStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $this->statusOptions(), true) ? $value : 'pending';
    }

    private function normalizeCurrency(string $value): string
    {
        $value = strtoupper(trim($value));
        return in_array($value, ['SR', 'RIYAL', 'RIYALS', 'SAUDI RIYAL', 'SAUDI RIYALS'], true) ? 'SAR' : ($value ?: 'SAR');
    }

    private function exchangeRateToPkr(string $sourceCurrency): ?float
    {
        $sourceCurrency = $this->normalizeCurrency($sourceCurrency);
        if ($sourceCurrency === 'PKR') return 1.0;
        if (array_key_exists($sourceCurrency, $this->exchangeRateToPkrCache)) return $this->exchangeRateToPkrCache[$sourceCurrency];

        $tables = ['currency_rates', 'currency_exchange_rates', 'exchange_rates', 'fx_rates', 'foreign_exchange_rates'];
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                $low = strtolower($table);
                if ($table !== '' && str_contains($low, 'rate') && (str_contains($low, 'currency') || str_contains($low, 'exchange') || str_contains($low, 'fx'))) $tables[] = $table;
            }
        } catch (Throwable) {}

        $codes = $sourceCurrency === 'SAR' ? ['SAR', 'SR'] : [$sourceCurrency];
        foreach (array_values(array_unique($tables)) as $table) {
            if (! preg_match('/^[A-Za-z0-9_]+$/', $table) || ! Schema::hasTable($table)) continue;
            try {
                $columns = Schema::getColumnListing($table);
                $rate = $this->firstColumn($columns, ['exchange_rate','conversion_rate','rate','rate_value','value','selling_rate','sell_rate','buying_rate','buy_rate']);
                if (! $rate) continue;
                $date = $this->firstColumn($columns, ['effective_date','rate_date','date','valid_from','as_of_date','created_at','updated_at']);
                $id = $this->firstColumn($columns, ['id']);
                $from = $this->firstColumn($columns, ['from_currency_code','source_currency_code','base_currency_code','currency_from_code','from_currency']);
                $to = $this->firstColumn($columns, ['to_currency_code','target_currency_code','quote_currency_code','currency_to_code','to_currency']);
                if ($from && $to) {
                    foreach ($codes as $code) {
                        $direct = $this->latestRate($table, $rate, $date, $id, [[$from, $code], [$to, 'PKR']]);
                        if ($direct !== null && $direct > 0) return $this->exchangeRateToPkrCache[$sourceCurrency] = $direct;
                        $inverse = $this->latestRate($table, $rate, $date, $id, [[$from, 'PKR'], [$to, $code]]);
                        if ($inverse !== null && $inverse > 0) return $this->exchangeRateToPkrCache[$sourceCurrency] = 1 / $inverse;
                    }
                }
                $currencyCode = $this->firstColumn($columns, ['currency_code','code','iso_code','foreign_currency_code']);
                if ($currencyCode) {
                    foreach ($codes as $code) {
                        $direct = $this->latestRate($table, $rate, $date, $id, [[$currencyCode, $code]]);
                        if ($direct !== null && $direct > 0) return $this->exchangeRateToPkrCache[$sourceCurrency] = $direct;
                    }
                }
            } catch (Throwable) { continue; }
        }
        return $this->exchangeRateToPkrCache[$sourceCurrency] = null;
    }

    /** @param list<array{0:string,1:mixed}> $where */
    private function latestRate(string $table, string $rateColumn, ?string $dateColumn, ?string $idColumn, array $where): ?float
    {
        try {
            $q = DB::table($table); foreach ($where as [$column, $value]) $q->where($column, $value);
            if ($dateColumn) $q->orderByDesc($dateColumn); if ($idColumn && $idColumn !== $dateColumn) $q->orderByDesc($idColumn);
            $value = $q->value($rateColumn); return ($value !== null && $value !== '' && is_numeric($value)) ? (float) $value : null;
        } catch (Throwable) { return null; }
    }

    /** @param list<string> $columns */
    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate;
        return null;
    }

    private function firstString(array $row, array $fields): string
    {
        foreach ($fields as $field) { $value = trim((string) ($row[$field] ?? '')); if ($value !== '') return $value; }
        return '';
    }

    private function syncBookingServiceBestEffort(int $booking, object $bookingRow, array $summary): void
    {
        if (! Schema::hasTable('booking_services')) return;
        try {
            $columns = Schema::getColumnListing('booking_services');
            if (! in_array('booking_id', $columns, true)) return;
            $rows = DB::table('booking_services')->where('booking_id', $booking)->get();
            $visa = null;
            foreach ($rows as $row) {
                $data = (array) $row;
                $hay = strtolower(implode(' ', array_map('strval', array_intersect_key($data, array_flip(['service_name','name','title','description','service_type','product_type'])))));
                if (str_contains($hay, 'visa')) { $visa = $data; break; }
            }
            if (! $visa || empty($visa['id'])) return;
            $update = [];
            foreach (['selling_total','customer_total','sale_total','total_sale','customer_amount','selling_amount'] as $field) if (in_array($field, $columns, true)) $update[$field] = $summary['customer_total'];
            foreach (['net_supplier_cost','supplier_total','vendor_total','cost_total','total_cost','supplier_amount','vendor_amount'] as $field) if (in_array($field, $columns, true)) $update[$field] = $summary['vendor_total'];
            foreach (['margin','gross_margin','net_margin','profit'] as $field) if (in_array($field, $columns, true)) $update[$field] = $summary['margin'];
            if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
            if ($update) DB::table('booking_services')->where('id', (int) $visa['id'])->update($update);
        } catch (Throwable) {
            // Visa child rows remain authoritative even if an old booking_services schema cannot accept totals.
        }
    }
}
