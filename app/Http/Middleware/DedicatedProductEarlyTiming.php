<?php

namespace App\Http\Middleware;

use App\Services\Operations\DedicatedProductTimingContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Temporary, route-scoped timing boundary for dedicated product pages. */
final class DedicatedProductEarlyTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        $timing = DedicatedProductTimingContext::forRequest($request);
        if ($timing === null) {
            return $next($request);
        }

        $timing->start('early_total');
        try {
            $response = $next($request);
            return $timing->finishResponse($response);
        } finally {
            $timing->stop('early_total');
        }
    }
}
