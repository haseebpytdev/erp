<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TravelMasterFlightRoutesController extends Controller
{
    public function __construct(private readonly NativeErpLayoutResolver $layout) {}

    public function index()
    {
        $table = 'booking_itinerary_segments';
        if (! Schema::hasTable($table)) return view('operations.travel-masters.flight-routes', ['routes' => [], 'available' => false, 'layoutMeta' => $this->layout->resolve()]);
        $columns = Schema::getColumnListing($table);
        $from = $this->first($columns, ['from_code', 'origin_code', 'from', 'origin']);
        $to = $this->first($columns, ['to_code', 'destination_code', 'to', 'destination']);
        if (! $from || ! $to) return view('operations.travel-masters.flight-routes', ['routes' => [], 'available' => false, 'layoutMeta' => $this->layout->resolve()]);
        $airline = $this->first($columns, ['airline_code', 'carrier_code', 'airline_name', 'airline', 'carrier_name']);
        $flight = $this->first($columns, ['flight_number', 'flight_no']);
        $routes = [];
        foreach (DB::table($table)->select(array_values(array_filter([$from, $to, $airline, $flight])))->get() as $row) {
            $fromValue = strtoupper(trim((string) ($row->{$from} ?? ''))); $toValue = strtoupper(trim((string) ($row->{$to} ?? '')));
            if ($fromValue === '' || $toValue === '') continue;
            $key = $fromValue.'-'.$toValue; $routes[$key] ??= ['route'=>$key,'from'=>$fromValue,'to'=>$toValue,'airlines'=>[],'flight_numbers'=>[],'used'=>0]; $routes[$key]['used']++;
            $airlineValue = trim((string) ($airline ? ($row->{$airline} ?? '') : '')); $flightValue = trim((string) ($flight ? ($row->{$flight} ?? '') : ''));
            if ($airlineValue !== '') $routes[$key]['airlines'][$airlineValue] = true; if ($flightValue !== '') $routes[$key]['flight_numbers'][$flightValue] = true;
        }
        $routes = array_values(array_map(function (array $r): array { $r['airlines']=implode(', ',array_keys($r['airlines'])); $r['flight_numbers']=implode(', ',array_keys($r['flight_numbers'])); return $r; }, $routes));
        usort($routes, fn (array $a,array $b): int => $b['used'] <=> $a['used'] ?: strcmp($a['route'],$b['route']));
        return view('operations.travel-masters.flight-routes', ['routes'=>$routes, 'available'=>true, 'layoutMeta' => $this->layout->resolve()]);
    }
    private function first(array $columns,array $candidates): ?string { foreach($candidates as $candidate) if(in_array($candidate,$columns,true)) return $candidate; return null; }
}
