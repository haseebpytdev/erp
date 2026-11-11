<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only adapter from unified package-owned operational rows to native UI/report shapes. */
final class CompletePackageOperationalResolver
{
    public function snapshot(int $bookingId): array
    {
        $empty = ['air'=>['itinerary'=>[],'tickets'=>[],'common'=>[]], 'hotel'=>['stays'=>[]], 'transport'=>['transports'=>[]], 'visa'=>['visa_rows'=>[]], 'other'=>[]];
        if ($bookingId <= 0 || ! Schema::hasTable('booking_group_package_unified') || ! DB::table('booking_group_package_unified')->where('booking_id',$bookingId)->exists()) return $empty;
        $components = Schema::hasTable('booking_group_package_components')
            ? DB::table('booking_group_package_components')->where('booking_id',$bookingId)->where('selected',1)->where('billing_treatment','included_non_billable')->get()
            : collect();
        $componentIds = static function (string $column) use ($components): array {
            return $components->pluck($column)->filter()->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        };
        $out = $empty;
        if (Schema::hasTable('booking_group_package_flights')) {
            $query = DB::table('booking_group_package_flights')->where('booking_id',$bookingId);
            $ids = $componentIds('group_package_flight_id');
            if ($ids) $query->whereIn('id', $ids);
            $out['air']['itinerary'] = $query->orderBy('sort_order')->orderBy('id')->get()->map(static function ($r): array {
                $row = (array) $r;
                $row['from'] = $row['from'] ?? $row['from_code'] ?? null;
                $row['to'] = $row['to'] ?? $row['to_code'] ?? null;
                $row['airline'] = $row['airline'] ?? $row['airline_name'] ?? null;
                $row['departure_at'] = $row['departure_at'] ?? trim((string) (($row['departure_date'] ?? '').' '.($row['departure_time'] ?? '')));
                $row['arrival_at'] = $row['arrival_at'] ?? trim((string) (($row['arrival_date'] ?? '').' '.($row['arrival_time'] ?? '')));
                return $row;
            })->all();
            $pnr = trim((string) ($out['air']['itinerary'][0]['pnr'] ?? ''));
            if ($pnr !== '') $out['air']['common'] = ['pnr' => $pnr, 'airline_pnr' => $pnr];
        }
        if (Schema::hasTable('booking_group_package_passengers')) {
            $out['air']['tickets'] = DB::table('booking_group_package_passengers')->where('booking_id',$bookingId)->orderBy('sort_order')->orderBy('id')->get()->map(static fn($r): array => [
                'booking_passenger_id' => $r->passenger_id,
                'ticket_number' => $r->ticket_number,
                'ticket_status' => trim((string) ($r->ticket_number ?? '')) !== '' ? 'ISSUED' : 'PENDING',
                'pnr' => $out['air']['common']['pnr'] ?? null,
            ])->all();
        }
        if (Schema::hasTable('booking_group_package_hotels')) {
            $query = DB::table('booking_group_package_hotels')->where('booking_id',$bookingId);
            $ids = $componentIds('group_package_hotel_id');
            if ($ids) $query->whereIn('id', $ids);
            $out['hotel']['stays'] = $query->orderBy('sort_order')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
        }
        if (Schema::hasTable('booking_group_package_transports')) {
            $query = DB::table('booking_group_package_transports')->where('booking_id',$bookingId);
            $ids = $componentIds('group_package_transport_id');
            if ($ids) $query->whereIn('id', $ids);
            $out['transport']['transports'] = $query->orderBy('sort_order')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
        }
        if (Schema::hasTable('booking_group_package_services')) {
            $query = DB::table('booking_group_package_services')->where('booking_id',$bookingId);
            $ids = $componentIds('group_package_service_id');
            if ($ids) $query->whereIn('id', $ids);
            $out['other'] = $query->orderBy('sort_order')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
        }
        if (Schema::hasTable('booking_visa_services')) {
            $visaIds = $components->pluck('booking_visa_service_id')->filter()->map(fn($id)=>(int)$id)->all();
            if ($visaIds) $out['visa']['visa_rows'] = DB::table('booking_visa_services')->whereIn('id',$visaIds)->get()->map(fn($r)=>(array)$r)->all();
        }
        return $out;
    }
}
