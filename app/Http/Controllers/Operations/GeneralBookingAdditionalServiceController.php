<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\GeneralBookingAdditionalServiceManager;
use App\Services\Operations\GeneralBookingAdditionalServiceSalesInvoiceCoordinator;
use App\Services\Operations\NativeBookingCustomerResolver;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Operations\GeneralBookingAdditionalServiceWorkflowManager;
use App\Services\Operations\GroupUmrahEditAuthority;
use App\Services\Operations\BookingEditLockResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class GeneralBookingAdditionalServiceController extends Controller
{
    public function index(Request $request, int $booking, GeneralBookingAdditionalServiceManager $manager, NativeErpLayoutResolver $layout, NativeBookingCustomerResolver $customer, BookingEditLockResolver $locks): View
    {
        $state = $manager->indexState($booking, (int) ($request->user()?->id ?? 0));
        abort_if(($state['booking_missing'] ?? false) || (! Schema::hasTable('bookings') && ! ($state['schema_ready'] ?? false)), 404);
        $bookingRow = (array) ($state['booking'] ?? []);
        return view('operations.bookings.additional-services.index', [
            'layoutMeta' => $layout->resolve(), 'bookingId' => $booking, 'state' => $state,
            'booking' => $bookingRow, 'lock' => $locks->fromRow($bookingRow),
            'customer' => $customer->resolve($booking),
        ]);
    }

    public function start(Request $request, int $booking, GeneralBookingAdditionalServiceManager $manager): RedirectResponse
    {
        try {
            $result = $manager->start($booking, (int) ($request->user()?->id ?? 0));
        } catch (Throwable $e) {
            return redirect()->route('bookings.additional-services.index', $booking)->withErrors(['additional_services' => 'Additional Services could not be started safely.']);
        }
        if (($result['ok'] ?? false) && ! empty($result['batch_id'])) {
            return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $result['batch_id']]);
        }
        return redirect()->route('bookings.additional-services.index', $booking)->withErrors(['additional_services' => $result['message'] ?? 'Additional Services is not available for this booking.']);
    }

    public function show(Request $request, int $booking, int $batch, GeneralBookingAdditionalServiceManager $manager, NativeErpLayoutResolver $layout, NativeBookingCustomerResolver $customer, GroupUmrahEditAuthority $authority, BookingEditLockResolver $locks): View
    {
        $state = $manager->show($booking, $batch);
        abort_if(($state['booking_missing'] ?? false) || (($state['schema_ready'] ?? false) && ($state['batch_missing'] ?? false)), 404);
        $bookingRow = (array) ($state['booking'] ?? []);
        return view('operations.bookings.additional-services.show', [
            'layoutMeta' => $layout->resolve(), 'bookingId' => $booking, 'batchId' => $batch, 'state' => $state, 'canApprove' => $authority->canReopen($request->user()),
            'booking' => $bookingRow, 'lock' => $locks->fromRow($bookingRow),
            'customer' => $customer->resolve($booking),
        ]);
    }

    public function invoice(Request $request, int $booking, int $batch, GeneralBookingAdditionalServiceSalesInvoiceCoordinator $coordinator, NativeSalesInvoiceInspector $invoices): RedirectResponse
    {
        try {
            $result = $coordinator->create($request, $booking, $batch);
            $status = strtolower(trim((string) ($result['status'] ?? '')));
            if (! in_array($status, ['created', 'already_invoiced'], true)) {
                throw ValidationException::withMessages([
                    'invoice' => 'Supplementary Sales Invoice operation returned an invalid status.',
                ]);
            }
            $salesInvoiceId = (int) ($result['sales_invoice_id'] ?? 0);
            if ($salesInvoiceId <= 0) {
                return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch])
                    ->withErrors(['invoice' => 'The supplementary Sales Invoice could not be safely resolved.']);
            }
            $url = $invoices->nativeInvoiceUrl($salesInvoiceId);
            if (! is_string($url) || trim($url) === '') {
                $url = url('/sales/invoices/'.$salesInvoiceId);
            }
            $message = $status === 'already_invoiced'
                ? 'Supplementary Sales Invoice already exists. Existing invoice opened.'
                : 'Supplementary Sales Invoice created successfully.';
            return redirect()->to($url)->with('success', $message);
        } catch (ValidationException $e) {
            $message = $e->getMessageBag()->first('batch')
                ?: $e->getMessageBag()->first('invoice')
                ?: 'The supplementary Sales Invoice request was not allowed.';
            return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch])
                ->withErrors(['invoice' => $message]);
        } catch (Throwable $e) {
            report($e);
            return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch])
                ->withErrors(['invoice' => 'Supplementary Sales Invoice could not be created or safely opened.']);
        }
    }

    public function workflow(Request $request, int $booking, int $batch, string $action, GeneralBookingAdditionalServiceWorkflowManager $workflow): RedirectResponse
    {
        try {
            $result = $workflow->transition($booking, $batch, $action, $request->user(), $request->input('rejection_reason'));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { throw $e; }
        catch (Throwable $e) { report($e); return back()->withErrors(['workflow' => 'The Additional Services workflow action could not be completed safely.']); }
        if (($result['code'] ?? '') === 'forbidden') abort(403, $result['message']);
        if (! ($result['ok'] ?? false)) return back()->withErrors(['workflow' => $result['message'] ?? 'The workflow action was not allowed.']);
        return redirect()->route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch]);
    }
}
