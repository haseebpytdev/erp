<?php

namespace App\Services\Operations;

use App\Services\Administration\ErpBranchAccessAuthority;
use App\Services\Administration\ErpPermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only authorization foundation; it does not execute removal. */
final class AirServiceRemovalAuthorization
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions,
        private readonly ErpBranchAccessAuthority $branches,
        private readonly NativeProductServiceResolver $products,
    ) {
    }

    /** @return array{authorized:bool,code:string,message:string,booking_id:int,booking_service_id:int} */
    public function authorize(mixed $user, int $bookingId, int $serviceId): array
    {
        $base = ['authorized' => false, 'code' => 'DENIED', 'message' => 'Air removal is not authorized.', 'booking_id' => $bookingId, 'booking_service_id' => $serviceId];
        if (! $user || $bookingId <= 0 || $serviceId <= 0) return $this->deny($base, 'INVALID_CONTEXT');

        try {
            // Service retirement uses the existing service-management
            // capability. Full-booking cancellation is deliberately not
            // required: bookings.cancel governs cancellation of the booking
            // lifecycle, not removal of one product service. No new
            // permission is introduced; the absence/ambiguity fails closed.
            if (! $this->permissions->hasExactPermission($user, ['booking_services.manage'])) {
                return $this->deny($base, 'PERMISSION_REQUIRED');
            }
            if (! Schema::hasTable('bookings') || ! Schema::hasTable('booking_services')) return $this->deny($base, 'UNKNOWN_SCOPE');
            foreach (['id', 'company_id', 'branch_id'] as $column) {
                if (! Schema::hasColumn('bookings', $column)) return $this->deny($base, 'UNKNOWN_SCOPE');
            }
            if (! Schema::hasColumn('booking_services', 'product_service_id')) return $this->deny($base, 'UNKNOWN_SERVICE');
            $booking = DB::table('bookings')->where('id', $bookingId)->first(['id', 'company_id', 'branch_id']);
            $service = DB::table('booking_services')->where('id', $serviceId)->where('booking_id', $bookingId)->first(['id', 'booking_id', 'product_service_id']);
            if (! $booking || ! $service) return $this->deny($base, 'BOOKING_SERVICE_MISMATCH');
            $air = $this->products->findAir();
            if (! $air || (int) ($air['id'] ?? 0) !== (int) $service->product_service_id) return $this->deny($base, 'NOT_AIR_SERVICE');
            if ((int) $this->branches->companyIdForUser($user) !== (int) $booking->company_id
                || ! $this->branches->mayAccessBranch($user, (int) $booking->branch_id)) {
                return $this->deny($base, 'OUTSIDE_AUTHORIZED_SCOPE');
            }
            return ['authorized' => true, 'code' => 'AUTHORIZED', 'message' => 'Air removal authorization passed.', 'booking_id' => $bookingId, 'booking_service_id' => $serviceId];
        } catch (\Throwable) {
            return $this->deny($base, 'UNKNOWN_AUTHORITY');
        }
    }

    private function deny(array $base, string $code): array
    { $base['code'] = $code; return $base; }
}
