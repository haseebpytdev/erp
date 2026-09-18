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
        $timing = DedicatedProductTimingContext::forRequest($request);
        $timing?->start('downstream_response');

        /** @var Response $response */
        $response = $next($request);
        $timing?->stop('downstream_response');

        $response = $this->presenter->transform($request, $response);
        $timing?->stop('product_pipeline_total');

        return $timing?->finishResponse($response) ?? $response;
    }
}
