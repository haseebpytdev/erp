<?php

namespace App\Http\Middleware;

use App\Services\Operations\BookingEditLockResolver;
use App\Services\Operations\GroupUmrahEditAuthority;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final class GuardApprovedGeneralBookingCommercials
{
    public function __construct(private readonly BookingEditLockResolver $locks, private readonly GroupUmrahEditAuthority $authority) {}
    public function handle(Request $request, Closure $next): Response
    {
        $routeBooking = $request->route('booking');
        $booking = is_object($routeBooking) && method_exists($routeBooking, 'getKey')
            ? (int) $routeBooking->getKey()
            : (int) $routeBooking;
        $state=$this->locks->resolve($booking);
        // Approved bookings remain commercially immutable, but an authorized
        // administrator may record post-approval operational ticket data.
        // Only non-financial Air fields are accepted by this narrow exception.
        if ($state['locked'] && $this->allowsOperationalAirUpdate($request)) return $next($request);
        if($state['locked']) return app(EnforceGeneralBookingEditLock::class)->handle($request,$next);
        return $next($request);
    }

    private function allowsOperationalAirUpdate(Request $request): bool
    {
        if (! $request->routeIs('bookings.air-product.store') || ! in_array(strtoupper($request->method()), ['PUT', 'PATCH', 'POST'], true)) return false;
        if (! $this->authority->canReopen($request->user())) return false;
        foreach (array_keys($request->all()) as $key) if (! in_array((string) $key, ['_token', '_method', 'common', 'segments', 'tickets'], true)) return false;
        foreach (array_keys((array) $request->input('common', [])) as $key) if (! in_array((string) $key, ['pnr', 'airline_pnr', 'booking_source', 'ticket_status', 'issue_date'], true)) return false;
        foreach ((array) $request->input('segments', []) as $segment) foreach (array_keys((array) $segment) as $key) if (! in_array((string) $key, ['from', 'to', 'departure_at', 'arrival_at', 'flight_number', 'airline_code', 'airline'], true)) return false;
        foreach ((array) $request->input('tickets', []) as $ticket) foreach (array_keys((array) $ticket) as $key) if (! in_array((string) $key, ['booking_passenger_id', 'ticket_number', 'booking_class', 'baggage'], true)) return false;
        return true;
    }
}
