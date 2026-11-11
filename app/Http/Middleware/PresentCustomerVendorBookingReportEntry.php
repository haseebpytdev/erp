<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Adds the read-only report to the existing native Accounting Reports navigation. */
final class PresentCustomerVendorBookingReportEntry
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) return $response;
        $html = (string) $response->getContent();
        if ($html === '' || stripos((string) $response->headers->get('content-type', ''), 'text/html') === false) return $response;
        if (str_contains($html, 'data-customer-vendor-report-entry="1"')) return $response;
        $link = '<a data-customer-vendor-report-entry="1" href="'.e(route('accounting.customer-vendor-bookings.index')).'">Customer &amp; Vendor Bookings</a>';
        if (preg_match('/(<nav[^>]+data-et-management-report-nav="[^"]+"[^>]*>)([\s\S]*?)(<\/nav>)/i', $html, $match) === 1) {
            $html = str_replace($match[0], $match[1].$match[2].$link.$match[3], $html);
        } elseif (preg_match('/(<form[^>]+data-et-report-filter(?:="[^"]*")?[^>]*>)/i', $html, $match) === 1) {
            $html = str_replace($match[1], '<nav data-customer-vendor-report-entry="1" class="et-customer-vendor-report-entry">'.$link.'</nav>'.$match[1], $html);
        }
        $response->setContent($html);
        $response->headers->remove('Content-Length');
        return $response;
    }
}
