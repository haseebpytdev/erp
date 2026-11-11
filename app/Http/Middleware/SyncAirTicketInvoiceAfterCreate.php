<?php

namespace App\Http\Middleware;

use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ERP-11.3.64
 *
 * Idempotent Sales Invoice creation boundary for:
 *   /sales/invoices/from-booking/{booking}
 *
 * Covers native GET and POST variants.
 *
 * Rules:
 * - existing linked invoice: open it; never invoke native create again
 * - no linked invoice: allow native create
 * - invoice committed then exception/500: recover and open it
 * - no invoice before or after a native failure: preserve the real failure
 *
 * This middleware never creates a Sales Invoice itself.
 */
class SyncAirTicketInvoiceAfterCreate
{
    public function __construct(
        private readonly AirTicketInvoiceCommercialSyncService $sync,
        private readonly NativeSalesInvoiceInspector $invoices,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bookingId = $this->bookingId($request);

        $beforeInvoice = $bookingId > 0
            ? $this->safeFindInvoice($bookingId)
            : null;

        $beforeInvoiceId = (int) ($beforeInvoice['id'] ?? 0);

        // Idempotency: do not call native create twice for one booking.
        if ($beforeInvoiceId > 0) {
            $syncResult = $this->syncWithoutBreakingRedirect(
                $beforeInvoiceId,
                $bookingId,
                false
            );

            Log::info(
                'ERP-11.3.64 opened existing Sales Invoice for repeated from-booking request.',
                [
                    'booking_id' => $bookingId,
                    'invoice_id' => $beforeInvoiceId,
                    'request_method' => $request->method(),
                    'route_name' => $request->route()?->getName(),
                    'air_sync_completed' => $syncResult['synced'],
                    'air_sync_error' => $syncResult['error'],
                ]
            );

            return $this->invoiceRedirect(
                $beforeInvoiceId,
                'Existing Sales Invoice opened. No duplicate invoice was created.'
            );
        }

        try {
            $response = $next($request);
        } catch (Throwable $nativeError) {
            $recovered = $this->recoverAfterNativeFailure(
                $request,
                $bookingId,
                $nativeError
            );

            if ($recovered instanceof RedirectResponse) {
                return $recovered;
            }

            throw $nativeError;
        }

        if (
            ! $response instanceof RedirectResponse
            && $response->getStatusCode() >= 400
        ) {
            $recovered = $this->recoverAfterNativeFailure(
                $request,
                $bookingId,
                null
            );

            if ($recovered instanceof RedirectResponse) {
                return $recovered;
            }

            return $response;
        }

        if (! $response instanceof RedirectResponse) {
            $created = $bookingId > 0
                ? $this->safeFindInvoice($bookingId)
                : null;

            $createdId = (int) ($created['id'] ?? 0);

            if ($createdId > 0) {
                $this->syncWithoutBreakingRedirect(
                    $createdId,
                    $bookingId,
                    false
                );

                return $this->invoiceRedirect(
                    $createdId,
                    'Sales Invoice created successfully.'
                );
            }

            return $response;
        }

        $invoiceId = $this->invoiceIdFromRedirect($response);

        if ($invoiceId > 0) {
            $this->syncWithoutBreakingRedirect(
                $invoiceId,
                $bookingId,
                false
            );

            return $response;
        }

        $created = $bookingId > 0
            ? $this->safeFindInvoice($bookingId)
            : null;

        $createdId = (int) ($created['id'] ?? 0);

        if ($createdId > 0) {
            $this->syncWithoutBreakingRedirect(
                $createdId,
                $bookingId,
                false
            );

            return $this->invoiceRedirect(
                $createdId,
                'Sales Invoice created successfully.'
            );
        }

        return $response;
    }

