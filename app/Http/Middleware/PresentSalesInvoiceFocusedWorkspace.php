<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Sales Invoice focused workspace shell.
 *
 * The native Sales Invoice show route is the same page used after:
 * Draft -> Pending Approval -> Approved -> Posted.
 *
 * Presentation state is marked server-side before first paint so the focused
 * shell and Air Ticket invoice theme never depend on a later body-class flip.
 * Sales Invoice Register/index keeps the normal permanent ERP sidebar.
 */
class PresentSalesInvoiceFocusedWorkspace
{
    public function handle(Request $request, Closure $next): BaseResponse
    {
        $response = $next($request);

        if (
            ! $response instanceof Response
            || $response->getStatusCode() >= 400
            || ! str_contains(strtolower((string) $response->headers->get('content-type')), 'text/html')
        ) {
            return $response;
        }

        $html = $response->getContent();
        if (! is_string($html) || $html === '') {
            return $response;
        }

        if (str_contains($html, 'data-et-sales-invoice-focus="ERP-11.3.60"')) {
            return $response;
        }

        $html = $this->markHtml($html);
        $html = $this->markBody($html);

        $response->setContent($html);
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function markHtml(string $html): string
    {
        if (preg_match('/<html\b([^>]*)>/i', $html, $matches) !== 1) {
            return $html;
        }

        $tag = $matches[0];
        if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $tag, $classMatch) === 1) {
            $classes = trim($classMatch[2].' et-sales-invoice-focus-prepaint');
            $replacement = str_replace(
                $classMatch[0],
                'class='.$classMatch[1].$classes.$classMatch[1],
                $tag
            );
        } else {
            $replacement = preg_replace(
                '/<html\b/i',
                '<html class="et-sales-invoice-focus-prepaint"',
                $tag,
                1
            ) ?? $tag;
        }

        $html = preg_replace('/'.preg_quote($tag, '/').'/', $replacement, $html, 1) ?? $html;
        return preg_replace(
            '/<html\b(?![^>]*data-et-sales-invoice-focus)/i',
            '<html data-et-sales-invoice-focus="ERP-11.3.60"',
            $html,
            1
        ) ?? $html;
    }

    private function markBody(string $html): string
    {
        if (preg_match('/<body\b([^>]*)>/i', $html, $matches) !== 1) {
            return $html;
        }

        $tag = $matches[0];
        if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $tag, $classMatch) === 1) {
            $classes = preg_split('/\s+/', trim($classMatch[2])) ?: [];
            if (! in_array('et-si11-page-103179', $classes, true)) {
                $classes[] = 'et-si11-page-103179';
            }
            $replacement = str_replace(
                $classMatch[0],
                'class='.$classMatch[1].implode(' ', array_filter($classes)).$classMatch[1],
                $tag
            );
        } else {
            $replacement = preg_replace(
                '/<body\b/i',
                '<body class="et-si11-page-103179"',
                $tag,
                1
            ) ?? $tag;
        }

        return preg_replace('/'.preg_quote($tag, '/').'/', $replacement, $html, 1) ?? $html;
    }
}
