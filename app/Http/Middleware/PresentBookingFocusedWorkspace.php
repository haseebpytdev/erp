<?php

namespace App\Http\Middleware;

use App\Services\Operations\BookingWorkspaceShellPresenter;
use App\Services\Operations\DedicatedProductTimingContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PresentBookingFocusedWorkspace
{
    public function __construct(
        private readonly BookingWorkspaceShellPresenter $presenter,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        DedicatedProductTimingContext::forRequest($request);

        /** @var Response $response */
        $response = $next($request);

        return $this->presenter->transform($request, $response);
    }
}
