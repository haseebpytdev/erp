<?php

namespace App\Http\Middleware;

use App\Services\Administration\ErpBranchAccessAuthority;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EnforceErpBranchAccess
{
    public function __construct(private readonly ErpBranchAccessAuthority $authority)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) return $next($request);

        $requested = $request->query('branch_id', $request->query('branch'));
        if ($requested !== null && trim((string) $requested) !== '') {
            $this->authority->branchFilter($user, $requested);
        } elseif ($request->isMethod('POST') && $this->isBookingFamily($request)) {
            $scope=$this->authority->effectiveScope($user);
            $allowed=$this->authority->allowedBranchIds($user);
            if ($scope===ErpBranchAccessAuthority::PRIMARY_BRANCH && ($primary=$this->authority->primaryBranchId($user))) {
                $request->merge(['branch_id'=>$primary]);
            } elseif (is_array($allowed) && count($allowed)===1) {
                $request->merge(['branch_id'=>(int)$allowed[0]]);
            } elseif (is_array($allowed) && count($allowed)>1 && $this->isBookingCreation($request)) {
                abort(403,'Select an authorized branch before creating a booking.');
            }
        }

        $booking = $request->route('booking');
        $bookingId = $this->routeIdentityId($booking);
        if ($booking !== null && $bookingId === null) {
            abort(404);
        }
        if ($bookingId !== null) {
            $row = DB::table('bookings')->where('id', $bookingId)->first();
            abort_unless($row, 404);
            if (! $this->authority->mayAccessBranch($user, (int) ($row->branch_id ?? 0))) abort(404);
            $this->assertChildBindings($request, $bookingId);
        } elseif ($request->route('passenger') !== null || $request->route('batch') !== null) {
            // A child binding without a resolvable parent is ambiguous and must fail closed.
            abort(404);
        }

        return $next($request);
    }

    private function assertChildBindings(Request $request, int $bookingId): void
    {
        $passengerBinding = $request->route('passenger');
        $passenger = $this->routeIdentityId($passengerBinding);
        if ($passengerBinding !== null && $passenger === null) {
            abort(404);
        }
        if ($passenger !== null) {
            if (is_object($passengerBinding)) {
                $passengerBookingId = $this->routeIdentityId($passengerBinding->booking_id ?? null);
                abort_unless($passengerBookingId !== null && $passengerBookingId === $bookingId, 404);
            }
            $exists = false;
            foreach (['booking_passengers', 'booking_passenger', 'passengers'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'booking_id')) {
                    $exists = DB::table($table)->where('id', $passenger)->where('booking_id', $bookingId)->exists();
                    break;
                }
            }
            abort_unless($exists, 404);
        }

        $batchBinding = $request->route('batch');
        $batch = $this->routeIdentityId($batchBinding);
        if ($batchBinding !== null && $batch === null) {
            abort(404);
        }
        if ($batch !== null && Schema::hasTable('general_booking_billing_batches')) {
            abort_unless(DB::table('general_booking_billing_batches')->where('id', $batch)->where('booking_id', $bookingId)->exists(), 404);
        }

        foreach (['service_id','booking_service_id','air_service_id','hotel_service_id','transport_service_id','visa_service_id','detail_id','air_detail_id','hotel_detail_id','transport_detail_id','visa_detail_id'] as $field) {
            $id=(int)$request->input($field,0);
            if ($id<=0) continue;
            foreach (['booking_services','booking_visa_services','booking_transport_segments','booking_hotel_stays','booking_hotels','booking_itinerary_segments','air_ticket_details'] as $table) {
                if (!Schema::hasTable($table) || !Schema::hasColumn($table,'booking_id')) continue;
                if (!DB::table($table)->where('id',$id)->exists()) continue;
                abort_unless(DB::table($table)->where('id',$id)->where('booking_id',$bookingId)->exists(),404);
                break;
            }
        }
    }

    /**
     * Resolve a route binding without coercing an Eloquent model to an integer.
     * Only scalar positive decimal IDs and model primary keys are accepted.
     */
    private function routeIdentityId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_object($value)) {
            if (method_exists($value, 'getKey')) {
                $value = $value->getKey();
            } elseif (property_exists($value, 'id')) {
                $value = $value->id;
            } else {
                return null;
            }
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $id = (int) $value;
            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function isBookingFamily(Request $request): bool
    {
        $uri=trim($request->path(),'/');
        return str_starts_with($uri,'operations/bookings') || str_starts_with($uri,'system/erp-bookings') || str_starts_with($uri,'operations/group-package-bookings');
    }

    private function isBookingCreation(Request $request): bool
    {
        $uri=trim($request->path(),'/');
        return preg_match('#(?:^|/)bookings(?:/create)?$#',$uri)===1 || str_ends_with($uri,'/bookings');
    }
}
