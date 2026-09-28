<?php

namespace App\Http\Middleware;

use Closure;
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

        if (str_contains($html, 'data-et-sales-invoice-print-v2="ERP-11.3.370"')) {
            return $response;
        }

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
                '<body class="et-si-print-370" data-et-sales-invoice-print-v2="ERP-11.3.370"',
                $tag,
                1
            ) ?? $tag;
        }

        if (! str_contains($replacement, 'data-et-sales-invoice-print-v2=')) {
            $replacement = preg_replace(
                '/<body\b/i',
                '<body data-et-sales-invoice-print-v2="ERP-11.3.370"',
                $replacement,
                1
            ) ?? $replacement;
        }

        return preg_replace('/'.preg_quote($tag, '/').'/', $replacement, $html, 1) ?? $html;
    }

    private function injectStyle(string $html): string
    {
        $style = <<<'CSS'
<style id="et-sales-invoice-print-v2-370">
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
body.et-si-print-370 .thanks{margin-top:7px;font-size:8px}
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
CSS;

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $style.'</head>', $html, 1) ?? $html;
        }

        return $style.$html;
    }
}
