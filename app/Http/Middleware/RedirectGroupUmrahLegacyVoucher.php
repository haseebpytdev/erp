<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RedirectGroupUmrahLegacyVoucher
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');

        if (! preg_match('#^operations/bookings/(\d+)/(?:travel-voucher|voucher)$#i', $path, $match)) {
            return $next($request);
        }

        $bookingId = (int) $match[1];

        try {
            if (
                Schema::hasTable('booking_group_package_unified')
                && DB::table('booking_group_package_unified')->where('booking_id', $bookingId)->exists()
            ) {
                return redirect()->route(
                    'operations.bookings.group-umrah-voucher.show',
                    ['booking' => $bookingId]
                );
            }
        } catch (\Throwable) {
            // Fall through to the native legacy voucher for non-Group-Umrah.
        }

        return $next($request);
    }
}
