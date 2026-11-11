<?php

namespace App\Http\Middleware;

use App\Services\Operations\BookingEditLockResolver;
use App\Services\Operations\BookingBillingEditLockResolver;
use App\Services\Operations\NativeSalesInvoiceInspector;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceGeneralBookingEditLock
{
    public function __construct(private readonly BookingEditLockResolver $locks, private readonly BookingBillingEditLockResolver $billingLocks, private readonly NativeSalesInvoiceInspector $invoices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $reviewAction = strtolower((string) $request->route('action'));
        if (in_array($reviewAction, ['submit','approve','reopen','ready','hotel-confirmation','notes'], true)) return $next($request);
        $routeBooking = $request->route('booking') ?? $request->route('id') ?? 0;
        $bookingId = $this->bookingId($routeBooking);
        $billing = $this->billingLocks->resolve($bookingId);
        if ($billing['locked'] ?? false) {
            $message = (string) ($billing['reason'] ?? 'This booking is protected by its billing state.');
            if ($request->expectsJson() || $request->ajax()) {
                return new JsonResponse(['message' => $message, 'error' => 'booking_billing_locked', 'billing_state' => $billing['code'] ?? 'locked'], 423);
            }
            return redirect()->route('bookings.review.show', ['booking' => $bookingId])->withErrors(['booking' => $message]);
        }
        // Ticket number/status issuance is an operational action, not a
        // commercial booking edit.  Keep billing locks above, while allowing
        // the existing controller to enforce permission, scope, ownership,
        // uniqueness, status validation and audit logging.
        if (
            $request->route()?->getName() === 'operations.bookings.air-tickets.bulk-numbers'
            && $this->isOperationalTicketPayload($request)
        ) {
            return $next($request);
        }
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

        return redirect()
            ->route('bookings.review.show', ['booking' => $bookingId])
            ->withHeaders(['X-Booking-Lock' => 'locked'])
            ->withErrors(['booking' => $message]);
    }

    private function bookingId(mixed $routeBooking): int
    {
        if (is_object($routeBooking) && method_exists($routeBooking, 'getKey')) {
            return (int) $routeBooking->getKey();
        }

        return (int) $routeBooking;
    }

    private function isOperationalTicketPayload(Request $request): bool
    {
        $keys = array_diff(array_keys($request->all()), ['_token', '_method', 'ticket_number', 'ticket_status']);
        return $keys === [] && ($request->has('ticket_number') || $request->has('ticket_status'));
    }
}
