<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SafeSalesInvoiceDraftUpdateRecovery;
use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * ERP-10.31.72
 *
 * Deterministic Sales Invoice Draft update endpoint.
 * Never calls the defective native SalesInvoiceService::updateDraft().
 */
class SalesInvoiceDraftUpdateBridgeController extends Controller
{
    public function __construct(
        private readonly SafeSalesInvoiceDraftUpdateRecovery $recovery,
        private readonly AirTicketInvoiceCommercialSyncService $airTicketSync,
    ) {}

    public function update(Request $request, mixed $invoice): RedirectResponse
    {
        $nativeFormRequest = $this->nativeUpdateFormRequestClass();

        if ($nativeFormRequest !== null) {
            /** @var FormRequest $validatedRequest */
            $validatedRequest = app($nativeFormRequest);
            $validatedRequest->validated();
        }

        /*
         * Air Ticket commercial amounts are owned by Saved Passenger Tickets.
         * The generic invoice line editor is never authoritative for Air Ticket
         * Qty / Unit Price / Discount.
         */
        if ($this->airTicketSync->supports($invoice)) {
            $result = $this->airTicketSync->sync(
                $invoice,
                $request
            );
        } else {
            $result = $this->recovery->recover(
                $request,
                $invoice
            );
        }

        $savedInvoice = $result['invoice'];

        Log::info(
            'ERP-10.31.72 Sales Invoice Draft saved through source-aware deterministic update bridge.',
            [
                'invoice_id' => $savedInvoice->getKey(),
                'line_count' => $result['lines'],
                'invoice_total' => $result['total'],
                'user_id' => $request->user()?->id,
                'native_form_request' => $nativeFormRequest,
            ]
        );

        if (app('router')->has('sales.invoices.show')) {
            return redirect()
                ->route(
                    'sales.invoices.show',
                    ['invoice' => $savedInvoice->getKey()]
                )
                ->with(
                    'success',
                    'Sales Invoice Draft saved.'
                );
        }

        return redirect()
            ->to('/sales/invoices/'.$savedInvoice->getKey())
            ->with(
                'success',
                'Sales Invoice Draft saved.'
            );
    }

    private function nativeUpdateFormRequestClass(): ?string
    {
        $nativeController =
            \App\Http\Controllers\Sales\SalesInvoiceController::class;

        if (
            ! class_exists($nativeController)
            || ! method_exists($nativeController, 'update')
        ) {
            return null;
        }

        try {
            $method = new ReflectionMethod(
                $nativeController,
                'update'
            );

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (
                    ! $type instanceof ReflectionNamedType
                    || $type->isBuiltin()
                ) {
                    continue;
                }

                $class = $type->getName();

                if (
                    class_exists($class)
                    && is_subclass_of(
                        $class,
                        FormRequest::class
                    )
                ) {
                    return $class;
                }
            }
        } catch (Throwable) {
        }

        return null;
    }
}
