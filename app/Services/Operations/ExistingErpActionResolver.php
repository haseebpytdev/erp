<?php

namespace App\Services\Operations;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

class ExistingErpActionResolver
{
    public function __construct(
        private readonly NativeSalesInvoiceInspector $salesInvoices,
        private readonly GroupUmrahCommercialAmendmentService $amendments,
    ) {}

    public function links(int $bookingId): array
    {
        $invoiceAction = $this->salesInvoices->action($bookingId);
        $invoiceWorkflow = $this->salesInvoices->workflowAction($bookingId);
        $amendmentState = $this->amendments->state($bookingId);
        $pendingAmendment = $amendmentState['latest_pending'] ?? null;

        if ($pendingAmendment) {
            $pendingAction = (string) ($pendingAmendment['accounting_action'] ?? '');

            if ($pendingAction === 'supplementary_invoice') {
                $invoiceAction = $this->salesInvoices->supplementaryAction(
                    $bookingId,
                    (int) ($pendingAmendment['id'] ?? 0)
                );
            } elseif ($pendingAction === 'revise_existing_invoice') {
                $current = $this->salesInvoices->action($bookingId);
                $number = trim((string) (($current['invoice']['number'] ?? '') ?: ''));

                $invoiceAction = [
                    ...$current,
                    'label' => $number !== '' ? 'Revise '.$number : 'Revise Sales Invoice',
                    'mode' => 'revise',
                ];
            }
        }

        return [
            'booking_workspace_url' => $this->groupUmrahWorkspaceUrl($bookingId),
            'voucher_preview_url' => route(
                'operations.bookings.group-umrah-voucher.show',
                ['booking' => $bookingId]
            ),
            'voucher_number' => app(GroupUmrahDocumentNumberService::class)->voucherNumber($bookingId),

            'sales_invoice_url' => $invoiceAction['url'] ?? null,
            'sales_invoice_label' => $invoiceAction['label'] ?? 'Create Sales Invoice',
            'sales_invoice_mode' => $invoiceAction['mode'] ?? 'create',
            'invoice_workflow_status' => $invoiceWorkflow['status'] ?? 'not_created',
            'invoice_workflow_status_label' => $invoiceWorkflow['status_label'] ?? 'Not Created',
            'invoice_workflow_action' => $invoiceWorkflow['action'] ?? null,
            'commercial_amendment_pending' => (bool) $pendingAmendment,
            'commercial_amendment_action' => $pendingAmendment['accounting_action'] ?? null,

            'supplier_payable_url' => $this->find($bookingId, [
                'vendor-bill', 'vendor_bill', 'supplier-bill', 'supplier_bill',
                'payable', 'purchase-invoice',
            ]),
            'receipt_url' => $this->find($bookingId, [
                'receipt-voucher', 'receipt_voucher', 'receipt',
            ]),
            'payment_url' => $this->find($bookingId, [
                'payment-voucher', 'payment_voucher', 'vendor-payment', 'payment',
            ]),
        ];
    }

    private function groupUmrahWorkspaceUrl(int $bookingId): string
    {
        return route(
            'operations.bookings.group-package-unified.edit',
            ['booking' => $bookingId]
        );
    }

    private function find(int $bookingId, array $keywords): ?string
    {
        $candidates = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = strtolower($route->uri());
            $name = strtolower((string) $route->getName());
            $haystack = $uri.' '.$name;

            if (str_contains($haystack, 'group-package-bookings')) {
                continue;
            }

            $hit = false;
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, strtolower($keyword))) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                continue;
            }

            $url = $this->urlFor($route, $bookingId);
            if (! $url) {
                continue;
            }

            $score = 0;
            if (str_contains($name, 'create')) $score += 50;
            if (str_contains($uri, 'operations/bookings')) $score += 20;
            if (str_contains($name, 'show')) $score += 10;

            $candidates[] = [$score, $url];
        }

        if (! $candidates) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return $candidates[0][1];
    }

    private function urlFor(LaravelRoute $route, int $bookingId): ?string
    {
        try {
            $parameters = method_exists($route, 'parameterNames') ? $route->parameterNames() : [];
            $values = [];

            foreach ($parameters as $parameter) {
                $key = strtolower($parameter);

                if (str_contains($key, 'booking') || $key === 'id') {
                    $values[$parameter] = $bookingId;
                    continue;
                }

                if (! str_contains($route->uri(), '{'.$parameter.'?}')) {
                    return null;
                }
            }

            if ($route->getName()) {
                $url = route($route->getName(), $values);
            } else {
                $uri = $route->uri();

                foreach ($values as $parameter => $value) {
                    $uri = str_replace(
                        ['{'.$parameter.'}', '{'.$parameter.'?}'],
                        (string) $value,
                        $uri
                    );
                }

                $uri = preg_replace('#/\{[^}]+\?\}#', '', $uri) ?: $uri;
                $url = url('/'.ltrim($uri, '/'));
            }

            if (! $values) {
                $separator = str_contains($url, '?') ? '&' : '?';
                $url .= $separator.'booking_id='.$bookingId;
            }

            return $url;
        } catch (\Throwable) {
            return null;
        }
    }
}
