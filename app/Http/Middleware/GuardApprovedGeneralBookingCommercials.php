<?php

namespace App\Http\Middleware;

use App\Services\Operations\BookingEditLockResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final class GuardApprovedGeneralBookingCommercials
{
    public function __construct(private readonly BookingEditLockResolver $locks) {}
    public function handle(Request $request, Closure $next): Response
    {
        $booking=(int)$request->route('booking');
        $state=$this->locks->resolve($booking);
        if($state['locked']) return app(EnforceGeneralBookingEditLock::class)->handle($request,$next);
        return $next($request);
    }
}
