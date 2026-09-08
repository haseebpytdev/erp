<?php

namespace App\Http\Middleware;

use App\Services\Operations\BookingEditLockResolver;
use App\Services\Operations\NativeSalesInvoiceInspector;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceGeneralBookingEditLock
{
    public function __construct(private readonly BookingEditLockResolver $locks, private readonly NativeSalesInvoiceInspector $invoices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $reviewAction = strtolower((string) $request->route('action'));
        if (in_array($reviewAction, ['submit','approve','reopen','ready'], true)) return $next($request);
        $bookingId = (int) ($request->route('booking') ?? $request->route('id') ?? 0);
        $state = $this->locks->resolve($bookingId);
        if (! $state['locked']) {
            $keys=implode(' ',array_keys($request->all()));
            $commercialPayload=preg_match('/(?:sale|cost|fare|price|amount|total|discount|commission|tax|exchange|vendor|supplier)/i',$keys)===1;
            if(!$commercialPayload||!($this->invoices->summary($bookingId)['has_posted']??false)) return $next($request);
            $message='Commercial changes are blocked because this booking has a Posted Sales Invoice. Use the controlled accounting correction policy first.';
            if($request->expectsJson()||$request->ajax()) return new JsonResponse(['message'=>$message,'error'=>'posted_invoice_commercial_lock'],423);
            return redirect()->route('bookings.review.show',['booking'=>$bookingId])->withErrors(['booking'=>$message]);
        }

        $message = 'Booking is locked after approval. Reopen the booking before making changes.';
        if ($request->expectsJson() || $request->ajax()) {
            return new JsonResponse([
                'message'=>$message,
                'error'=>'booking_locked',
                'booking_status'=>$state['status'],
            ], 423);
        }

        return new RedirectResponse(
            route('bookings.review.show', ['booking'=>$bookingId]),
            302,
            ['X-Booking-Lock'=>'locked']
        )->withErrors(['booking'=>$message]);
    }
}
