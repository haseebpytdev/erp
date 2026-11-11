<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use App\Services\Sales\GuardedDraftSalesInvoiceReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class DraftSalesInvoiceReconciliationController extends Controller
{
    public function __construct(private readonly GuardedDraftSalesInvoiceReconciliationService $reconciliation) {}

    public function preview(Request $request, SalesInvoice $invoice): View
    {
        $preview = $this->reconciliation->preview((int) $invoice->id, $request->user());
        return view('sales.invoices.draft-reconciliation-preview', compact('invoice', 'preview'));
    }

    public function reconcile(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'preview_hash' => ['required','string','size:64'],
            'reason' => ['required','string','min:5','max:1000'],
        ]);
        $result = $this->reconciliation->reconcile((int) $invoice->id, $request->user(), $data['preview_hash'], $data['reason']);
        return redirect()->route('sales.invoices.show', $invoice)->with('status', $result['inserted'] > 0
            ? 'Draft invoice reconciled successfully. No accounting entry was created.'
            : 'Draft invoice is already reconciled. No changes were required.');
    }
}
