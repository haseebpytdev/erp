<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ERP-11.3.72
 *
 * Deterministic /sales/invoices/from-booking/{booking} bridge.
 *
 * Production evidence repeatedly showed:
 *   native invoice INSERT/COMMIT succeeds
 *   -> a later response/redirect/presentation step throws
 *   -> browser sees 500
 *   -> refresh shows the invoice already exists
 *
 * This controller makes the endpoint transaction-result idempotent:
 * - existing active invoice: open it
 * - invoke the original native fromBooking controller action once
 * - if native action throws AFTER commit: recover the committed invoice
 * - if native action returns any response AFTER commit: open the invoice
 * - only rethrow when no invoice exists after the failure
 *
 * It does not create a parallel invoice or rewrite native Sales Invoice logic.
 */
class AirTicketSalesInvoiceFromBookingBridgeController extends Controller
{
    public function __construct(
        private readonly NativeSalesInvoiceInspector $invoices,
        private readonly AirTicketInvoiceCommercialSyncService $sync,
    ) {}

    public function __invoke(Request $request): Response
    {
        $bookingId = $this->bookingId($request);

        if ($bookingId <= 0) {
            abort(404);
        }

        $existing = $this->safeFindInvoice($bookingId);
        $existingId = (int) ($existing['id'] ?? 0);

        if ($existingId > 0) {
            $this->safeSync(
                $existingId,
                $bookingId,
                'existing'
            );

            return $this->invoiceRedirect(
                $existingId,
                'Existing Sales Invoice opened. No duplicate invoice was created.'
            );
        }

        $nativeMethod = $this->nativeMethod($request);

        /*
         * ERP-11.3.74 — GET is navigation; native invoice creation remains a
         * write operation.  The host ERP can register fromBooking as POST-only.
         * Calling that controller with the original browser GET caused the fresh
         * booking 500 seen on booking 8.
         *
         * Existing invoices were already handled above. For a fresh invoice,
         * transparently submit the same authenticated browser session using the
         * native write method and CSRF, then let the POST/PUT/PATCH request enter
         * this bridge again and invoke the original native controller.
         */
        if (
            in_array(
                strtoupper($request->method()),
                ['GET', 'HEAD'],
                true
            )
            && $nativeMethod !== 'GET'
        ) {
            return $this->nativeWriteHandoff(
                $request,
                $nativeMethod
            );
        }

        $nativeAction = $this->nativeAction($request);

        if (
            $nativeAction === ''
            || str_contains(
                strtolower($nativeAction),
                strtolower(self::class)
            )
        ) {
            abort(
                500,
                'Native Sales Invoice from-booking action could not be resolved.'
            );
        }

        try {
            $response = $this->invokeNative(
                $nativeAction,
                $request
            );
        } catch (Throwable $error) {
            $recovered = $this->safeFindInvoice(
                $bookingId
            );
            $recoveredId = (int) (
                $recovered['id']
                ?? 0
            );

            if ($recoveredId <= 0) {
                Log::error(
                    'ERP-11.3.72 native Sales Invoice from-booking failed before invoice persistence.',
                    [
                        'booking_id' => $bookingId,
                        'request_method' => $request->method(),
                        'native_action' => $nativeAction,
                        'exception' => get_class($error),
                        'message' => $error->getMessage(),
                        'file' => basename($error->getFile()),
                        'line' => $error->getLine(),
                    ]
                );

                throw $error;
            }

            $sync = $this->safeSync(
                $recoveredId,
                $bookingId,
                'post_commit_exception'
            );

            Log::warning(
                'ERP-11.3.72 recovered a committed Sales Invoice after the native create response failed.',
                [
                    'booking_id' => $bookingId,
                    'invoice_id' => $recoveredId,
                    'request_method' => $request->method(),
                    'native_action' => $nativeAction,
                    'exception' => get_class($error),
                    'message' => $error->getMessage(),
                    'file' => basename($error->getFile()),
                    'line' => $error->getLine(),
                    'air_sync_completed' => $sync['synced'],
                    'air_sync_error' => $sync['error'],
                ]
            );

            return $this->invoiceRedirect(
                $recoveredId,
                'Sales Invoice created successfully.',
                $sync['synced']
                    ? null
                    : 'Invoice was created, but Air Ticket commercial synchronization needs review before approval.'
            );
        }

        /*
         * Do not trust the returned response as proof of create success/failure.
         * The database is authoritative. If the native action committed the
         * invoice, always open it now — even if it returned a redirect/4xx/5xx
         * response that would otherwise expose a false failure to the browser.
         */
        $created = $this->safeFindInvoice(
            $bookingId
        );
        $createdId = (int) (
            $created['id']
            ?? 0
        );

        if ($createdId > 0) {
            $sync = $this->safeSync(
                $createdId,
                $bookingId,
                'post_native_response'
            );

            return $this->invoiceRedirect(
                $createdId,
                'Sales Invoice created successfully.',
                $sync['synced']
                    ? null
                    : 'Invoice was created, but Air Ticket commercial synchronization needs review before approval.'
            );
        }

        if ($response instanceof Response) {
            return $response;
        }

        return new IlluminateResponse(
            $response
        );
    }

    private function invokeNative(
        string $nativeAction,
        Request $request
    ): mixed {
        $parameters = [];

        foreach (
            $request->route()?->parameters()
            ?? []
            as $name => $value
        ) {
            if (
                str_starts_with(
                    (string) $name,
                    '_et_'
                )
            ) {
                continue;
            }

            $parameters[$name] = $value;
        }

        /*
         * app()->call preserves Laravel dependency injection for Request,
         * services and model-bound route arguments while executing the original
         * native controller method inside this recovery boundary.
         */
        return app()->call(
            $nativeAction,
            $parameters
        );
    }

