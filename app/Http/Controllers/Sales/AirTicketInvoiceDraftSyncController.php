<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AirTicketInvoiceDraftSyncController extends Controller
{
    public function __construct(
        private readonly AirTicketInvoiceCommercialSyncService $sync,
    ) {}

    public function __invoke(
        Request $request,
        mixed $invoice
    ): RedirectResponse {
        $result = $this->sync->sync(
            $invoice,
            null
        );

        $savedInvoice = $result['invoice'];

        $message =
            'Draft Sales Invoice synchronized from '
            .$result['ticket_count']
            .' saved passenger ticket(s).';

        if (app('router')->has('sales.invoices.show')) {
            return redirect()
                ->route(
                    'sales.invoices.show',
                    ['invoice' => $savedInvoice->getKey()]
                )
                ->with('success', $message);
        }

        return redirect()
            ->to('/sales/invoices/'.$savedInvoice->getKey())
            ->with('success', $message);
    }
}
