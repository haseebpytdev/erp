<?php

namespace App\Http\Middleware;

use App\Services\Sales\SafeSalesInvoiceDraftUpdateRecovery;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ERP-10.31.72
 *
 * Wrap only the native Sales Invoice Draft update route. Native controller +
 * validation run first. Recovery is used only for the historical exact `$fy`
 * service-scope defect; every other exception remains untouched.
 */
class RecoverSalesInvoiceDraftUpdate
{
    public function __construct(
        private readonly SafeSalesInvoiceDraftUpdateRecovery $recovery,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            if (! $this->isHistoricalFiscalYearScopeBug($e)) {
                throw $e;
            }

            $routeInvoice = $request->route('invoice');

            Log::warning('ERP-10.31.72 recovered native Sales Invoice Draft update from historical $fy scope defect.', [
                'invoice_id' => is_object($routeInvoice) && method_exists($routeInvoice, 'getKey')
                    ? $routeInvoice->getKey()
                    : $routeInvoice,
                'user_id' => $request->user()?->id,
                'native_exception' => get_class($e),
                'native_file' => basename($e->getFile()),
                'native_line' => $e->getLine(),
            ]);

            $result = $this->recovery->recover(
                $request,
                $routeInvoice
            );

            $invoice = $result['invoice'];
            $message = 'Sales Invoice Draft saved.';

            if (app('router')->has('sales.invoices.show')) {
                return redirect()
                    ->route('sales.invoices.show', ['invoice' => $invoice->getKey()])
                    ->with('success', $message);
            }

            return redirect()
                ->to('/sales/invoices/'.$invoice->getKey())
                ->with('success', $message);
        }
    }

    private function isHistoricalFiscalYearScopeBug(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        $file = strtolower(str_replace('\\', '/', $e->getFile()));

        return str_contains($message, 'undefined variable $fy')
            && str_ends_with($file, '/app/services/sales/salesinvoiceservice.php');
    }
}
