<?php

namespace App\Services\Sales;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Resolves the current native service scope for a base booking invoice. */
final class BaseBookingInvoiceScopeResolver
{
    public function resolve(Booking $booking): array
    {
        $booking->loadMissing(['services.product', 'services.passengers']);
        $ownedBySupplement = $this->supplementaryServiceIds((int) $booking->id);
        $services = $booking->services
            ->filter(fn ($service): bool => strtoupper((string) $service->status) !== 'CANCELLED')
            ->reject(fn ($service): bool => $ownedBySupplement->contains((int) $service->id))
            ->values();

        return [
            'booking_id' => (int) $booking->id,
            'expected_service_ids' => $services->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'expected_services' => $services,
            'expected_total' => round((float) $services->sum(fn ($service): float => (float) $service->line_total), 2),
            'supplementary_service_ids' => $ownedBySupplement->values()->all(),
        ];
    }

    private function supplementaryServiceIds(int $bookingId): \Illuminate\Support\Collection
    {
        if ($bookingId <= 0
            || ! Schema::hasTable('general_booking_billing_batch_items')
            || ! Schema::hasTable('general_booking_billing_batches')) {
            return collect();
        }

        return DB::table('general_booking_billing_batch_items as i')
            ->join('general_booking_billing_batches as b', 'b.id', '=', 'i.batch_id')
            ->where('i.booking_id', $bookingId)
            ->where('b.booking_id', $bookingId)
            ->where('b.batch_type', 'supplementary')
            ->where('i.batch_id', '>', 0)
            ->pluck('i.booking_service_id')
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }
}