    private function nativeAction(
        Request $request
    ): string {
        $route = $request->route();

        if (! $route) {
            return '';
        }

        $defaults = (array) (
            $route->defaults
            ?? []
        );

        return trim(
            (string) (
                $defaults['_et_native_from_booking_action']
                ?? ''
            )
        );
    }

    private function nativeMethod(
        Request $request
    ): string {
        $route = $request->route();

        if (! $route) {
            return 'POST';
        }

        $defaults = (array) (
            $route->defaults
            ?? []
        );

        $method = strtoupper(
            trim(
                (string) (
                    $defaults['_et_native_from_booking_method']
                    ?? 'POST'
                )
            )
        );

        return in_array(
            $method,
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            true
        )
            ? $method
            : 'POST';
    }

    private function nativeWriteHandoff(
        Request $request,
        string $nativeMethod
    ): Response {
        $method = strtoupper(
            trim($nativeMethod)
        );

        if (! in_array(
            $method,
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true
        )) {
            $method = 'POST';
        }

        $action = htmlspecialchars(
            $request->url(),
            ENT_QUOTES,
            'UTF-8'
        );

        $token = htmlspecialchars(
            csrf_token(),
            ENT_QUOTES,
            'UTF-8'
        );

        $methodField = $method === 'POST'
            ? ''
            : '<input type="hidden" name="_method" value="'.htmlspecialchars($method, ENT_QUOTES, 'UTF-8').'">';

        $html = '<!doctype html>'
            .'<html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Creating Sales Invoice</title>'
            .'<style>body{font-family:Inter,Arial,sans-serif;background:#f4f7fb;color:#172033;margin:0;display:grid;place-items:center;min-height:100vh}.box{background:#fff;border:1px solid #dbe3ee;border-radius:12px;padding:24px 28px;box-shadow:0 12px 35px rgba(15,23,42,.08);max-width:480px}.box h1{font-size:18px;margin:0 0 8px}.box p{font-size:13px;color:#64748b;margin:0 0 16px}.box button{border:0;border-radius:8px;background:#1467d2;color:#fff;padding:10px 16px;font-weight:800;cursor:pointer}</style>'
            .'</head><body><div class="box">'
            .'<h1>Creating Sales Invoice…</h1>'
            .'<p>The booking is being passed to the native Sales Invoice create action securely.</p>'
            .'<form id="et-native-invoice-create" method="POST" action="'.$action.'">'
            .'<input type="hidden" name="_token" value="'.$token.'">'
            .$methodField
            .'<input type="hidden" name="_et_invoice_bridge_write" value="1">'
            .'<button type="submit">Continue</button>'
            .'</form>'
            .'<script>document.getElementById("et-native-invoice-create").submit();</script>'
            .'</div></body></html>';

        return new IlluminateResponse(
            $html,
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]
        );
    }

    private function bookingId(
        Request $request
    ): int {
        foreach ([
            'booking',
            'travelBooking',
            'travel_booking',
            'booking_id',
            'id',
        ] as $name) {
            $value = $request->route(
                $name
            );

            if (
                is_object($value)
                && method_exists(
                    $value,
                    'getKey'
                )
            ) {
                $id = (int) $value->getKey();

                if ($id > 0) {
                    return $id;
                }
            }

            if (
                is_scalar($value)
                && (int) $value > 0
            ) {
                return (int) $value;
            }
        }

        if (
            preg_match(
                '#/sales/invoices/from-booking/(\d+)(?:[/?#]|$)#',
                '/'.ltrim(
                    $request->path(),
                    '/'
                ),
                $match
            ) === 1
        ) {
            return (int) (
                $match[1]
                ?? 0
            );
        }

        return 0;
    }

    private function safeFindInvoice(
        int $bookingId
    ): ?array {
        try {
            return $this->invoices->find(
                $bookingId
            );
        } catch (Throwable $error) {
            Log::warning(
                'ERP-11.3.72 could not inspect linked Sales Invoice.',
                [
                    'booking_id' => $bookingId,
                    'message' => $error->getMessage(),
                ]
            );

            return null;
        }
    }

    /**
     * @return array{synced:bool,error:?string}
     */
    private function safeSync(
        int $invoiceId,
        int $bookingId,
        string $phase
    ): array {
        try {
            if (
                ! $this->sync->supports(
                    $invoiceId
                )
            ) {
                return [
                    'synced' => true,
                    'error' => null,
                ];
            }

            $this->sync->sync(
                $invoiceId,
                null
            );

            return [
                'synced' => true,
                'error' => null,
            ];
        } catch (Throwable $error) {
            Log::warning(
                'ERP-11.3.72 Air Ticket invoice synchronization did not block invoice navigation.',
                [
                    'booking_id' => $bookingId,
                    'invoice_id' => $invoiceId,
                    'phase' => $phase,
                    'message' => $error->getMessage(),
                ]
            );

            return [
                'synced' => false,
                'error' => $error->getMessage(),
            ];
        }
    }

    private function invoiceRedirect(
        int $invoiceId,
        ?string $success = null,
        ?string $warning = null
    ): RedirectResponse {
        $url = $this->invoices
            ->nativeInvoiceUrl(
                $invoiceId
            );

        if (
            ! is_string($url)
            || trim($url) === ''
        ) {
            $url = url(
                '/sales/invoices/'
                .$invoiceId
            );
        }

        $redirect = redirect()->to(
            $url
        );

        if (
            is_string($success)
            && trim($success) !== ''
        ) {
            $redirect->with(
                'success',
                $success
            );
        }

        if (
            is_string($warning)
            && trim($warning) !== ''
        ) {
            $redirect->with(
                'warning',
                $warning
            );
        }

        return $redirect;
    }
}
