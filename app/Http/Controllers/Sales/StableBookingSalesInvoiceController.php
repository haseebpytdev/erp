<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use App\Services\Sales\NativeBookingSalesInvoiceCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ERP-11.3.75
 *
 * One deterministic Sales Invoice entry point for native Booking Workspace.
 *
 * This no longer invokes the native controller action or depends on whether
 * /sales/invoices/from-booking/{booking} was registered GET/POST in the host.
 * It calls the host's native SalesInvoiceService::createFromBooking() directly.
 */
final class StableBookingSalesInvoiceController extends Controller
{
    public function __construct(
        private readonly NativeSalesInvoiceInspector $invoices,
        private readonly NativeBookingSalesInvoiceCreator $creator,
        private readonly AirTicketInvoiceCommercialSyncService $airSync,
    ) {}

    public function __invoke(Request $request, int $booking): RedirectResponse
    {
        if ($booking <= 0) {
            abort(404);
        }

        $existing = $this->find($booking);

        if ((int) ($existing['id'] ?? 0) > 0) {
            return $this->open(
                (int) $existing['id'],
                'Existing Sales Invoice opened. No duplicate invoice was created.'
            );
        }

        try {
            $result = $this->creator->create(
                $request,
                $booking
            );
        } catch (ValidationException $error) {
            $recovered = $this->find($booking);

            if ((int) ($recovered['id'] ?? 0) > 0) {
                return $this->open(
                    (int) $recovered['id'],
                    'Sales Invoice created successfully.'
                );
            }

            return $this->bookingError(
                $booking,
                $error->errors()['invoice'][0]
                    ?? $error->getMessage()
            );
        } catch (Throwable $error) {
            $recovered = $this->find($booking);

            if ((int) ($recovered['id'] ?? 0) > 0) {
                Log::warning(
                    'ERP-11.3.75 recovered Sales Invoice after native service exception.',
                    [
                        'booking_id' => $booking,
                        'invoice_id' => (int) $recovered['id'],
                        'exception' => get_class($error),
                        'message' => $error->getMessage(),
                    ]
                );

                return $this->open(
                    (int) $recovered['id'],
                    'Sales Invoice created successfully.'
                );
            }

            Log::error(
                'ERP-11.3.75 native Sales Invoice creation failed before persistence.',
                [
                    'booking_id' => $booking,
                    'exception' => get_class($error),
                    'message' => $error->getMessage(),
                    'file' => basename($error->getFile()),
                    'line' => $error->getLine(),
                ]
            );

            return $this->bookingError(
                $booking,
                'Sales Invoice could not be created. The native create operation failed before persistence. '
                    .'The failure has been logged for review.'
            );
        }

        $invoice = $this->find($booking);

        if ((int) ($invoice['id'] ?? 0) <= 0) {
            $resultId = $this->resultInvoiceId(
                $result
            );

            if ($resultId > 0) {
                $invoice = [
                    'id' => $resultId,
                ];
            }
        }

        $invoiceId = (int) (
            $invoice['id']
            ?? 0
        );

        if ($invoiceId <= 0) {
            return $this->bookingError(
                $booking,
                'The native Sales Invoice operation returned without creating a linked invoice.'
            );
        }

        $this->syncAirNonBlocking(
            $invoiceId,
            $booking
        );

        return $this->open(
            $invoiceId,
            'Sales Invoice created successfully.'
        );
    }

    private function find(int $bookingId): ?array
    {
        try {
            return $this->invoices->find(
                $bookingId
            );
        } catch (Throwable $error) {
            Log::warning(
                'ERP-11.3.75 Sales Invoice inspection failed.',
                [
                    'booking_id' => $bookingId,
                    'message' => $error->getMessage(),
                ]
            );

            return null;
        }
    }

    private function resultInvoiceId(mixed $result): int
    {
        if ($result instanceof Model) {
            return (int) $result->getKey();
        }

        if (is_array($result)) {
            foreach ([
                'id',
                'invoice_id',
                'sales_invoice_id',
            ] as $key) {
                if ((int) ($result[$key] ?? 0) > 0) {
                    return (int) $result[$key];
                }
            }
        }

        if (is_object($result)) {
            foreach ([
                'id',
                'invoice_id',
                'sales_invoice_id',
            ] as $key) {
                if ((int) ($result->{$key} ?? 0) > 0) {
                    return (int) $result->{$key};
                }
            }

            if (method_exists($result, 'getKey')) {
                return (int) $result->getKey();
            }
        }

        if (is_scalar($result) && (int) $result > 0) {
            return (int) $result;
        }

        return 0;
    }

    private function syncAirNonBlocking(
        int $invoiceId,
        int $bookingId
    ): void {
        try {
            if (! $this->airSync->supports($invoiceId)) {
                return;
            }

            $this->airSync->sync(
                $invoiceId,
                null
            );
        } catch (Throwable $error) {
            Log::warning(
                'ERP-11.3.75 Air Ticket commercial sync did not block invoice creation.',
                [
                    'booking_id' => $bookingId,
                    'invoice_id' => $invoiceId,
                    'message' => $error->getMessage(),
                ]
            );
        }
    }

    private function open(
        int $invoiceId,
        string $message
    ): RedirectResponse {
        $url = $this->invoices->nativeInvoiceUrl(
            $invoiceId
        );

        if (
            ! is_string($url)
            || trim($url) === ''
        ) {
            $url = url(
                '/sales/invoices/'.$invoiceId
            );
        }

        return redirect()
            ->to($url)
            ->with('success', $message);
    }

    private function bookingError(
        int $bookingId,
        string $message
    ): RedirectResponse {
        return redirect()
            ->to(
                url('/operations/bookings/'.$bookingId)
            )
            ->withErrors([
                'invoice' => trim($message),
            ]);
    }
}
