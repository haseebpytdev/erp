<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-10.30.10
 *
 * Prevents a unified Group Umrah booking from reopening in the historical
 * multi-step Booking Workspace, whose cards read legacy tables and can show
 * incomplete/stale information.
 *
 * Only the exact booking workspace URL is redirected. Voucher, accounting,
 * invoice and other nested booking routes remain untouched.
 */
class RedirectGroupUmrahLegacyWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->method() !== 'GET') {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        if (! preg_match('#^operations/bookings/(\\d+)$#', $path, $match)) {
            return $next($request);
        }

        $bookingId = (int) $match[1];

        try {
            if (
                $bookingId > 0
                && Schema::hasTable('booking_group_package_unified')
                && DB::table('booking_group_package_unified')->where('booking_id', $bookingId)->exists()
            ) {
                return redirect()->route(
                    'operations.bookings.group-package-unified.edit',
                    ['booking' => $bookingId]
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $next($request);
    }
}
