<?php

namespace App\Http\Middleware;

use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ERP-11.3.65 — Air Ticket Sales Invoice workflow transition integrity.
 *
 * Production issue:
 * - Air Draft commercial values were correct (e.g. PKR 110,000)
 * - Submit for Approval reloaded the same Draft page
 * - no visible native reason and no status transition
 *
 * Root integration gap:
 * Group Umrah already reconciled the exact native Sales Invoice Draft contract
 * before submit, but the normal Air invoice workflow guard did not.
 *
 * Contract:
 * - Draft -> Submit:
 *     synchronize Air commercial values if needed
 *     reconcile the exact native Draft contract
 *     call the native workflow endpoint
 *     verify the status actually moved
 *
 * - Pending Approval -> Approve
 * - Approved -> Post
 *     require commercial integrity, call native action and verify transition
 *
 * A native redirect is never treated as success by itself.
 */
class GuardAirTicketSalesInvoiceWorkflow
{
    public function __construct(
        private readonly AirTicketInvoiceCommercialSyncService $sync,
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $invoiceId = $this->invoiceId($request);
        $workflow = $this->workflow($request);

        if ($invoiceId <= 0 || $workflow === null) {
            return $next($request);
        }

        try {
            $snapshot = $this->sync->snapshot($invoiceId);
        } catch (Throwable $e) {
            Log::warning(
                'ERP-11.3.65 could not inspect Air Ticket Sales Invoice before workflow transition.',
                [
                    'invoice_id' => $invoiceId,
                    'workflow' => $workflow,
                    'message' => $e->getMessage(),
                ]
            );

            return $next($request);
        }

        if (! ($snapshot['supported'] ?? false)) {
            return $next($request);
        }

        $beforeStatus = $this->snapshotStatus($snapshot);
        $needsSync = (bool) ($snapshot['needs_sync'] ?? false);
        $sourceTotal = (float) ($snapshot['total'] ?? 0);

        if ($sourceTotal <= 0) {
            return $this->blocked(
                $invoiceId,
                'Air Ticket customer sale is PKR 0.00. Update the booking ticket pricing before continuing the invoice workflow.'
            );
        }

        if ($workflow === 'submit') {
            if ($needsSync) {
                try {
                    $this->sync->sync($invoiceId, $request);

                    $afterSync = $this->sync->snapshot($invoiceId);

                    if (
                        ! ($afterSync['supported'] ?? false)
                        || (bool) ($afterSync['needs_sync'] ?? true)
                    ) {
                        return $this->blocked(
                            $invoiceId,
                            'Air Ticket commercial values could not be synchronized. Keep the invoice Draft and review the booking pricing.'
                        );
                    }
                } catch (Throwable $e) {
                    Log::warning(
                        'ERP-11.3.65 blocked Air Ticket Sales Invoice Submit because commercial synchronization failed.',
                        [
                            'invoice_id' => $invoiceId,
                            'message' => $e->getMessage(),
                        ]
                    );

                    return $this->blocked(
                        $invoiceId,
                        'Air Ticket commercial synchronization failed. The invoice remains Draft and was not submitted for approval.'
                    );
                }
            }

        } elseif (
            in_array($workflow, ['approve', 'post'], true)
            && $needsSync
        ) {
            return $this->blocked(
                $invoiceId,
                $workflow === 'approve'
                    ? 'This Air Ticket invoice is not commercially synchronized and cannot be approved. Return it to Draft / synchronize it before approval.'
                    : 'This Air Ticket invoice is not commercially synchronized and cannot be posted.'
            );
        }

        /*
         * Native controller remains authoritative.
         */
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            Log::error(
                'ERP-11.3.65 native Air Sales Invoice workflow action threw an exception.',
                [
                    'invoice_id' => $invoiceId,
                    'workflow' => $workflow,
                    'before_status' => $beforeStatus,
                    'message' => $e->getMessage(),
                ]
            );

            $message = 'The native Sales Invoice '.$this->actionLabel($workflow)
                .' action failed: '.mb_substr($e->getMessage(), 0, 700);

            return $this->blocked($invoiceId, $message);
        }

        /*
         * Never infer success from HTTP 302 / page reload. Re-read the native
         * invoice and prove its status moved to the expected next state.
         */
        try {
            $afterSnapshot = $this->sync->snapshot($invoiceId);
            $afterStatus = $this->snapshotStatus($afterSnapshot);
        } catch (Throwable $e) {
            Log::warning(
                'ERP-11.3.65 could not verify Air Sales Invoice status after native workflow action.',
                [
                    'invoice_id' => $invoiceId,
                    'workflow' => $workflow,
                    'message' => $e->getMessage(),
                ]
            );

            return $response;
        }

        if ($this->transitionSucceeded($workflow, $beforeStatus, $afterStatus)) {
            return $response;
        }

