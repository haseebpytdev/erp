<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-11.3.10
 *
 * Canonical Chart-of-Accounts handoff. The legacy native URL remains valid for
 * old bookmarks, but it no longer renders a second Chart UI. Authorized native
 * route middleware still runs; this bridge then redirects to the dedicated
 * ERP-11.3.10 canonical workspace and preserves query-string filters.
 */
class PresentChartOfAccountsWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('GET')
            && trim($request->path(), '/') === 'accounting/chart-of-accounts'
        ) {
            return redirect()->route(
                'accounting.chart-of-accounts.workspace',
                $request->query()
            );
        }

        /** @var Response $response */
        $response = $next($request);
        return $response;
    }
}
