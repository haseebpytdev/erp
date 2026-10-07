<?php

namespace App\Http\Middleware;

use App\Models\SalesInvoice;
use App\Services\Sales\BaseSalesInvoiceConsistencyResolver;
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
    public function __construct(
        private readonly BaseSalesInvoiceConsistencyResolver $consistency,
    ) {}

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

        if (! str_contains($html, 'data-et-sales-invoice-focus="ERP-11.3.60"')) {
            $html = $this->markHtml($html);
            $html = $this->markBody($html);
        }
        $html = $this->presentBaseConsistency($request, $html);

        $response->setContent($html);
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function presentBaseConsistency(Request $request, string $html): string
    {
        if (str_contains($html, 'data-et-base-invoice-consistency="ERP-11.3.378-C53"')) {
            return $html;
        }

        $invoice = $this->resolveInvoice($request->route('invoice'));
        if (! $invoice) {
            return $html;
        }

        try {
            $state = $this->consistency->resolve($invoice);
        } catch (\Throwable) {
            return $html;
        }

        if (($state['scope'] ?? 'supplementary') !== 'base'
            || ($state['status'] ?? 'IN_SYNC') === 'IN_SYNC') {
            return $html;
        }

        $html = $this->suppressNativeSubmit($html, (int) $invoice->getKey());
        $diagnostic = $this->diagnosticMarkup($invoice, $state);

        foreach ([
            '/(<[^>]*>\s*Workflow\s*<\/[^>]+>)/i',
            '/(<[^>]*>\s*Review Draft Invoice\s*<\/[^>]+>)/i',
        ] as $pattern) {
            $updated = preg_replace($pattern, $diagnostic.'$1', $html, 1, $count);
            if ($count > 0 && is_string($updated)) {
                return $updated;
            }
        }

        return preg_replace('/<\/body>/i', $diagnostic.'</body>', $html, 1) ?: $html;
    }

    private function resolveInvoice(mixed $routeInvoice): ?SalesInvoice
    {
        if ($routeInvoice instanceof SalesInvoice) {
            return $routeInvoice;
        }

        $id = is_scalar($routeInvoice) && preg_match('/^[1-9][0-9]*$/', (string) $routeInvoice)
            ? (int) $routeInvoice
            : 0;

        if ($id <= 0) {
            return null;
        }

        try {
            return SalesInvoice::query()->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    private function suppressNativeSubmit(string $html, int $invoiceId): string
    {
        $target = $this->nativeSubmitPath($invoiceId);
        if ($target === null) {
            return $html;
        }

        return preg_replace_callback(
            '/<form\b[^>]*\baction\s*=\s*(["\'])(.*?)\1[^>]*>.*?<\/form>/is',
            static function (array $match) use ($target): string {
                return str_contains(rawurldecode((string) $match[2]), $target)
                    ? ''
                    : $match[0];
            },
            $html
        ) ?: $html;
    }

    private function nativeSubmitPath(int $invoiceId): ?string
    {
        try {
            $route = app('router')->getRoutes()->getByName('sales.invoices.submit');
            if (! $route) {
                return null;
            }

            return '/'.ltrim(str_replace('{invoice}', (string) $invoiceId, $route->uri()), '/');
        } catch (\Throwable) {
            return null;
        }
    }

    private function diagnosticMarkup(SalesInvoice $invoice, array $state): string
    {
        $esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $currency = trim((string) ($invoice->currency_code ?? ''));
        $money = static fn (mixed $value): string => number_format((float) $value, 2);
        $rows = static function (array $items) use ($esc, $money): string {
            if ($items === []) return '<li>None</li>';
            return implode('', array_map(static fn (array $row): string => '<li>'.$esc($row['description'] ?? 'Service').($row['category'] ?? '' !== '' ? ' · '.$esc($row['category']) : '').' — '.$money($row['amount'] ?? 0).'</li>', $items));
        };

        return '<section data-et-base-invoice-consistency="ERP-11.3.378-C53" class="et-c53-invoice-consistency" role="alert">'
            .'<strong>OUT OF SYNC WITH BOOKING</strong>'
            .'<div>Expected base booking services: '.$esc($state['expected_count'] ?? 0).'</div>'
            .'<div>Invoice represented services: '.$esc($state['actual_count'] ?? 0).'</div>'
            .'<div>Current expected base total: '.($currency !== '' ? $esc($currency).' ' : '').$money($state['expected_total'] ?? 0).'</div>'
            .'<div>Current invoice total: '.($currency !== '' ? $esc($currency).' ' : '').$money($state['invoice_total'] ?? 0).'</div>'
            .'<div>Missing services:</div><ul>'.$rows((array) ($state['missing_services'] ?? [])).'</ul>'
            .'<div>Stale invoice services:</div><ul>'.$rows((array) ($state['stale_services'] ?? [])).'</ul>'
            .'</section>';
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
