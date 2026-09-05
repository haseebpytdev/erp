<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Operations\NativeBookingCustomerResolver;
use App\Services\Operations\NativeSalesInvoiceDraftCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERP-10.31.33
 *
 * Safe bridge between Group Umrah and the EXISTING native Sales Invoice module.
 *
 * Important:
 * - never guesses a conventional invoice-create URL
 * - never creates a second/parallel invoice table
 * - resolves the actual Laravel SalesInvoiceController/create route at runtime
 * - when no standalone create route exists, opens the real Sales Invoice
 *   register with booking context instead of returning staff to a 404
 */
class GroupUmrahSalesInvoiceBridgeController extends Controller
{
    public function __invoke(
        Request $request,
        int $booking,
        NativeSalesInvoiceInspector $salesInvoices,
        NativeBookingCustomerResolver $customerResolver,
        NativeSalesInvoiceDraftCreator $draftCreator,
    ): RedirectResponse {
        abort_unless(
            Schema::hasTable('bookings')
            && DB::table('bookings')->where('id', $booking)->exists(),
            404
        );

        $mode = strtolower(trim((string) $request->query('invoice_mode', 'create')));
        $amendmentId = max(0, (int) $request->query('group_umrah_amendment_id', 0));

        $identity = $customerResolver->resolve($booking);

        $context = [
            'booking_id' => $booking,
            'source' => 'group_umrah',
            'group_umrah_create' => 1,
        ];

        if ((int) ($identity['id'] ?? 0) > 0) {
            $context['customer_id'] = (int) $identity['id'];
        }

        if ($amendmentId > 0) {
            $context['group_umrah_amendment_id'] = $amendmentId;
        }

        if ($mode === 'supplementary') {
            $context['invoice_mode'] = 'supplementary';
        }

        /*
         * Base invoice already exists:
         * normal action should open it instead of attempting another base
         * invoice. Supplementary amendments intentionally continue to native
         * create/register resolution below.
         */
        if ($mode !== 'supplementary') {
            $existing = $salesInvoices->find($booking);

            if (
                $existing
                && strtolower(trim((string) ($existing['status'] ?? ''))) !== 'draft'
            ) {
                $url = $salesInvoices->nativeInvoiceUrl(
                    (int) ($existing['id'] ?? 0)
                );

                if ($url) {
                    return redirect()->to($url);
                }

                if ($register = $salesInvoices->nativeRegisterUrl([
                    'booking_id' => $booking,
                    'source' => 'group_umrah',
                ])) {
                    return redirect()->to($register);
                }

                return redirect()
                    ->route('operations.bookings.group-package-unified.edit', ['booking' => $booking])
                    ->with('error', 'The Sales Invoice exists, but its native ERP page could not be resolved.');
            }

            /*
             * Existing Draft intentionally falls through to draftCreator.
             * The creator synchronizes the same native Draft to the latest
             * fare matrix and returns its existing ID.
             */
        }

        try {
            $created = $draftCreator->create(
                $request,
                $booking,
                $mode === 'supplementary' ? 'supplementary' : 'base',
                $amendmentId > 0 ? $amendmentId : null
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            $message = collect($e->errors())->flatten()->filter()->implode(' | ');

            return redirect()
                ->route('operations.bookings.group-package-unified.edit', ['booking' => $booking])
                ->with('error', $message !== '' ? $message : 'The native Sales Invoice could not be created.');
        } catch (\Throwable $e) {
            report($e);

            $message = trim((string) $e->getMessage());
            $safeDetail = '';

            if (
                $message !== ''
                && ! str_contains(strtoupper($message), 'SQLSTATE')
                && ! str_contains(strtolower($message), 'select ')
                && ! str_contains(strtolower($message), 'insert ')
                && ! str_contains(strtolower($message), 'update ')
            ) {
                $message = preg_replace(
                    '#/home/[^/\\s]+/[^\\s]+#',
                    '[server-path]',
                    $message
                ) ?: $message;

                $message = preg_replace('/\\s+/', ' ', $message) ?: $message;
                $safeDetail = ' Native error: '.mb_substr($message, 0, 500);
            }

            return redirect()
                ->route('operations.bookings.group-package-unified.edit', ['booking' => $booking])
                ->with(
                    'error',
                    'The native Sales Invoice could not be created. The booking and commercial data were not changed.'
                    .$safeDetail
                );
        }

        $invoiceUrl = $salesInvoices->nativeInvoiceUrl((int) ($created['id'] ?? 0));

        if ($invoiceUrl) {
            return redirect()->to($invoiceUrl)->with(
                'success',
                $mode === 'supplementary'
                    ? 'Supplementary Sales Invoice draft created from the Group Umrah amendment.'
                    : 'Customer Sales Invoice draft created from the Group Umrah commercial package.'
            );
        }

        return redirect()
            ->route('operations.bookings.group-package-unified.edit', ['booking' => $booking])
            ->with(
                'success',
                $mode === 'supplementary'
                    ? 'Supplementary Sales Invoice draft created.'
                    : 'Customer Sales Invoice draft created.'
            );
    }
}
