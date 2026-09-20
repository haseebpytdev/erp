<?php

namespace App\Http\Middleware;

use App\Services\Administration\ErpRoleAccessPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Operations\DedicatedProductTimingContext;

final class PresentPassengerOperationsLink
{
    public function __construct(private readonly ErpRoleAccessPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $timing = DedicatedProductTimingContext::fromRequest($request);
        $timing?->start('passenger_links');
        $response = $next($request);
        $timing?->stop('passenger_links');
        $timing?->start('passenger_links_response');
        if ($request->routeIs('bookings.products.air.fragment')) {
            $timing?->stop('passenger_links_response');
            return $response;
        }
        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent') || ! $this->policy->moduleAllowed($request->user(), 'passenger operations')) { $timing?->stop('passenger_links_response'); return $response; }
        $html = (string) $response->getContent();
        if ($html === '' || str_contains($html, 'data-et-passengers-nav')) { $timing?->stop('passenger_links_response'); return $response; }
        $active = trim($request->path(), '/') === 'passengers' ? ' active' : '';
        $link = '<a class="nav-item'.$active.'" href="'.e(route('passengers.index')).'" data-et-passengers-nav="true"><span>♙</span><span>Passengers</span></a>';
        $placeholder = '<div class="nav-item muted"><span>•</span><span>Passengers</span><em>Soon</em></div>';
        $html = str_replace($placeholder, $link, $html);
        if (! str_contains($html, 'data-et-passengers-nav')) {
            $html = preg_replace('~(<a\b[^>]*href="[^"]*/operations/bookings[^"]*"[^>]*>.*?</a>)~is', '$1'.$link, $html, 1) ?? $html;
        }
        $response->setContent($html);
        $timing?->stop('passenger_links_response');
        return $response;
    }
}
