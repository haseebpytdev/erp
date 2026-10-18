<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Reports\TravelReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Responsive presentation over the existing, permission-scoped movement authority. */
final class MobileMovementController extends Controller
{
    private const MOVEMENTS = [
        'arrival' => 'Arrivals',
        'makkah-checkin' => 'Makkah Check-ins',
        'makkah-checkout' => 'Makkah Check-outs',
        'madinah-checkin' => 'Madinah Check-ins',
        'madinah-checkout' => 'Madinah Check-outs',
        'departure' => 'Departures',
    ];

    public function __construct(
        private readonly TravelReportService $reports,
        private readonly NativeErpLayoutResolver $layout,
    ) {
    }

    public function index(Request $request)
    {
        $today = CarbonImmutable::today();
        $tomorrow = $today->addDay();
        $threeDays = $today->addDays(3);
        $todayCounts = [];
        $upcoming = [];

        foreach (array_keys(self::MOVEMENTS) as $movement) {
            $todayCounts[$movement] = $this->rows($request, $movement, $today, $today)->total();
            foreach ($this->rows($request, $movement, $tomorrow, $threeDays) as $row) {
                $upcoming[] = $this->card($movement, $row);
            }
        }

        usort($upcoming, static fn (array $a, array $b): int => strcmp(
            ($a['date'] ?? '9999-99-99').' '.($a['time'] ?? '99:99'),
            ($b['date'] ?? '9999-99-99').' '.($b['time'] ?? '99:99'),
        ));

        return view('mobile.movements.index', [
            'layoutMeta' => $this->layout->resolve(),
            'movements' => self::MOVEMENTS,
            'todayCounts' => $todayCounts,
            'tomorrowCount' => count(array_filter($upcoming, fn (array $row): bool => ($row['date'] ?? '') === $tomorrow->toDateString())),
            'nextThreeDaysCount' => count($upcoming),
            'upcoming' => array_slice($upcoming, 0, 20),
        ]);
    }

    public function report(Request $request, string $movement)
    {
        abort_unless(isset(self::MOVEMENTS[$movement]), 404);
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $filters = $this->filters($request, $movement);
        $rows = $this->rows($request, $movement, $from, $to);

        return view('mobile.movements.report', [
            'layoutMeta' => $this->layout->resolve(),
            'movement' => $movement,
            'label' => self::MOVEMENTS[$movement],
            'definition' => $this->reports->definition($movement),
            'rows' => $rows,
            'filters' => $filters,
            'airportOptions' => $this->reports->movementAirportOptions($movement),
        ]);
    }

    private function rows(Request $request, string $movement, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null)
    {
        $query = $request->query();
        if ($from) $query['from'] = $from->toDateString();
        if ($to) $query['to'] = $to->toDateString();
        return $this->reports->rows($movement, $this->filtersFromArray($query, $movement), 100);
    }

    private function filters(Request $request, string $movement): array
    {
        return $this->filtersFromArray($request->query(), $movement);
    }

    private function filtersFromArray(array $input, string $movement): array
    {
        $allowed = ['from', 'to'];
        if (in_array($movement, ['arrival', 'departure'], true)) $allowed[] = $movement === 'arrival' ? 'destination' : 'origin';
        else $allowed[] = 'hotel';
        $filters = [];
        foreach ($allowed as $key) {
            if (isset($input[$key]) && trim((string) $input[$key]) !== '') $filters[$key] = trim((string) $input[$key]);
        }
        return $this->reports->authorizedFilters($filters, request()->user());
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
    }

    private function card(string $movement, array $row): array
    {
        $dateKey = in_array($movement, ['arrival', 'departure'], true)
            ? ($movement === 'arrival' ? 'arrival_date' : 'departure_date')
            : (str_contains($movement, 'checkin') ? 'check_in' : 'check_out');
        $timeKey = $movement === 'arrival' ? 'arrival_time' : ($movement === 'departure' ? 'departure_time' : null);
        return [
            'movement' => self::MOVEMENTS[$movement],
            'key' => $movement,
            'date' => (string) ($row[$dateKey] ?? ''),
            'time' => $timeKey ? (string) ($row[$timeKey] ?? '') : '',
            'booking_no' => (string) ($row['booking_no'] ?? '—'),
            'customer' => (string) ($row['customer'] ?? '—'),
            'pax' => (string) ($row['total_pax'] ?? '—'),
            'flight' => (string) ($row['flight'] ?? ''),
            'sector' => (string) ($row['sector'] ?? ''),
            'hotel' => (string) ($row['makkah_hotel'] ?? $row['madinah_hotel'] ?? ''),
            'check_in' => (string) ($row['check_in'] ?? ''),
            'check_out' => (string) ($row['check_out'] ?? ''),
            'nights' => (string) ($row['nights'] ?? ''),
            'action' => (string) ($row['action'] ?? ''),
        ];
    }
}
