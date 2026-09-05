<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Corrects only the native Company Profile footer-priority help copy. */
final class PresentCompanyVoucherFooterAuthority
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        if (
            ! $request->isMethod('GET')
            || trim($request->path(), '/') !== 'organization/company'
            || ! str_contains(strtolower((string) $response->headers->get('Content-Type')), 'text/html')
        ) {
            return $response;
        }

        $response->setContent($this->correct((string) $response->getContent()));

        return $response;
    }

    public function correct(string $content): string
    {
        return preg_replace(
            '~Voucher-specific Footer\s*(?:→|&rarr;|&#8594;)\s*Selected Pakistan Visa / IATA Footer\s*(?:→|&rarr;|&#8594;)\s*Company Default Footer\.?~iu',
            'Saudi Company Footer → Company Default Footer.',
            $content
        ) ?? $content;
    }
}
