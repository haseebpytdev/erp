<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class C89AuthenticatedSupportAssetRoute
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