    private function recoverAfterNativeFailure(
        Request $request,
        int $bookingId,
        ?Throwable $nativeError
    ): ?RedirectResponse {
        if ($bookingId <= 0) {
            return null;
        }

        $afterInvoice = $this->safeFindInvoice($bookingId);
        $invoiceId = (int) ($afterInvoice['id'] ?? 0);

        if ($invoiceId <= 0) {
            Log::error(
                'ERP-11.3.64 native Sales Invoice from-booking failed before a linked invoice could be recovered.',
                [
                    'booking_id' => $bookingId,
                    'request_method' => $request->method(),
                    'route_name' => $request->route()?->getName(),
                    'route_action' => $request->route()?->getActionName(),
                    'native_exception' => $nativeError
                        ? get_class($nativeError)
                        : null,
                    'native_message' => $nativeError?->getMessage(),
                    'native_file' => $nativeError
                        ? basename($nativeError->getFile())
                        : null,
                    'native_line' => $nativeError?->getLine(),
                ]
            );

            return null;
        }

        $syncResult = $this->syncWithoutBreakingRedirect(
            $invoiceId,
            $bookingId,
            true
        );

        Log::warning(
            'ERP-11.3.64 recovered a Sales Invoice persisted before native from-booking failed.',
            [
                'booking_id' => $bookingId,
                'invoice_id' => $invoiceId,
                'request_method' => $request->method(),
                'route_name' => $request->route()?->getName(),
                'route_action' => $request->route()?->getActionName(),
                'user_id' => $request->user()?->id,
                'native_exception' => $nativeError
                    ? get_class($nativeError)
                    : null,
                'native_message' => $nativeError?->getMessage(),
                'native_file' => $nativeError
                    ? basename($nativeError->getFile())
                    : null,
                'native_line' => $nativeError?->getLine(),
                'air_sync_completed' => $syncResult['synced'],
                'air_sync_error' => $syncResult['error'],
            ]
        );

        if ($syncResult['synced']) {
            return $this->invoiceRedirect(
                $invoiceId,
                'Sales Invoice created and Air Ticket commercial values synchronized.'
            );
        }

        return $this->invoiceRedirect(
            $invoiceId,
            'Sales Invoice was created successfully.',
            'Air Ticket commercial synchronization could not complete automatically. Keep the invoice Draft and review its commercial values before approval.'
        );
    }

    private function invoiceRedirect(
        int $invoiceId,
        ?string $success = null,
        ?string $warning = null
    ): RedirectResponse {
        $url = $this->invoices->nativeInvoiceUrl($invoiceId);

        if (! is_string($url) || $url === '') {
            $url = url('/sales/invoices/'.$invoiceId);
        }

        $redirect = redirect()->to($url);

        if (is_string($success) && trim($success) !== '') {
            $redirect->with('success', $success);
        }

        if (is_string($warning) && trim($warning) !== '') {
            $redirect->with('warning', $warning);
        }

        return $redirect;
    }

    /**
     * @return array{synced:bool,error:?string}
     */
    private function syncWithoutBreakingRedirect(
        int $invoiceId,
        int $bookingId,
        bool $recoveryPath
    ): array {
        if ($invoiceId <= 0) {
            return [
                'synced' => false,
                'error' => 'invalid invoice id',
            ];
        }

        try {
            if (! $this->sync->supports($invoiceId)) {
                return [
                    'synced' => true,
                    'error' => null,
                ];
            }

            $this->sync->sync($invoiceId, null);

            return [
                'synced' => true,
                'error' => null,
            ];
        } catch (Throwable $e) {
            Log::warning(
                $recoveryPath
                    ? 'ERP-11.3.64 Air Ticket invoice synchronization could not complete during from-booking recovery.'
                    : 'ERP-11.3.64 Air Ticket invoice auto-sync after from-booking could not complete.',
                [
                    'booking_id' => $bookingId,
                    'invoice_id' => $invoiceId,
                    'message' => $e->getMessage(),
                ]
            );

            return [
                'synced' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function safeFindInvoice(int $bookingId): ?array
    {
        if ($bookingId <= 0) {
            return null;
        }

        try {
            return $this->invoices->find($bookingId);
        } catch (Throwable $e) {
            Log::warning(
                'ERP-11.3.64 could not inspect Sales Invoice linkage during from-booking request.',
                [
                    'booking_id' => $bookingId,
                    'message' => $e->getMessage(),
                ]
            );

            return null;
        }
    }

    private function bookingId(Request $request): int
    {
        foreach ([
            'booking',
            'travelBooking',
            'travel_booking',
            'booking_id',
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
                '#/sales/invoices/from-booking/(\d+)(?:[/?#]|$)#',
                '/'.ltrim($request->path(), '/'),
                $matches
            ) === 1
        ) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
    }

    private function invoiceIdFromRedirect(
        RedirectResponse $response
    ): int {
        $location = (string) $response
            ->headers
            ->get('Location', '');

        if (
            $location !== ''
            && preg_match(
                '#/sales/invoices/(\d+)(?:[/?#]|$)#',
                $location,
                $matches
            ) === 1
        ) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
    }
}
