<?php

namespace App\Http\Middleware;

use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use App\Services\Sales\SalesInvoiceProductCommercialSummaryResolver;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Throwable;

class PresentAirTicketSalesInvoice
{
    public function __construct(
        private readonly AirTicketInvoiceCommercialSyncService $sync,
        private readonly SalesInvoiceProductCommercialSummaryResolver $commercialSummary,
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): BaseResponse {
        $response = $next($request);

        if (
            ! $response instanceof Response
            || $response->getStatusCode() >= 400
            || ! str_contains(
                strtolower(
                    (string) $response->headers->get(
                        'content-type'
                    )
                ),
                'text/html'
            )
        ) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return $response;
        }

        $routeInvoice =
            $request->route('invoice');

        if ($routeInvoice === null) {
            return $response;
        }

        /*
         * The native page itself is also a reliable Air Ticket signal. This
         * prevents old invoices from missing the focused presenter solely
         * because one legacy booking-link column is absent.
         */
        $htmlLooksAirTicket =
            stripos($content, 'AIR_TICKET') !== false
            || stripos($content, 'Air Ticket') !== false;

        try {
            $snapshot =
                $this->sync->snapshot(
                    $routeInvoice
                );
        } catch (Throwable) {
            $snapshot = [
                'supported' => false,
            ];
        }

        if (
            ! ($snapshot['supported'] ?? false)
            && ! $htmlLooksAirTicket
        ) {
            return $response;
        }

        /*
         * If HTML proves this is Air Ticket but the first snapshot resolution
         * still failed, do not silently fall back to the wrong generic invoice
         * presentation. Render the focused shell and show a controlled sync
         * diagnostic instead.
         */
        $invoice =
            $snapshot['invoice']
            ?? (
                is_object($routeInvoice)
                    ? $routeInvoice
                    : null
            );

        $invoiceId = is_object($invoice)
            && method_exists($invoice, 'getKey')
                ? (int) $invoice->getKey()
                : (int) (
                    is_scalar($routeInvoice)
                        ? $routeInvoice
                        : 0
                );

        $status = is_object($invoice)
            && method_exists($invoice, 'getAttribute')
                ? strtolower(
                    trim(
                        (string) (
                            $invoice->getAttribute('status')
                            ?? $invoice->getAttribute(
                                'invoice_status'
                            )
                            ?? ''
                        )
                    )
                )
                : (
                    stripos($content, '>DRAFT<') !== false
                        ? 'draft'
                        : ''
                );

        $bookingId =
            (int) (
                $snapshot['booking_id']
                ?? 0
            );

        if ($bookingId <= 0) {
            /*
             * Final UI fallback from the rendered booking reference. The sync
             * service itself now has the same reference-suffix resolver.
             */
            if (
                preg_match(
                    '/BK-\d{4}-(\d{4,12})/i',
                    $content,
                    $matches
                ) === 1
            ) {
                $bookingId =
                    (int) ltrim(
                        (string) (
                            $matches[1]
                            ?? ''
                        ),
                        '0'
                    );
            }
        }

        $bookingUrl =
            $bookingId > 0
                ? (
                    app('router')->has(
                        'operations.bookings.show'
                    )
                        ? route(
                            'operations.bookings.show',
                            ['booking' => $bookingId]
                        )
                        : url(
                            '/operations/bookings/'
                            .$bookingId
                        )
                )
                : '#';

        $syncUrl =
            $invoiceId > 0
                ? route(
                    'sales.invoices.air-ticket-sync',
                    ['invoice' => $invoiceId]
                )
                : '#';

        $tickets =
            (array) (
                $snapshot['tickets']
                ?? []
            );

        $groups =
            (array) (
                $snapshot['groups']
                ?? []
            );

        $paxMix = [
            'ADULT' => 0,
            'CHILD' => 0,
            'INFANT' => 0,
        ];

        foreach ($tickets as $ticket) {
            $fare = strtoupper(
                trim(
                    (string) (
                        $ticket['fare_type']
                        ?? 'ADULT'
                    )
                )
            );

            if (! array_key_exists($fare, $paxMix)) {
                $fare = 'ADULT';
            }

            $paxMix[$fare]++;
        }

        $supported =
            (bool) (
                $snapshot['supported']
                ?? false
            );

        $profitability = [
            'products' => [],
            'invoice_sale_total' => $invoice instanceof Model
                ? $this->nativeInvoiceTotal($invoice)
                : 0.0,
            'product_sale_total' => 0.0,
            'sale_reconciles' => false,
            'total_cost' => null,
            'total_cost_complete' => false,
            'gross_margin' => null,
            'gross_margin_complete' => false,
            'product_count' => 0,
            'passenger_count' => 0,
            'passenger_mix' => ['ADULT' => 0, 'CHILD' => 0, 'INFANT' => 0],
        ];
        if ($invoice instanceof Model && $bookingId > 0) {
            try {
                $profitability = $this->commercialSummary->resolve($invoice, $bookingId);
            } catch (Throwable) {
                // Presentation remains read-only and degrades to explicit
                // incomplete profitability rather than inventing zero costs.
            }
        }

        $data = [
            'release' => 'ERP-11.3',
            'supported' => $supported,
            'reason' =>
                (string) (
                    $snapshot['reason']
                    ?? (
                        $supported
                            ? ''
                            : 'Air Ticket booking commercial source could not yet be resolved.'
                    )
                ),
            'status' => $status,
            'draft' => $status === 'draft',
            'bookingUrl' => $bookingUrl,
            'syncUrl' => $syncUrl,
            'csrf' => csrf_token(),
            'needsSync' =>
                (bool) (
                    $snapshot['needs_sync']
                    ?? true
                ),
            'ticketCount' =>
                (int) (
                    $snapshot['ticket_count']
                    ?? count($tickets)
                ),
            'total' =>
                (float) (
                    $snapshot['total']
                    ?? 0
                ),
            'currentAirLineTotal' =>
                (float) (
                    $snapshot['current_air_line_total']
                    ?? 0
                ),
            'paxMix' => $paxMix,
            'profitability' => $profitability,
            'groups' => array_map(
                static fn (array $group): array => [
                    'fare_type' =>
                        (string) (
                            $group['fare_type']
                            ?? 'ADULT'
                        ),
                    'quantity' =>
                        (int) (
                            $group['quantity']
                            ?? 0
                        ),
                    'rate' =>
                        (float) (
                            $group['rate']
                            ?? 0
                        ),
                    'total' =>
                        (float) (
                            $group['total']
                            ?? 0
                        ),
                    'tickets' => array_map(
                        static fn (array $ticket): array => [
                            'passenger_name' =>
                                (string) (
                                    $ticket['passenger_name']
                                    ?? ''
                                ),
                            'fare_type' =>
                                (string) (
                                    $ticket['fare_type']
                                    ?? ''
                                ),
                            'ticket_number' =>
                                (string) (
                                    $ticket['ticket_number']
                                    ?? ''
                                ),
                            'pnr' =>
                                (string) (
                                    $ticket['pnr']
                                    ?? ''
                                ),
                            'customer_sale' =>
                                (float) (
                                    $ticket['customer_sale']
                                    ?? 0
                                ),
                        ],
                        (array) (
                            $group['tickets']
                            ?? []
                        )
                    ),
                ],
                $groups
            ),
            'tickets' => array_map(
                static fn (array $ticket): array => [
                    'passenger_name' =>
                        (string) (
                            $ticket['passenger_name']
                            ?? ''
                        ),
                    'fare_type' =>
                        (string) (
                            $ticket['fare_type']
                            ?? ''
                        ),
                    'ticket_number' =>
                        (string) (
                            $ticket['ticket_number']
                            ?? ''
                        ),
                    'pnr' =>
                        (string) (
                            $ticket['pnr']
                            ?? ''
                        ),
                    'customer_sale' =>
                        (float) (
                            $ticket['customer_sale']
                            ?? 0
                        ),
                ],
                $tickets
            ),
        ];

        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        if (! is_string($json)) {
            return $response;
        }