        $nativeFailure = $this->nativeFlashedFailure($request);
        $message = $nativeFailure !== ''
            ? $nativeFailure
            : 'The native Sales Invoice '.$this->actionLabel($workflow)
                .' action returned without changing the invoice status.';

        $message .= ' [Status remained '
            .($afterStatus !== '' ? $afterStatus : 'unknown')
            .'; before '
            .($beforeStatus !== '' ? $beforeStatus : 'unknown')
            .']';

        Log::warning(
            'ERP-11.3.65 rejected silent Air Sales Invoice workflow reload because status did not transition.',
            [
                'invoice_id' => $invoiceId,
                'workflow' => $workflow,
                'before_status' => $beforeStatus,
                'after_status' => $afterStatus,
                'native_failure' => $nativeFailure,
            ]
        );

        return $this->blocked($invoiceId, $message);
    }

    private function transitionSucceeded(
        string $workflow,
        string $before,
        string $after
    ): bool {
        if ($after === '' || $after === $before) {
            return false;
        }

        return match ($workflow) {
            'submit' => in_array(
                $after,
                [
                    'pending',
                    'pending_approval',
                    'pendingapproval',
                    'submitted',
                    'pending_review',
                    'awaiting_approval',
                    'approved',
                    'posted',
                    'posted_to_gl',
                ],
                true
            ),
            'approve' => in_array(
                $after,
                [
                    'approved',
                    'authorized',
                    'authorised',
                    'posted',
                    'posted_to_gl',
                ],
                true
            ),
            'post' => in_array(
                $after,
                [
                    'posted',
                    'posted_to_gl',
                    'final',
                    'finalized',
                    'completed',
                ],
                true
            ),
            default => false,
        };
    }

    private function snapshotStatus(array $snapshot): string
    {
        $invoice = $snapshot['invoice'] ?? null;

        if (is_object($invoice) && method_exists($invoice, 'refresh')) {
            try {
                $invoice->refresh();
            } catch (Throwable) {
            }
        }

        $status = is_object($invoice) && method_exists($invoice, 'getAttribute')
            ? (string) (
                $invoice->getAttribute('status')
                ?? $invoice->getAttribute('invoice_status')
                ?? ''
            )
            : '';

        return $this->normalizeStatus($status);
    }

    private function normalizeStatus(string $status): string
    {
        return str_replace(
            [' ', '-'],
            '_',
            strtolower(trim($status))
        );
    }


    private function nativeFlashedFailure(Request $request): string
    {
        try {
            if (! $request->hasSession()) {
                return '';
            }

            $session = $request->session();

            foreach (['error', 'warning', 'message'] as $key) {
                $value = $session->get($key);

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            $errors = $session->get('errors');

            if (is_object($errors) && method_exists($errors, 'getBag')) {
                $bag = $errors->getBag('default');

                if ($bag && method_exists($bag, 'all')) {
                    $all = $bag->all();

                    if ($all) {
                        return implode(
                            ' | ',
                            array_map(
                                static fn ($value): string => trim((string) $value),
                                $all
                            )
                        );
                    }
                }
            }
        } catch (Throwable) {
        }

        return '';
    }

    private function actionLabel(string $workflow): string
    {
        return match ($workflow) {
            'submit' => 'Submit for Approval',
            'approve' => 'Approve',
            'post' => 'Post',
            default => ucfirst($workflow),
        };
    }

    private function blocked(
        int $invoiceId,
        string $message
    ): Response {
        $url = app('router')->has('sales.invoices.show')
            ? route('sales.invoices.show', ['invoice' => $invoiceId])
            : url('/sales/invoices/'.$invoiceId);

        return redirect()
            ->to($url)
            ->with('error', $message);
    }

    private function invoiceId(Request $request): int
    {
        foreach ([
            'invoice',
            'sales_invoice',
            'salesInvoice',
            'id',
        ] as $parameter) {
            $value = $request->route($parameter);

            if (
                is_object($value)
                && method_exists($value, 'getKey')
            ) {
                $id = (int) $value->getKey();

                if ($id > 0) {
                    return $id;
                }
            }

            if (is_scalar($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        if (
            preg_match(
                '#/sales/invoices/(\\d+)/(?:submit|approve|post)(?:[/?#]|$)#i',
                '/'.ltrim($request->path(), '/'),
                $matches
            ) === 1
        ) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
    }

    private function workflow(Request $request): ?string
    {
        $name = strtolower((string) ($request->route()?->getName() ?? ''));
        $action = strtolower((string) ($request->route()?->getActionName() ?? ''));
        $uri = strtolower((string) ($request->route()?->uri() ?? ''));
        $haystack = $name.' '.$action.' '.$uri;

        foreach (['submit', 'approve', 'post'] as $workflow) {
            if (str_contains($haystack, $workflow)) {
                return $workflow;
            }
        }

        return null;
    }
}
