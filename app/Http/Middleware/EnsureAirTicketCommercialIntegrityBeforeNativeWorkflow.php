<?php

namespace App\Http\Middleware;

use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ERP-11.3.81
 *
 * Commercial-only guard around the HOST NATIVE Sales Invoice workflow.
 *
 * This middleware never writes status/draft/workflow fields and never replaces
 * the native Submit/Approve/Post controller. Its only responsibilities are:
 *
 * - Draft Submit: synchronize stale Air commercial lines/snapshot metadata
 *   before handing the SAME request to the native controller.
 * - Approve/Post: block only a real customer-commercial mismatch.
 * - Passenger-ticket link metadata alone is non-blocking.
 */
final class EnsureAirTicketCommercialIntegrityBeforeNativeWorkflow
{
    public function __construct(
        private readonly AirTicketInvoiceCommercialSyncService $sync,
        private readonly NativeSalesInvoiceInspector $invoices,
    ) {}

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $invoiceId=$this->invoiceId($request);
        $workflow=$this->workflow($request);

        if ($invoiceId<=0 || $workflow===null) {
            return $next($request);
        }

        try {
            $snapshot=$this->sync->snapshot($invoiceId);
        } catch (Throwable $error) {
            Log::warning(
                'ERP-11.3.81 could not inspect Air Ticket commercial integrity; native workflow remains authoritative.',
                [
                    'invoice_id'=>$invoiceId,
                    'workflow'=>$workflow,
                    'message'=>$error->getMessage(),
                ]
            );

            return $next($request);
        }

        if (!($snapshot['supported']??false)) {
            return $next($request);
        }

        $sourceTotal=(float)($snapshot['total']??0);

        if ($sourceTotal<=0) {
            return $this->blocked(
                $invoiceId,
                'Air Ticket customer sale is PKR 0.00. Update the booking ticket pricing before continuing the invoice workflow.'
            );
        }

        $commercialMismatch=(bool)($snapshot['needs_sync']??false);
        $linkMetadataStale=(bool)($snapshot['link_sync_needed']??false);

        if ($workflow==='submit' && ($commercialMismatch || $linkMetadataStale)) {
            try {
                /*
                 * The invoice is still Draft here. Native Air sync updates only
                 * customer commercial presentation/lines/header and snapshot
                 * links; it does not submit/approve/post the invoice.
                 */
                $this->sync->sync(
                    $invoiceId,
                    $request
                );

                $after=$this->sync->snapshot($invoiceId);

                if ((bool)($after['needs_sync']??true)) {
                    return $this->blocked(
                        $invoiceId,
                        'Air Ticket commercial values could not be synchronized with the saved booking ticket source. The invoice remains Draft.'
                    );
                }
            } catch (Throwable $error) {
                Log::warning(
                    'ERP-11.3.81 blocked native Submit because Air commercial synchronization failed.',
                    [
                        'invoice_id'=>$invoiceId,
                        'message'=>$error->getMessage(),
                    ]
                );

                return $this->blocked(
                    $invoiceId,
                    'Air Ticket commercial synchronization failed before Submit for Approval: '
                    .mb_substr($error->getMessage(),0,500)
                );
            }
        } elseif (
            in_array($workflow,['approve','post'],true)
            && $commercialMismatch
        ) {
            return $this->blocked(
                $invoiceId,
                'Commercial synchronization required. This Air Ticket invoice cannot be '
                .($workflow==='approve'?'Approved':'Posted')
                .' until its customer commercial amount and invoice lines match the saved booking ticket source.'
            );
        }

        /*
         * Critical: native workflow request continues untouched.
         */
        return $next($request);
    }

    private function blocked(
        int $invoiceId,
        string $message
    ): Response {
        $url=$this->invoices->nativeInvoiceUrl($invoiceId);

        if (!is_string($url) || trim($url)==='') {
            $url=url('/sales/invoices/'.$invoiceId);
        }

        return redirect()
            ->to($url)
            ->with('error',$message)
            ->withErrors(['workflow'=>$message]);
    }

    private function invoiceId(Request $request): int
    {
        foreach ([
            'invoice',
            'sales_invoice',
            'salesInvoice',
            'id',
        ] as $parameter) {
            $value=$request->route($parameter);

            if (
                is_object($value)
                && method_exists($value,'getKey')
                && (int)$value->getKey()>0
            ) {
                return (int)$value->getKey();
            }

            if (is_scalar($value) && (int)$value>0) {
                return (int)$value;
            }
        }

        foreach ((array)($request->route()?->parameters()??[]) as $value) {
            if (
                is_object($value)
                && method_exists($value,'getKey')
                && (int)$value->getKey()>0
            ) {
                return (int)$value->getKey();
            }

            if (is_scalar($value) && (int)$value>0) {
                return (int)$value;
            }
        }

        return 0;
    }

    private function workflow(Request $request): ?string
    {
        $name=strtolower((string)($request->route()?->getName()??''));
        $action=strtolower((string)($request->route()?->getActionName()??''));
        $uri=strtolower((string)($request->route()?->uri()??''));
        $haystack=$name.' '.$action.' '.$uri;

        if (str_contains($haystack,'submit')) return 'submit';
        if (str_contains($haystack,'approv')) return 'approve';
        if (str_contains($haystack,'post')) return 'post';

        return null;
    }
}
