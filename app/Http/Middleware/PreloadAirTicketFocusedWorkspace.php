<?php

namespace App\Http\Middleware;

use App\Services\Operations\BookingWorkspaceShellPresenter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-11.3.35 compatibility wrapper.
 *
 * Older route caches/builds may still reference this middleware. It no longer
 * hides the entire body or waits 2.5 seconds. It delegates to the canonical
 * first-paint booking shell presenter.
 */
final class PreloadAirTicketFocusedWorkspace
{
    public function __construct(
        private readonly BookingWorkspaceShellPresenter $presenter,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $this->presenter->transform($request, $response);
    }
}