        $injection = <<<HTML
<style id="et-si11-style-103179">
:root{
    --et-si11-ink:#17243a;
    --et-si11-muted:#66768c;
    --et-si11-line:#dfe7f1;
    --et-si11-soft:#f5f8fc;
    --et-si11-blue:#1769d2;
    --et-si11-blue-soft:#eef5ff;
    --et-si11-amber:#fff7df;
    --et-si11-green:#139a62;
    --et-si11-green-soft:#eaf8f2;
    --et-si11-purple:#7c3aed;
    --et-si11-purple-soft:#f3efff;
    --et-si11-orange:#d97706;
    --et-si11-orange-soft:#fff3e6;
    --et-si11-red:#c83c3c;
}
body.et-si11-page-103179{background:#f4f7fb!important;color:var(--et-si11-ink)}
body.et-si11-page-103179 main,
body.et-si11-page-103179 .page-body,
body.et-si11-page-103179 .content-wrapper{font-size:13px}
.et-si11-card-103179{
    border:1px solid var(--et-si11-line)!important;
    border-radius:12px!important;
    background:#fff!important;
    box-shadow:0 8px 24px rgba(29,55,88,.045)!important;
    overflow:hidden!important;
}
.et-si11-metric-103179{
    border:1px solid var(--et-si11-line)!important;
    border-radius:11px!important;
    background:#fff!important;
    box-shadow:0 6px 18px rgba(29,55,88,.035)!important;
    min-height:104px!important;
    padding:16px!important;
}
.et-si11-metric-103179 *{line-height:1.35}
.et-si11-native-metrics-hidden-103179{display:none!important}
.et-si11-summary-103179{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:11px;margin:0 0 14px;width:100%}
.et-si11-summary-card-103179{display:grid;grid-template-columns:38px minmax(0,1fr);gap:11px;align-items:center;min-width:0;padding:14px;border:1px solid var(--et-si11-line);border-radius:12px;background:#fff;box-shadow:0 6px 18px rgba(29,55,88,.035)}
.et-si11-summary-icon-103179,.et-si11-product-icon-103179{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:36px;height:36px;border-radius:9px;background:var(--et-si11-blue-soft);color:var(--et-si11-blue)}
.et-si11-summary-icon-103179 svg,.et-si11-product-icon-103179 svg,.et-si11-title-icon-103179 svg,.et-si11-account-icon-103179 svg{width:19px;height:19px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.et-si11-summary-label-103179{font-size:8.5px;line-height:1.2;font-weight:850;letter-spacing:.045em;text-transform:uppercase;color:#718096}
.et-si11-summary-value-103179{margin-top:3px;font-size:16px;line-height:1.2;font-weight:900;color:var(--et-si11-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.et-si11-summary-value-103179.positive,.et-si11-margin-103179.positive{color:var(--et-si11-green)}
.et-si11-summary-value-103179.negative,.et-si11-margin-103179.negative{color:var(--et-si11-red)}
.et-si11-summary-sub-103179{margin-top:3px;font-size:8.5px;line-height:1.3;color:var(--et-si11-muted);white-space:normal}
.et-si11-review-103179{padding:18px!important;margin-bottom:14px!important}
.et-si11-review-103179 input,
.et-si11-review-103179 select,
.et-si11-review-103179 textarea{
    border:1px solid #cfdae8!important;
    border-radius:7px!important;
    background:#fff!important;
    min-height:39px;
    font-size:12px!important;
}
.et-si11-review-103179 textarea{min-height:78px!important}
.et-si11-review-103179 label{font-size:10px!important;font-weight:800!important;color:#40516a!important}
.et-si11-review-103179 button[type="submit"]{
    border-radius:7px!important;
    min-height:36px!important;
    padding:7px 12px!important;
    font-size:10px!important;
    font-weight:850!important;
}
.et-si11-kicker-103179{
    color:var(--et-si11-blue)!important;
    font-size:10px!important;
    font-weight:900!important;
    letter-spacing:.045em!important;
    text-transform:uppercase!important;
}
.et-si11-native-lines-103179,
.et-si11-native-duplicate-103179{display:none!important}
.et-si11-source-103179{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    align-items:center;
    gap:14px;
    margin-top:14px;
    padding:12px 14px;
    border:1px solid #ecd89c;
    border-radius:9px;
    background:var(--et-si11-amber);
}
.et-si11-source-103179.ready{border-color:#d2e3f8;background:#f4f8ff}
.et-si11-source-title-103179{font-size:11px;font-weight:900;color:#24344b}
.et-si11-source-note-103179{margin-top:3px;font-size:10px;line-height:1.45;color:#66768c}
.et-si11-actions-103179{display:flex;gap:7px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.et-si11-btn-103179{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:36px;padding:7px 11px;border:1px solid #d3deea;border-radius:7px;
    background:#fff;color:#26364e;text-decoration:none;font-size:10px;font-weight:850;
    white-space:nowrap;cursor:pointer
}
.et-si11-btn-103179.primary{background:var(--et-si11-blue);border-color:var(--et-si11-blue);color:#fff}
.et-si11-title-with-icon-103179{display:flex!important;align-items:center!important;gap:9px!important}
.et-si11-title-icon-103179{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;background:var(--et-si11-blue-soft);color:var(--et-si11-blue)}
.et-si11-profitability-103179{width:100%;margin:0 0 14px;box-sizing:border-box}
.et-si11-profit-head-103179{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:15px 16px 12px;border-bottom:1px solid #edf1f6}
.et-si11-profit-title-103179{display:flex;align-items:center;gap:9px;font-size:15px;font-weight:900;color:var(--et-si11-ink)}
.et-si11-profit-note-103179{margin-top:3px;font-size:10px;color:#718096}
.et-si11-reconcile-103179{font-size:8.5px;font-weight:850;color:var(--et-si11-green);background:var(--et-si11-green-soft);border-radius:999px;padding:5px 8px}
.et-si11-reconcile-103179.warn{color:var(--et-si11-red);background:#fff0f0}
.et-si11-profit-body-103179{padding:7px 16px 12px}
.et-si11-profit-row-103179{display:grid;grid-template-columns:minmax(190px,1.35fr) repeat(3,minmax(120px,.75fr));align-items:center;gap:12px;padding:10px 8px;border-bottom:1px solid #edf1f5}
.et-si11-profit-row-103179.header{padding-top:6px;padding-bottom:7px;font-size:8px;font-weight:850;letter-spacing:.045em;text-transform:uppercase;color:#718096}
.et-si11-profit-row-103179.total{margin-top:4px;border:0;border-radius:8px;background:var(--et-si11-blue-soft);font-weight:900;color:var(--et-si11-ink)}
.et-si11-product-103179{display:flex;align-items:center;gap:10px;min-width:0;font-size:11px;font-weight:850;color:#26384e}
.et-si11-product-icon-103179.air{background:var(--et-si11-blue-soft);color:var(--et-si11-blue)}
.et-si11-product-icon-103179.hotel{background:var(--et-si11-purple-soft);color:var(--et-si11-purple)}
.et-si11-product-icon-103179.transport{background:var(--et-si11-green-soft);color:var(--et-si11-green)}
.et-si11-product-icon-103179.visa{background:var(--et-si11-orange-soft);color:var(--et-si11-orange)}
.et-si11-profit-number-103179{text-align:right;font-size:11px;font-weight:850;color:#26384e;white-space:nowrap}
.et-si11-margin-103179{display:inline-flex;justify-self:end;padding:4px 7px;border-radius:6px;background:#f1f4f8;color:#5c6d84}
.et-si11-margin-103179.positive{background:var(--et-si11-green-soft)}
.et-si11-margin-103179.negative{background:#fff0f0}
.et-si11-cost-warning-103179{display:block;margin-top:2px;font-size:8px;font-weight:650;color:#a06517;white-space:normal}
.et-si11-commercial-103179{width:100%;margin:14px 0;box-sizing:border-box}
.et-si11-commercial-grid-103179{
    display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);
    gap:12px;align-items:start;width:100%
}
.et-si11-panel-103179{
    min-width:0;border:1px solid var(--et-si11-line);border-radius:12px;background:#fff;
    box-shadow:0 8px 24px rgba(29,55,88,.04);overflow:hidden
}
.et-si11-panel-head-103179{padding:15px 16px 12px;border-bottom:1px solid #edf1f6}
.et-si11-panel-title-103179{font-size:15px;font-weight:900;color:var(--et-si11-ink)}
.et-si11-panel-note-103179{margin-top:3px;font-size:10px;color:#718096;line-height:1.45}
.et-si11-panel-body-103179{padding:14px 16px 16px}
.et-si11-line-103179{
    margin-bottom:10px;padding:12px;border:1px solid #dfe7f1;border-radius:9px;background:#fbfcfe
}
.et-si11-line-103179:last-child{margin-bottom:0}
.et-si11-line-top-103179{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.et-si11-line-title-103179{font-size:12px;font-weight:900;color:#24344b}
.et-si11-line-title-103179 span{color:var(--et-si11-blue)}
.et-si11-line-amount-103179{font-size:12px;font-weight:900;color:#0e57b7;white-space:nowrap}
.et-si11-line-metrics-103179{
    display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-top:10px;padding-top:10px;border-top:1px solid #e4eaf2
}
.et-si11-line-metric-103179 small{display:block;font-size:8.5px;color:#718096;font-weight:700}
.et-si11-line-metric-103179 strong{display:block;margin-top:3px;font-size:10px;color:#24344b}
.et-si11-ticket-box-103179{margin-top:10px;padding:9px 10px;border-radius:7px;background:#eef5ff}
.et-si11-ticket-103179{display:flex;gap:7px;align-items:flex-start;padding:4px 0;font-size:9.5px;line-height:1.4;color:#35577f}
.et-si11-ticket-103179:before{content:'●';font-size:7px;color:#1769d2;margin-top:2px}
.et-si11-ticket-name-103179{font-weight:800;color:#40516a}
.et-si11-pax-hero-103179{
    display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:center;padding:11px 12px;border-radius:8px;background:#eef5ff
}
.et-si11-pax-count-103179{font-size:27px;line-height:1;font-weight:900;color:var(--et-si11-blue)}
.et-si11-pax-caption-103179{margin-top:3px;font-size:9px;color:#66768c}
.et-si11-pills-103179{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end}
.et-si11-pill-103179{padding:4px 7px;border:1px solid #d3deea;border-radius:6px;background:#fff;font-size:8.8px;font-weight:850;color:#33445d}
.et-si11-table-103179{width:100%;border-collapse:collapse;margin-top:11px;table-layout:fixed}
.et-si11-table-103179 th{padding:7px 6px;border-bottom:1px solid #dce5ef;text-align:left;font-size:8px;letter-spacing:.035em;text-transform:uppercase;color:#617189}
.et-si11-table-103179 td{padding:9px 6px;border-bottom:1px solid #edf1f5;font-size:9.5px;color:#304159;vertical-align:top;overflow-wrap:anywhere}
.et-si11-table-103179 tr:last-child td{border-bottom:0}
.et-si11-table-103179 td:first-child{font-weight:850;color:#24344b}
.et-si11-table-103179 .right{text-align:right;font-weight:850;color:#24344b}
.et-si11-table-103179 th:nth-child(1),.et-si11-table-103179 td:nth-child(1){width:34%}
.et-si11-table-103179 th:nth-child(2),.et-si11-table-103179 td:nth-child(2){width:14%;text-align:center}
.et-si11-table-103179 th:nth-child(3),.et-si11-table-103179 td:nth-child(3){width:52%}
.et-si11-ticket-refs-103179{display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.et-si11-ticket-ref-103179{display:inline-flex;max-width:100%;padding:4px 7px;border:1px solid #d6e3f2;border-radius:6px;background:#f5f9ff;color:#174f92;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:9px;font-weight:800;line-height:1.25;letter-spacing:.025em;overflow-wrap:anywhere}
.et-si11-ticket-ref-103179.pending{border-style:dashed;background:#fafbfd;color:#7b8798;font-family:inherit;font-weight:700}
.et-si11-accounting-103179{margin-top:14px!important;padding:16px!important}
.et-si11-accounting-103179 table{width:100%!important;border-collapse:collapse!important}
.et-si11-accounting-103179 th{padding:8px!important;background:#f8fafc!important;font-size:8px!important;text-transform:uppercase!important;letter-spacing:.04em!important;color:#617189!important}
.et-si11-accounting-103179 td{padding:9px 8px!important;border-bottom:1px solid #edf1f5!important;font-size:9.5px!important}
.et-si11-account-icon-103179{display:inline-flex;align-items:center;justify-content:center;width:25px;height:25px;margin-right:7px;border-radius:7px;vertical-align:middle;background:var(--et-si11-blue-soft);color:var(--et-si11-blue)}
.et-si11-account-row-103179.hotel .et-si11-account-icon-103179{background:var(--et-si11-purple-soft);color:var(--et-si11-purple)}
.et-si11-account-row-103179.transport .et-si11-account-icon-103179{background:var(--et-si11-green-soft);color:var(--et-si11-green)}
.et-si11-account-row-103179.visa .et-si11-account-icon-103179{background:var(--et-si11-orange-soft);color:var(--et-si11-orange)}
.et-si11-account-row-103179.receivable{background:var(--et-si11-blue-soft)!important;font-weight:900!important}
.et-si11-account-note-103179{margin-top:10px;padding:9px 11px;border-radius:8px;background:#f7f9fc;color:#66768c;font-size:9px;line-height:1.45}
.et-si11-bottom-grid-103179{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,.95fr);gap:12px;margin:14px 0}
.et-si11-workflow-103179,.et-si11-activity-103179{padding:16px!important;margin:0!important;min-width:0!important}
.et-si11-timeline-103179{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0;margin:14px 0 12px}
.et-si11-step-103179{position:relative;text-align:center;padding-top:1px}
.et-si11-step-103179:not(:last-child):after{content:'';position:absolute;top:14px;left:58%;right:-42%;height:1px;background:#cbd6e4}
.et-si11-step-dot-103179{position:relative;z-index:2;display:flex;width:28px;height:28px;margin:0 auto 6px;border:1px solid #cfdae8;border-radius:50%;align-items:center;justify-content:center;background:#f5f8fc;color:#708096;font-size:9px;font-weight:900}
.et-si11-step-103179.done .et-si11-step-dot-103179{background:#eaf8f2;border-color:#bfe7d4;color:#118b59}
.et-si11-step-103179.current .et-si11-step-dot-103179{background:var(--et-si11-blue);border-color:var(--et-si11-blue);color:#fff;box-shadow:0 0 0 4px rgba(23,105,210,.09)}
.et-si11-step-label-103179{font-size:8.8px;font-weight:850;color:#53647b}
.et-si11-workflow-103179 button,.et-si11-workflow-103179 a{border-radius:7px!important;font-size:9.5px!important;font-weight:850!important}
.et-si11-activity-103179 [class*="activity"],.et-si11-activity-103179 article{border-radius:8px!important}
.et-si11-legacy-alert-103179{display:none!important}
.et-si11-integrity-warning-103179{
    margin:0 0 14px!important;
    padding:11px 13px!important;
    border:1px solid #efc7c7!important;
    border-radius:9px!important;
    background:#fff3f3!important;
    color:#8d2323!important;
    font-size:10.5px!important;
    line-height:1.45!important;
    font-weight:750!important;
}
.et-si11-workflow-locked-103179{
    opacity:.48!important;
    pointer-events:none!important;
    cursor:not-allowed!important;
}
@media(max-width:1100px){
    .et-si11-commercial-grid-103179,.et-si11-bottom-grid-103179{grid-template-columns:1fr}
    .et-si11-summary-103179{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:760px){
    .et-si11-source-103179{grid-template-columns:1fr}
    .et-si11-actions-103179{justify-content:flex-start}
    .et-si11-line-metrics-103179{grid-template-columns:1fr 1fr 1fr}
    .et-si11-table-103179 th:nth-child(4),.et-si11-table-103179 td:nth-child(4){display:none}
    .et-si11-summary-103179{grid-template-columns:repeat(2,minmax(0,1fr))}
    .et-si11-profit-head-103179{grid-template-columns:1fr}
    .et-si11-profit-row-103179{grid-template-columns:1fr 1fr;gap:7px 12px;margin:7px 0;padding:11px;border:1px solid #edf1f5;border-radius:9px}
    .et-si11-profit-row-103179.header{display:none}
    .et-si11-profit-row-103179>div:not(.et-si11-product-103179):before{display:block;margin-bottom:2px;font-size:7.5px;font-weight:850;letter-spacing:.04em;text-transform:uppercase;color:#8491a4}
    .et-si11-profit-row-103179>div:nth-child(2):before{content:'Sale Total'}
    .et-si11-profit-row-103179>div:nth-child(3):before{content:'Cost Total'}
    .et-si11-profit-row-103179>div:nth-child(4):before{content:'Margin'}
    .et-si11-profit-row-103179 .et-si11-product-103179{grid-column:1/-1}
    .et-si11-profit-number-103179,.et-si11-margin-103179{text-align:left;justify-self:start}
}
@media(max-width:430px){.et-si11-summary-103179{grid-template-columns:1fr}.et-si11-profit-row-103179{grid-template-columns:1fr}.et-si11-profit-row-103179 .et-si11-product-103179{grid-column:auto}}
</style>
<script id="et-si11-script-103179">
(function(){
'use strict';
const data={$json};
const norm=v=>String(v||'').replace(/\s+/g,' ').trim().toLowerCase();
const esc=v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
const money=v=>'PKR '+Number(v||0).toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:2});
const iconPaths={
    invoice:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
    cost:'<circle cx="9" cy="20" r="1"/><circle cx="19" cy="20" r="1"/><path d="M3 4h2l2.7 11.4a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 8H6"/>',
    margin:'<path d="M3 3v18h18"/><path d="m7 16 4-5 4 3 5-7"/>',
    users:'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    package:'<path d="m21 8-9-5-9 5 9 5 9-5z"/><path d="m3 8 9 5 9-5M3 8v8l9 5 9-5V8M12 13v8"/>',
    plane:'<path d="M22 2 9.7 14.3M15 6l-4-4M9 11l-7-1 3 3-3 3 7-1 4 4 2-6 4-2z"/>',
    building:'<path d="M3 21h18M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1"/>',
    bus:'<rect x="4" y="3" width="16" height="16" rx="2"/><path d="M4 11h16M8 19v2M16 19v2M8 15h.01M16 15h.01"/>',
    visa:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
    'file-description':'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
    calculator:'<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/>'
};
const icon=key=>'<svg viewBox="0 0 24 24" aria-hidden="true">'+(iconPaths[key]||iconPaths.package)+'</svg>';
const leafExact=text=>Array.from(document.querySelectorAll('*')).find(el=>el.children.length===0&&norm(el.textContent)===norm(text))||null;
const leafContains=text=>Array.from(document.querySelectorAll('*')).find(el=>el.children.length===0&&norm(el.textContent).includes(norm(text)))||null;
const nearestCard=node=>{
    if(!node)return null;
    const direct=node.closest('.card,[class*="card"],[class*="panel"],section');
    if(direct){const r=direct.getBoundingClientRect();if(r.width>260&&r.height>45)return direct;}
    let current=node;
    for(let i=0;i<8&&current&&current!==document.body;i++){
        const r=current.getBoundingClientRect();
        const bg=getComputedStyle(current).backgroundColor;
        if(r.width>260&&r.height>45&&bg!=='rgba(0, 0, 0, 0)')return current;
        current=current.parentElement;
    }
    return node.parentElement;
};
const commonAncestor=(a,b)=>{
    if(!a)return b||null;if(!b)return a||null;
    const set=new Set();let n=a;
    while(n&&n!==document.body){set.add(n);n=n.parentElement;}
    n=b;while(n&&n!==document.body){if(set.has(n))return n;n=n.parentElement;}return null;
};
document.body.classList.add('et-si11-page-103179');

/* Upgrade the native module kicker only; the ERP shell/header remains native. */
Array.from(document.querySelectorAll('*')).filter(el=>el.children.length===0).forEach(el=>{
    const t=norm(el.textContent);
    if(t.includes('sales invoice')&&t.includes('erp-09')){
        el.textContent='SALES INVOICE · ERP-11.3';
        el.classList.add('et-si11-kicker-103179');
    }
});

const accountingHeading=leafExact('Accounting Preview');
const accountingCard=nearestCard(accountingHeading);
const invoiceDate=leafExact('Invoice Date');
const reviewHeading=leafExact('Review Draft Invoice')||leafContains('Review Draft Invoice');
const reviewCard=nearestCard(reviewHeading||invoiceDate);
const anchorCard=reviewCard||accountingCard;
if(!anchorCard)return;
const topBoundary=anchorCard.getBoundingClientRect().top;

/* Hide a stale legacy source-resolution alert only when we have usable source/ticket data. */
if(Boolean(data.supported)||Number(data.ticketCount||0)>0){
    const stale=leafContains('No saved passenger-ticket source could be resolved');
    const alert=nearestCard(stale);
    if(alert&&alert.getBoundingClientRect().top<topBoundary)alert.classList.add('et-si11-legacy-alert-103179');
}

/* Native duplicate snapshot row signals are captured BEFORE inserting ERP-11 panels. */
const oldLineSignal=leafContains('customer-facing services copied from the booking')||leafExact('Invoice Lines')||Array.from(document.querySelectorAll('*')).find(el=>{
    if(el.children.length>8)return false;
    const t=norm(el.textContent);
    return t.includes('air_ticket')&&t.includes('per_ticket')&&t.includes('qty')&&t.includes('unit price')&&t.includes('discount');
});
const oldLineCard=nearestCard(oldLineSignal);
const oldPassengerCard=nearestCard(leafContains('snapshot captured when the invoice was created')||leafExact('Passengers'));

function snapshotPassengerNames(){
    const names=[];if(!oldPassengerCard)return names;
    Array.from(oldPassengerCard.querySelectorAll('strong,b,h4,h5,h6')).forEach(el=>{
        const value=String(el.textContent||'').replace(/\s+/g,' ').trim();
        if(value&&value.length<100&&!/passenger|snapshot|passport|dob|adult|child|infant/i.test(value)&&!names.includes(value))names.push(value);
    });
    return names;
}
function topPassengerCount(){
    const labels=Array.from(document.querySelectorAll('*')).filter(el=>el.children.length===0&&norm(el.textContent)==='passengers'&&el.getBoundingClientRect().top<topBoundary);
    for(const label of labels){
        let box=label.parentElement;
        for(let i=0;i<5&&box;i++){
            const values=Array.from(box.querySelectorAll('*')).filter(el=>el.children.length===0).map(el=>String(el.textContent||'').trim());
            const idx=values.findIndex(v=>norm(v)==='passengers');
            if(idx>=0){const count=values.slice(idx+1).find(v=>/^\d+$/.test(v));if(count!==undefined)return Number(count);}
            box=box.parentElement;
        }
    }
    return 0;
}
function fallbackTickets(){
    const all=String(document.body.innerText||'');
    const names=snapshotPassengerNames();
    const fares=Array.from(all.matchAll(/#?\s*\d+\s*[·:-]\s*(ADULT|CHILD|INFANT)/gi)).map(m=>String(m[1]||'ADULT').toUpperCase());
    const refs=Array.from(all.matchAll(/Ticket:\s*([^\s·]+).*?PNR\s*([^\s·]+)/gi)).map(m=>({ticket_number:String(m[1]||'').trim(),pnr:String(m[2]||'').trim()}));
    const count=Math.max(Number(data.ticketCount||0),topPassengerCount(),names.length,fares.length,refs.length);
    return Array.from({length:count},(_,i)=>({passenger_name:names[i]||('Passenger '+String(i+1)),fare_type:fares[i]||'ADULT',ticket_number:refs[i]?.ticket_number||'',pnr:refs[i]?.pnr||'',customer_sale:0}));
}
const normalizeFare=value=>{const fare=String(value||'ADULT').toUpperCase();if(fare.includes('INF'))return 'INFANT';if(fare.includes('CHD')||fare.includes('CHILD'))return 'CHILD';return 'ADULT';};
let tickets=Array.isArray(data.tickets)?data.tickets.filter(Boolean):[];
if(!tickets.length)tickets=fallbackTickets();
tickets=tickets.map(t=>({...t,fare_type:normalizeFare(t.fare_type),customer_sale:Number(t.customer_sale||0)}));
const paxMix={ADULT:0,CHILD:0,INFANT:0};tickets.forEach(t=>paxMix[t.fare_type]=Number(paxMix[t.fare_type]||0)+1);
let groups=Array.isArray(data.groups)?data.groups.filter(Boolean):[];
if(!groups.length&&tickets.length){
    const map=new Map();
    tickets.forEach(ticket=>{
        const rate=Number(ticket.customer_sale||0);const key=ticket.fare_type+'|'+rate.toFixed(2);
        if(!map.has(key))map.set(key,{fare_type:ticket.fare_type,quantity:0,rate:rate,total:0,tickets:[]});
        const group=map.get(key);group.quantity++;group.total+=rate;group.tickets.push(ticket);
    });
    const order={ADULT:1,CHILD:2,INFANT:3};groups=Array.from(map.values()).sort((a,b)=>((order[a.fare_type]||99)-(order[b.fare_type]||99))||(Number(a.rate||0)-Number(b.rate||0)));
}
const ticketCount=tickets.length||topPassengerCount();
const sourceTotal=groups.reduce((sum,g)=>sum+Number(g.total||0),0);
const profitability=data.profitability||{};
const products=Array.isArray(profitability.products)?profitability.products:[];
const invoiceTotal=Number(profitability.invoice_sale_total||0);
const totalCostComplete=Boolean(profitability.total_cost_complete);
const grossMarginComplete=Boolean(profitability.gross_margin_complete);
const grossMargin=Number(profitability.gross_margin||0);
const invoicePassengerCount=Number(profitability.passenger_count||ticketCount);
const invoicePaxMix=profitability.passenger_mix&&Number(profitability.passenger_count||0)>0?profitability.passenger_mix:paxMix;
const productNames=products.map(product=>String(product.product_name||'')).filter(Boolean).join(' · ');
const summaryCard=(label,value,sub,iconKey,valueClass='')=>'<div class="et-si11-summary-card-103179"><span class="et-si11-summary-icon-103179">'+icon(iconKey)+'</span><div><div class="et-si11-summary-label-103179">'+esc(label)+'</div><div class="et-si11-summary-value-103179 '+valueClass+'">'+esc(value)+'</div><div class="et-si11-summary-sub-103179">'+esc(sub)+'</div></div></div>';
const summary=document.createElement('section');summary.className='et-si11-summary-103179';summary.setAttribute('aria-label','Sales Invoice summary');
summary.innerHTML=summaryCard('Invoice Total',money(invoiceTotal),'Native Sales Invoice grand total','invoice')
    +summaryCard('Total Cost',totalCostComplete?money(profitability.total_cost):'Incomplete',totalCostComplete?'Resolved source-product costs':'One or more product costs are unavailable','cost')
    +summaryCard('Gross Margin',grossMarginComplete?money(grossMargin):'Incomplete',grossMarginComplete?'Invoice sale less resolved cost':'Complete cost authority is required','margin',grossMarginComplete?(grossMargin>0?'positive':grossMargin<0?'negative':''):'')
    +summaryCard('Passengers',String(invoicePassengerCount),String(invoicePaxMix.ADULT||0)+' Adult · '+String(invoicePaxMix.CHILD||0)+' Child · '+String(invoicePaxMix.INFANT||0)+' Infant','users')
    +summaryCard('Products',String(Number(profitability.product_count||0)),productNames||'Invoice product snapshots','package');
const nativeMetricLabels=['Invoice Total','Passengers','Service Lines','Accounting'];
const nativeMetricCards=nativeMetricLabels.map(label=>nearestCard(leafExact(label))).filter(card=>card&&card.getBoundingClientRect().top<topBoundary);
const nativeMetricParents=[...new Set(nativeMetricCards.map(card=>card.parentElement).filter(Boolean))];
if(nativeMetricParents.length===1&&nativeMetricCards.length>=3)nativeMetricParents[0].classList.add('et-si11-native-metrics-hidden-103179');
else nativeMetricCards.forEach(card=>card.classList.add('et-si11-native-metrics-hidden-103179'));
const topAccountingSignals=Array.from(document.querySelectorAll('*')).filter(el=>el.children.length===0&&['accounting','not posted'].includes(norm(el.textContent))&&el.getBoundingClientRect().top<topBoundary);
topAccountingSignals.forEach(signal=>{let current=signal.parentElement;for(let i=0;i<7&&current&&current!==document.body;i++){const text=norm(current.textContent);if(text.includes('accounting')&&text.includes('not posted')&&current.getBoundingClientRect().top<topBoundary){current.classList.add('et-si11-native-metrics-hidden-103179');break;}current=current.parentElement;}});
anchorCard.parentNode.insertBefore(summary,anchorCard);

if(reviewCard){
    reviewCard.classList.add('et-si11-card-103179','et-si11-review-103179');
    if(reviewHeading&&!reviewHeading.querySelector('.et-si11-title-icon-103179')){reviewHeading.classList.add('et-si11-title-with-icon-103179');reviewHeading.insertAdjacentHTML('afterbegin','<span class="et-si11-title-icon-103179">'+icon('invoice')+'</span>');}
    const genericTable=Array.from(reviewCard.querySelectorAll('table')).find(table=>{const t=norm(table.textContent);return t.includes('description')&&t.includes('qty')&&t.includes('unit price')&&t.includes('discount');});
    if(genericTable)genericTable.classList.add('et-si11-native-lines-103179');
    const save=Array.from(reviewCard.querySelectorAll('button,input[type="submit"]')).find(el=>{const t=norm(el.textContent||el.value);return t==='save draft'||t==='save invoice details';});
    if(save){if(save.tagName==='INPUT')save.value='Save Invoice Details';else save.textContent='Save Invoice Details';}

    const source=document.createElement('div');source.className='et-si11-source-103179 '+(sourceTotal>0?'ready':'');
    source.innerHTML='<div><div class="et-si11-source-title-103179">Air Ticket commercial source</div><div class="et-si11-source-note-103179">'+(sourceTotal>0?(String(ticketCount)+' saved passenger ticket(s) · '+money(sourceTotal)):'Saved ticket Customer Sale is currently PKR 0.00. Enter Adult / Child / Infant pricing in the booking, save ticket updates, then sync this Draft invoice.')+'</div></div><div class="et-si11-actions-103179">'+(data.bookingUrl!=='#'?'<a class="et-si11-btn-103179" href="'+esc(data.bookingUrl)+'">Edit Air Ticket Pricing</a>':'')+(data.draft?'<form method="POST" action="'+esc(data.syncUrl)+'" style="margin:0"><input type="hidden" name="_token" value="'+esc(data.csrf)+'"><button class="et-si11-btn-103179 primary" type="submit">Sync Draft Invoice</button></form>':'')+'</div>';
    reviewCard.appendChild(source);
}

const profitabilityPanel=document.createElement('section');profitabilityPanel.className='et-si11-panel-103179 et-si11-profitability-103179';
const productRows=products.map(product=>{
    const key=String(product.key||'product');
    const costResolved=Boolean(product.cost_resolved);
    const marginResolved=Boolean(product.margin_resolved);
    const margin=Number(product.margin||0);
    const marginClass=marginResolved?(margin>0?'positive':margin<0?'negative':'') : '';
    const unavailable='<span title="Product cost could not be resolved from the source booking.">—</span><span class="et-si11-cost-warning-103179">Product cost could not be resolved from the source booking.</span>';
    return '<div class="et-si11-profit-row-103179"><div class="et-si11-product-103179"><span class="et-si11-product-icon-103179 '+esc(key)+'">'+icon(product.icon_key||key)+'</span><span>'+esc(product.product_name||'Product')+'</span></div><div class="et-si11-profit-number-103179">'+money(product.sale_total)+'</div><div class="et-si11-profit-number-103179">'+(costResolved?money(product.cost_total):unavailable)+'</div><div class="et-si11-profit-number-103179 et-si11-margin-103179 '+marginClass+'">'+(marginResolved?money(margin):'—')+'</div></div>';
}).join('');
const totalMarginClass=grossMarginComplete?(grossMargin>0?'positive':grossMargin<0?'negative':''):'';
profitabilityPanel.innerHTML='<div class="et-si11-profit-head-103179"><div><div class="et-si11-profit-title-103179"><span class="et-si11-title-icon-103179">'+icon('margin')+'</span><span>Product Commercial Summary</span></div><div class="et-si11-profit-note-103179">Commercial visibility by product — sale, cost and margin.</div></div><span class="et-si11-reconcile-103179 '+(profitability.sale_reconciles?'':'warn')+'">'+(profitability.sale_reconciles?'Reconciled to invoice':'Invoice sale mismatch')+'</span></div><div class="et-si11-profit-body-103179"><div class="et-si11-profit-row-103179 header"><div>Product</div><div class="et-si11-profit-number-103179">Sale Total</div><div class="et-si11-profit-number-103179">Cost Total</div><div class="et-si11-profit-number-103179">Margin</div></div>'+productRows+'<div class="et-si11-profit-row-103179 total"><div>TOTAL</div><div class="et-si11-profit-number-103179">'+money(profitability.product_sale_total)+'</div><div class="et-si11-profit-number-103179">'+(totalCostComplete?money(profitability.total_cost):'Incomplete')+'</div><div class="et-si11-profit-number-103179 et-si11-margin-103179 '+totalMarginClass+'">'+(grossMarginComplete?money(grossMargin):'Incomplete')+'</div></div></div>';
if(reviewCard&&reviewCard.parentNode)reviewCard.insertAdjacentElement('afterend',profitabilityPanel);else if(accountingCard&&accountingCard.parentNode)accountingCard.parentNode.insertBefore(profitabilityPanel,accountingCard);

const commercial=document.createElement('section');commercial.className='et-si11-commercial-103179';
const grid=document.createElement('div');grid.className='et-si11-commercial-grid-103179';
const linesPanel=document.createElement('div');linesPanel.className='et-si11-panel-103179';
const linesHtml=groups.length?groups.map(group=>{
    const ticketHtml=(group.tickets||[]).map(ticket=>'<div class="et-si11-ticket-103179"><div><span class="et-si11-ticket-name-103179">'+esc(ticket.passenger_name||'Passenger')+'</span> — '+(ticket.ticket_number?'Ticket '+esc(ticket.ticket_number):'Ticket pending')+(ticket.pnr?' — PNR '+esc(ticket.pnr):'')+'</div></div>').join('');
    return '<article class="et-si11-line-103179"><div class="et-si11-line-top-103179"><div class="et-si11-line-title-103179 et-si11-product-103179"><span class="et-si11-product-icon-103179 air">'+icon('plane')+'</span><span>'+esc(group.fare_type)+' · Air Ticket</span></div><div class="et-si11-line-amount-103179">'+money(group.total)+'</div></div><div class="et-si11-line-metrics-103179"><div class="et-si11-line-metric-103179"><small>Qty</small><strong>'+Number(group.quantity||0).toLocaleString()+'</strong></div><div class="et-si11-line-metric-103179"><small>Unit Price</small><strong>'+money(group.rate)+'</strong></div><div class="et-si11-line-metric-103179"><small>Discount</small><strong>PKR 0</strong></div></div>'+(ticketHtml?'<div class="et-si11-ticket-box-103179">'+ticketHtml+'</div>':'')+'</article>';
}).join(''):'<div style="font-size:10px;color:#718096">No commercial ticket groups are available yet.</div>';
linesPanel.innerHTML='<div class="et-si11-panel-head-103179"><div class="et-si11-panel-title-103179">Air Ticket Commercial Lines</div><div class="et-si11-panel-note-103179">Grouped by fare type + customer rate.</div></div><div class="et-si11-panel-body-103179">'+linesHtml+'</div>';

const paxPanel=document.createElement('div');paxPanel.className='et-si11-panel-103179';
const farePresentation={ADULT:{label:'Adult',className:'air'},CHILD:{label:'Child',className:'transport'},INFANT:{label:'Infant',className:'visa'}};
const paxRows=Object.entries(farePresentation).map(([fare,presentation])=>{const fareTickets=tickets.filter(ticket=>ticket.fare_type===fare);const refs=fareTickets.length?'<div class="et-si11-ticket-refs-103179">'+fareTickets.map(ticket=>{const reference=String(ticket.ticket_number||'').trim();return '<span class="et-si11-ticket-ref-103179 '+(reference?'':'pending')+'" title="'+esc(reference?'Ticket '+reference:'Ticket pending')+'">'+esc(reference||'Pending')+'</span>';}).join('')+'</div>':'—';return '<tr><td><span class="et-si11-product-103179"><span class="et-si11-product-icon-103179 '+presentation.className+'">'+icon('users')+'</span>'+presentation.label+'</span></td><td>'+String(fareTickets.length)+'</td><td>'+refs+'</td></tr>';}).join('');
paxPanel.innerHTML='<div class="et-si11-panel-head-103179"><div class="et-si11-panel-title-103179">Passenger / Ticket Summary</div><div class="et-si11-panel-note-103179">Current saved-ticket source with invoice snapshot fallback.</div></div><div class="et-si11-panel-body-103179"><div class="et-si11-pax-hero-103179"><div><div class="et-si11-pax-count-103179">'+String(ticketCount)+'</div><div class="et-si11-pax-caption-103179">Saved passenger tickets</div></div><div class="et-si11-pills-103179"><span class="et-si11-pill-103179">'+String(paxMix.ADULT)+' Adult</span><span class="et-si11-pill-103179">'+String(paxMix.CHILD)+' Child</span><span class="et-si11-pill-103179">'+String(paxMix.INFANT)+' Infant</span></div></div><table class="et-si11-table-103179"><thead><tr><th>Type</th><th>Count</th><th>Tickets</th></tr></thead><tbody>'+paxRows+'<tr><td><strong>Total</strong></td><td><strong>'+String(ticketCount)+'</strong></td><td><strong>'+String(tickets.filter(ticket=>ticket.ticket_number).length)+' ticket(s)</strong></td></tr></tbody></table></div>';
grid.appendChild(linesPanel);grid.appendChild(paxPanel);commercial.appendChild(grid);

if(profitabilityPanel.parentNode)profitabilityPanel.insertAdjacentElement('afterend',commercial);else if(accountingCard&&accountingCard.parentNode)accountingCard.parentNode.insertBefore(commercial,accountingCard);else if(reviewCard)reviewCard.insertAdjacentElement('afterend',commercial);

/* Collapse the old Invoice Lines / Passengers native snapshot row. */
let oldRow=commonAncestor(oldLineCard,oldPassengerCard);
if(oldRow&&(oldRow===reviewCard||oldRow===accountingCard||oldRow.contains(reviewCard)||oldRow.contains(accountingCard)||oldRow.contains(commercial)))oldRow=null;
if(!oldRow&&oldLineCard){let candidate=oldLineCard.parentElement;for(let i=0;i<5&&candidate&&candidate!==document.body;i++){const t=norm(candidate.textContent);const safe=!candidate.contains(reviewCard)&&!candidate.contains(accountingCard)&&!candidate.contains(commercial)&&!t.includes('accounting preview')&&!t.includes('workflow')&&!t.includes('activity');if(safe&&candidate.getBoundingClientRect().width>=oldLineCard.getBoundingClientRect().width)oldRow=candidate;candidate=candidate.parentElement;}}
if(oldRow)oldRow.classList.add('et-si11-native-duplicate-103179');else [oldLineCard,oldPassengerCard].filter(Boolean).forEach(card=>{if(card!==reviewCard&&card!==accountingCard&&!card.contains(commercial))card.classList.add('et-si11-native-duplicate-103179');});

/* Accounting / Workflow / Activity retain native data and actions; only presentation changes. */
if(accountingCard){
    accountingCard.classList.add('et-si11-card-103179','et-si11-accounting-103179');
    if(accountingHeading)accountingHeading.textContent='Accounting Preview (Journal Lines)';
    Array.from(accountingCard.querySelectorAll('tr')).forEach(row=>{const text=norm(row.textContent);const first=row.querySelector('td');if(!first)return;let key='';if(text.includes('air ticket'))key='air';else if(text.includes('hotel'))key='hotel';else if(text.includes('transport'))key='transport';else if(text.includes('visa'))key='visa';else if(text.includes('customer receivable'))key='receivable';if(!key)return;row.classList.add('et-si11-account-row-103179',key);if(!first.querySelector('.et-si11-account-icon-103179'))first.insertAdjacentHTML('afterbegin','<span class="et-si11-account-icon-103179">'+icon(key==='receivable'?'calculator':key)+'</span>');});
    if(!accountingCard.querySelector('.et-si11-account-note-103179'))accountingCard.insertAdjacentHTML('beforeend','<div class="et-si11-account-note-103179">Customer receivable remains based on sale total. Product costs are shown for profitability visibility only and do not alter this journal.</div>');
}
const workflowCard=nearestCard(leafExact('Workflow'));
const activityCard=nearestCard(leafExact('Activity'));
if(workflowCard){
    workflowCard.classList.add('et-si11-card-103179','et-si11-workflow-103179');
    const raw=String(data.status||'draft').toLowerCase().replace(/\s+/g,'_').replace(/-/g,'_');
    const idx=raw.includes('post')?3:raw.includes('approv')&&!raw.includes('pending')?2:raw.includes('pending')||raw.includes('submit')?1:0;
    const steps=['Draft','Pending Approval','Approved','Posted'];
    const line=document.createElement('div');line.className='et-si11-timeline-103179';
    line.innerHTML=steps.map((label,i)=>'<div class="et-si11-step-103179 '+(i<idx?'done ':i===idx?'current ':'')+'"><div class="et-si11-step-dot-103179">'+String(i+1)+'</div><div class="et-si11-step-label-103179">'+label+'</div></div>').join('');
    const heading=leafExact('Workflow');
    if(heading&&heading.parentElement)heading.parentElement.insertAdjacentElement('afterend',line);else workflowCard.prepend(line);
}
if(activityCard)activityCard.classList.add('et-si11-card-103179','et-si11-activity-103179');

/*
 * ERP-11.3.57 workflow integrity presentation.
 * Backend middleware is authoritative; this makes the reason visible before
 * the user attempts Approve/Post.
 */
if(data.needsSync&&!data.draft){
    const warning=document.createElement('div');
    warning.className='et-si11-integrity-warning-103179';

    const normalizedStatus=String(data.status||'').toLowerCase().replace(/\s+/g,'_').replace(/-/g,'_');
    const isPosted=normalizedStatus.includes('post');

    warning.textContent=isPosted
        ? 'Legacy commercial snapshot reconciled for display from the authoritative saved booking-ticket source. This invoice is already Posted and immutable; its posted General Ledger journal is not changed.'
        : 'Commercial synchronization required. This Air Ticket invoice cannot be Approved or Posted until its native invoice monetary total matches the saved booking ticket source.';

    const metrics=Array.from(document.querySelectorAll('.et-si11-metric-103179'));
    const firstMetric=metrics.length?metrics[0]:null;
    const metricRow=firstMetric?firstMetric.parentElement:null;

    if(metricRow&&metricRow.parentNode){
        metricRow.parentNode.insertBefore(warning,metricRow);
    }else if(workflowCard&&workflowCard.parentNode){
        workflowCard.parentNode.insertBefore(warning,workflowCard);
    }

    if(!isPosted&&workflowCard){
        Array.from(workflowCard.querySelectorAll('button,a,input[type="submit"]')).forEach(el=>{
            const t=norm(el.textContent||el.value);
            if(t.includes('approve')||t.includes('post invoice')||t==='post'){
                el.classList.add('et-si11-workflow-locked-103179');
                el.setAttribute('aria-disabled','true');

                if(el.tagName==='BUTTON'||el.tagName==='INPUT'){
                    el.disabled=true;
                }
            }
        });
    }
}

if(workflowCard&&activityCard&&workflowCard.parentNode===activityCard.parentNode){
    const parent=workflowCard.parentNode;const bottom=document.createElement('div');bottom.className='et-si11-bottom-grid-103179';parent.insertBefore(bottom,workflowCard);bottom.appendChild(workflowCard);bottom.appendChild(activityCard);
}
})();
</script>
HTML;

        if (str_contains($content, '</body>')) {
            $content = str_replace(
                '</body>',
                $injection.'</body>',
                $content
            );
        } else {
            $content .= $injection;
        }

        $response->setContent($content);

        return $response;
    }

    private function nativeInvoiceTotal(Model $invoice): float
    {
        foreach (['grand_total', 'total_amount', 'net_total', 'invoice_total', 'total', 'amount'] as $field) {
            $value = $invoice->getAttribute($field);
            if ($value !== null && $value !== '' && is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return 0.0;
    }
}
