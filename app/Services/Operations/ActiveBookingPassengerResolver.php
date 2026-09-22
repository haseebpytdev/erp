<?php

namespace App\Services\Operations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ActiveBookingPassengerResolver
{
    /** @return Collection<int,object> */
    public function rows(int $bookingId, ?string &$table = null, ?array &$columns = null): Collection
    {
        foreach (['booking_passengers', 'booking_travellers', 'booking_travelers'] as $candidate) {
            if (! Schema::hasTable($candidate)) continue;
            $installed = Schema::getColumnListing($candidate);
            if (! in_array('booking_id', $installed, true) || ! in_array('id', $installed, true)) continue;
            $table = $candidate;
            $columns = $installed;
            $query = DB::table($candidate)->where('booking_id', $bookingId);
            if (in_array('deleted_at', $installed, true)) $query->whereNull('deleted_at');
            if (in_array('is_active', $installed, true)) $query->where('is_active', true);
            if (in_array('active', $installed, true)) $query->where('active', true);
            return $query->get()->filter(function (object $row): bool {
                return ! in_array(strtolower(trim((string) (($row->status ?? null) ?: ''))), ['inactive', 'deleted', 'removed', 'cancelled', 'canceled'], true);
            })->values();
        }
        $table = null;
        $columns = [];
        return collect();
    }

    /** @return list<int> */
    public function ids(int $bookingId): array
    {
        return $this->rows($bookingId)->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
    }
}
