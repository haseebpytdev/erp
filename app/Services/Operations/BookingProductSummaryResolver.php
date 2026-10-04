<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Lightweight, read-only product summary authority for the booking hub. */
final class BookingProductSummaryResolver
{
    public function resolve(int $booking): array
    {
        return [
            'air' => $this->summary($booking, 'air'),
            'hotel' => $this->summary($booking, 'hotel'),
            'transport' => $this->summary($booking, 'transport'),
            'visa' => $this->summary($booking, 'visa'),
        ];
    }

    private function summary(int $booking, string $product): array
    {
        try {
            $master = app(NativeProductServiceResolver::class)->{'find'.ucfirst($product)}();
            $serviceIds = Schema::hasTable('booking_services') && $master
                ? DB::table('booking_services')->where('booking_id', $booking)->where('product_service_id', (int) $master['id'])
                    ->where(function ($q): void { $q->whereNull('deleted_at')->orWhere('deleted_at', ''); })
                    ->pluck('id')->map(static fn ($id): int => (int) $id)->all()
                : [];
            $table = match ($product) {
                'air' => 'air_ticket_details',
                'hotel' => app(GeneralBookingHotelNativeStoreResolver::class)->resolve()['table'] ?? null,
                'transport' => $this->transportTable(),
                'visa' => 'booking_visa_services',
            };
            if (! $table || ! Schema::hasTable($table)) return $this->empty();
            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);
            if (in_array('booking_id', $columns, true)) $query->where('booking_id', $booking);
            elseif (in_array('booking_service_id', $columns, true)) $query->whereIn('booking_service_id', $serviceIds ?: [-1]);
            $rows = $query->get();
            $sale = $this->sum($rows, ['customer_total','selling_total','sale_total','total_sale','sale_amount']);
            $cost = $this->sum($rows, ['supplier_total','vendor_total','cost_total','total_cost','cost_amount']);
            return ['count' => $rows->count(), 'customer_total' => round($sale, 2), 'supplier_total' => round($cost, 2), 'margin' => round($sale - $cost, 2)];
        } catch (Throwable) { return $this->empty(); }
    }

    private function transportTable(): ?string
    {
        foreach (Schema::getTables() as $meta) {
            $name = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
            if ($name !== '' && str_starts_with(strtolower($name), 'booking_') && str_contains(strtolower($name), 'transport')) return $name;
        }
        return null;
    }

    private function sum(iterable $rows, array $fields): float
    {
        $total = 0.0;
        foreach ($rows as $row) foreach ($fields as $field) if (isset($row->{$field}) && is_numeric($row->{$field})) { $total += (float) $row->{$field}; break; }
        return $total;
    }

    private function empty(): array { return ['count' => 0, 'customer_total' => 0.0, 'supplier_total' => 0.0, 'margin' => 0.0]; }
}
