<?php

namespace App\Http\Middleware;

use Closure;
use App\Services\Sales\SalesInvoiceLineDescriptionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Presentation-only compact print treatment for the native Sales Invoice
 * print response. Native controllers, values, and accounting remain intact.
 */
class PresentSalesInvoicePrintV2
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
        if (! is_string($html) || $html === '' || ! str_contains($html, 'class="sheet"')) {
            return $response;
        }

        if (str_contains($html, 'data-et-sales-invoice-print-v2="ERP-11.3.374"')) {
            return $response;
        }

        $html = $this->refineContent($html, $this->invoiceDescriptions($request));
        $html = $this->markBody($html);
        $html = $this->injectStyle($html);

        $response->setContent($html);
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function markBody(string $html): string
    {
        if (preg_match('/<body\b([^>]*)>/i', $html, $matches) !== 1) {
            return $html;
        }

        $tag = $matches[0];
        if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $tag, $classMatch) === 1) {
            $classes = preg_split('/\s+/', trim($classMatch[2])) ?: [];
            if (! in_array('et-si-print-370', $classes, true)) {
                $classes[] = 'et-si-print-370';
            }
            $replacement = str_replace(
                $classMatch[0],
                'class='.$classMatch[1].implode(' ', array_filter($classes)).$classMatch[1],
                $tag
            );
        } else {
            $replacement = preg_replace(
                '/<body\b/i',
                '<body class="et-si-print-370" data-et-sales-invoice-print-v2="ERP-11.3.374"',
                $tag,
                1
            ) ?? $tag;
        }

        if (! str_contains($replacement, 'data-et-sales-invoice-print-v2=')) {
            $replacement = preg_replace(
                '/<body\b/i',
                '<body data-et-sales-invoice-print-v2="ERP-11.3.374"',
                $replacement,
                1
            ) ?? $replacement;
        }

        return preg_replace('/'.preg_quote($tag, '/').'/', $replacement, $html, 1) ?? $html;
    }

    /** @return list<string> */
    private function invoiceDescriptions(Request $request): array
    {
        $route = $request->route();
        $parameters = is_object($route) && method_exists($route, 'parameters') ? $route->parameters() : [];
        if (! is_array($parameters)) return [];
        $candidates = [];
        foreach ($parameters as $key => $value) {
            $id = is_object($value) && method_exists($value, 'getKey')
                ? (int) $value->getKey()
                : (is_scalar($value) ? (int) $value : 0);
            if ($id > 0) $candidates[(string) $key] = $id;
        }
        $known = array_values(array_intersect_key($candidates, array_flip(['invoice', 'sales_invoice', 'salesInvoice', 'id'])));
        $ids = $known !== [] ? array_values(array_unique($known)) : array_values(array_unique($candidates));
        $invoiceId = count($ids) === 1 ? (int) $ids[0] : 0;
        if ($invoiceId <= 0) return [];
        try {
            return app(SalesInvoiceLineDescriptionResolver::class)->resolve($invoiceId);
        } catch (\Throwable) {
            // Product context is optional presentation enrichment. Native HTML
            // remains authoritative when a schema/relationship is unavailable.
            return [];
        }
    }

    private function refineContent(string $html, array $lineDescriptions = []): string
    {
        $html = preg_replace('/(>\s*)TICKET\s+NUMBER(\s*<)/i', '$1TICKET / REF$2', $html) ?? $html;
        $html = preg_replace('/This Sales Invoice is the customer commercial\/accounting document\.\s*Booking Confirmation, Receipt Voucher, Hotel\/Umrah\/Travel Voucher and supplier documents remain separate controlled documents in the ERP\.?/is', '', $html) ?? $html;

        return preg_replace_callback('/<table\b[^>]*class\s*=\s*(["\'])[^"\']*\binvoice-table\b[^"\']*\1[^>]*>.*?<\/table>/is', function (array $tableMatch) use ($lineDescriptions): string {
            $table = $tableMatch[0];
            $ticketColumn = $this->ticketColumnFromHeader($table);
            if ($lineDescriptions !== [] && $this->invoiceDataRowCount($table) !== count($lineDescriptions)) {
                $lineDescriptions = [];
            }

            $lineIndex = 0;
            return preg_replace_callback('/<tr\b[^>]*>.*?<\/tr>/is', function (array $rowMatch) use ($ticketColumn, $lineDescriptions, &$lineIndex): string {
                $row = $rowMatch[0];
                if (stripos($row, '<td') === false) {
                    return $row;
                }

                preg_match_all('/<td\b[^>]*>.*?<\/td>/is', $row, $cellMatches);
                $cells = $cellMatches[0] ?? [];
                $descriptionIndex = null;
                $classTicketIndex = null;
                foreach ($cells as $index => $cell) {
                    $openEnd = strpos($cell, '>');
                    if ($openEnd === false) {
                        continue;
                    }
                    $open = substr($cell, 0, $openEnd + 1);
                    if ($this->hasClass($open, 'desc')) {
                        $descriptionIndex = $index;
                    }
                    if ($this->hasClass($open, 'ticket')) {
                        $classTicketIndex = $index;
                    }
                }

                if ($descriptionIndex === null) {
                    return $row;
                }

                $descriptionRecord = array_key_exists($lineIndex, $lineDescriptions)
                    ? (array) $lineDescriptions[$lineIndex]
                    : null;
                $descriptionContext = $descriptionRecord === null ? null : trim((string) ($descriptionRecord['description'] ?? ''));
                $referenceContext = $descriptionRecord === null ? '' : trim((string) ($descriptionRecord['reference'] ?? ''));
                $lineIndex++;

                $description = $cells[$descriptionIndex];
                $openEnd = strpos($description, '>');
                if ($openEnd === false) {
                    return $row;
                }
                $inner = substr($description, $openEnd + 1, -5);
                $originalInner = $inner;
                $plain = preg_replace('/<br\b[^>]*>/i', "\n", $originalInner) ?? $originalInner;
                $plain = trim(preg_replace('/\s+/', ' ', strip_tags($plain)) ?? '');
                $hasPnr = preg_match('/\bPNR\s*:\s*([A-Za-z0-9][A-Za-z0-9_-]*)\b/i', $plain, $pnrMatch) === 1;
                if (! $hasPnr && $descriptionContext === null) {
                    return $row;
                }

                $pnr = $hasPnr ? trim($pnrMatch[1]) : '';
                $destinationIndex = $classTicketIndex ?? $ticketColumn;
                if ($destinationIndex === null || ! isset($cells[$destinationIndex]) || $destinationIndex === $descriptionIndex) {
                    if ($hasPnr) return $row;
                }

                $destination = $hasPnr ? $cells[$destinationIndex] : '';
                $destinationText = preg_replace('/<br\b[^>]*>/i', "\n", $destination) ?? $destination;
                $destinationText = trim(preg_replace('/\s+/', ' ', strip_tags($destinationText)) ?? '');
                $destinationPnr = null;
                if ($hasPnr && preg_match('/\bPNR\s*:\s*([A-Za-z0-9][A-Za-z0-9_-]*)\b/i', $destinationText, $destinationPnrMatch) === 1) {
                    $destinationPnr = trim($destinationPnrMatch[1]);
                    if (strcasecmp($destinationPnr, $pnr) !== 0) {
                        return $row;
                    }
                }

                $cleanInner = preg_replace('/\s*(?:<br\b[^>]*>\s*)?\bPNR\s*:\s*[A-Za-z0-9][A-Za-z0-9_-]*\b/i', '', $originalInner) ?? $originalInner;
                if ($descriptionContext !== null && $descriptionContext !== '') {
                    $cleanInner = $this->descriptionHtml($descriptionContext);
                } elseif (stripos(strip_tags($cleanInner), 'air ticket') !== false) {
                    $cleanInner = preg_replace('/(?:Adult\s+)?Air Ticket/i', 'Air Ticket', $cleanInner, 1) ?? $cleanInner;
                }
                $safePnr = htmlspecialchars($pnr, ENT_QUOTES, 'UTF-8');
                $cells[$descriptionIndex] = substr($description, 0, $openEnd + 1).$cleanInner.'</td>';
                if ($hasPnr && $destinationPnr === null) {
                    $cells[$destinationIndex] = substr($destination, 0, -5).'<div class="service-detail">PNR: '.$safePnr.'</div></td>';
                } elseif ($referenceContext !== '' && $destinationIndex !== null && isset($cells[$destinationIndex]) && $destinationIndex !== $descriptionIndex) {
                    $existingReference = trim(preg_replace('/\s+/', ' ', strip_tags($destinationText)) ?? '');
                    if ($existingReference === '' || preg_match('/^(?:—|-|N\/A|NONE)$/i', $existingReference) === 1) {
                        $safeReference = htmlspecialchars($referenceContext, ENT_QUOTES, 'UTF-8');
                        $cells[$destinationIndex] = substr($cells[$destinationIndex], 0, -5).'<div class="service-detail">'.$safeReference.'</div></td>';
                    }
                }

                $cursor = 0;
                return preg_replace_callback('/<td\b[^>]*>.*?<\/td>/is', function () use (&$cursor, $cells): string {
                    return $cells[$cursor++] ?? '';
                }, $row) ?? $row;
            }, $table) ?? $table;
        }, $html) ?? $html;
    }

    private function invoiceDataRowCount(string $table): int
    {
        preg_match_all('/<tr\b[^>]*>.*?<td\b[^>]*class\s*=\s*(["\'])[^"\']*\bdesc\b[^"\']*\1[^>]*>.*?<\/tr>/is', $table, $rows);
        return count($rows[0] ?? []);
    }

    private function descriptionHtml(string $description): string
    {
        $lines = preg_split('/\R+/', trim($description)) ?: [];
        $html = '';
        foreach (array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== '')) as $index => $line) {
            $safe = htmlspecialchars(trim($line), ENT_QUOTES, 'UTF-8');
            $html .= '<div class="'.($index === 0 ? 'service-title' : 'service-detail').'">'.$safe.'</div>';
        }
        return $html;
    }

    private function ticketColumnFromHeader(string $table): ?int
    {
        if (preg_match('/<thead\b[^>]*>.*?<\/thead>/is', $table, $headMatch) !== 1) {
            return null;
        }
        if (preg_match('/<tr\b[^>]*>.*?<\/tr>/is', $headMatch[0], $rowMatch) !== 1) {
            return null;
        }
        preg_match_all('/<th\b[^>]*>.*?<\/th>/is', $rowMatch[0], $headers);
        foreach ($headers[0] ?? [] as $index => $header) {
            $label = trim(preg_replace('/\s+/', ' ', strip_tags($header)) ?? '');
            if (preg_match('/\bTICKET\s*(?:\/\s*REF|NUMBER)\b/i', $label) === 1) {
                return $index;
            }
        }
        return null;
    }

    private function hasClass(string $tag, string $class): bool
    {
        if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/is', $tag, $match) !== 1) {
            return false;
        }

        return in_array($class, preg_split('/\s+/', trim($match[2])) ?: [], true);
    }

    private function injectStyle(string $html): string
    {
        $style = <<<'CSS'
<style id="et-sales-invoice-print-v2-374">
body.et-si-print-370{background:#edf3f8;color:#13233d;font-family:Arial,Helvetica,sans-serif}
body.et-si-print-370 .actions{width:210mm;margin:12px auto 10px;display:flex;gap:8px}
body.et-si-print-370 .sheet{width:210mm;min-height:297mm;margin:0 auto 22px;background:#fff;padding:10mm 11mm 9mm;box-shadow:0 6px 22px rgba(15,41,70,.12)}
body.et-si-print-370 .doc-header{grid-template-columns:78px minmax(0,1fr) 232px;gap:14px;padding-bottom:11px;border-bottom:2px solid #103f79}
body.et-si-print-370 .logo-wrap{width:70px;height:70px}
body.et-si-print-370 .logo-wrap img{max-width:70px;max-height:70px}
body.et-si-print-370 .brand-name{font-size:19px;line-height:1.1}
body.et-si-print-370 .brand-legal{font-size:10px;margin-top:3px}
body.et-si-print-370 .brand-contact{font-size:8.75px;line-height:1.35;margin-top:6px}
body.et-si-print-370 .doc-meta{padding-left:14px;font-size:9px;line-height:1.45}
body.et-si-print-370 .doc-meta-title{font-size:21px;margin-bottom:5px}
body.et-si-print-370 .doc-meta-booking,body.et-si-print-370 .doc-meta-row{grid-template-columns:76px minmax(0,1fr);gap:6px}
body.et-si-print-370 .info-grid{gap:10px;margin:7px 0 11px}
body.et-si-print-370 .info-box{min-height:0;border-radius:7px}
body.et-si-print-370 .info-head{padding:6px 9px;font-size:9px}
body.et-si-print-370 .info-body{padding:8px 9px;font-size:9px;line-height:1.4}
body.et-si-print-370 .customer-name{font-size:12px}
body.et-si-print-370 .kv{grid-template-columns:95px minmax(0,1fr);gap:6px}
body.et-si-print-370 .invoice-table{margin-top:5px;border-radius:6px}
body.et-si-print-370 .invoice-table th{padding:6px 6px;font-size:8px}
body.et-si-print-370 .invoice-table td{padding:7px 6px;font-size:9px}
body.et-si-print-370 .invoice-table .sno{width:38px}
body.et-si-print-370 .invoice-table .desc{width:31%}
body.et-si-print-370 .invoice-table .pax{width:28%}
body.et-si-print-370 .invoice-table .ticket{width:17%;white-space:nowrap}
body.et-si-print-370 .invoice-table .amount{width:17%;white-space:nowrap;font-size:9px}
body.et-si-print-370 .service-title{font-size:9.5px;margin-bottom:2px}
body.et-si-print-370 .service-detail{font-size:8px;line-height:1.3}
body.et-si-print-370 .pax-name{line-height:1.25}
body.et-si-print-370 .pax-type{font-size:8px;margin-top:2px}
body.et-si-print-370 .totals{width:40%;margin-top:10px;font-size:9px}
body.et-si-print-370 .total-row{grid-template-columns:1fr 110px;gap:8px;padding:3px 0}
body.et-si-print-370 .total-row.grand{margin-top:3px;padding-top:6px;font-size:12px}
body.et-si-print-370 .grand-value{padding:5px 7px;border-radius:4px}
body.et-si-print-370 .notes{margin-top:11px;border-radius:6px}
body.et-si-print-370 .notes .info-head{padding:5px 9px}
body.et-si-print-370 .notes-body{padding:7px 9px;font-size:8.5px;line-height:1.35}
body.et-si-print-370 .foot{margin-top:11px;padding-top:8px;font-size:7.75px;line-height:1.35}
body.et-si-print-370 .thanks{margin-top:7px;font-size:8px;text-align:right;white-space:normal}
@page{size:A4 portrait;margin:10mm}
@media print{
    body.et-si-print-370{background:#fff}
    body.et-si-print-370 .actions{display:none}
    body.et-si-print-370 .sheet{width:auto;min-height:0;margin:0;box-shadow:none;padding:0}
    body.et-si-print-370 .invoice-table thead{display:table-header-group}
    body.et-si-print-370 .invoice-table tr{break-inside:avoid;page-break-inside:avoid}
    body.et-si-print-370 .totals,body.et-si-print-370 .notes,body.et-si-print-370 .foot,body.et-si-print-370 .thanks{break-inside:avoid;page-break-inside:avoid}
}
@media screen and (max-width:900px){
    body.et-si-print-370 .sheet{width:min(210mm,calc(100vw - 24px));overflow:hidden}
    body.et-si-print-370 .doc-header{grid-template-columns:64px minmax(0,1fr);}
    body.et-si-print-370 .doc-meta{grid-column:1/-1;border-left:0;border-top:1px solid #d9e3ef;padding:8px 0 0}
}
</style>
CSS;

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $style.'</head>', $html, 1) ?? $html;
        }

        return $style.$html;
    }
}
