<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Operations\BookingInvoiceEligibilityResolver;
use App\Services\Operations\BookingCommercialCompletenessResolver;
use App\Services\Operations\NativeSalesInvoiceCreateCapability;
use App\Services\Operations\NativeSalesInvoiceRuntimeBridge;
use App\Http\Controllers\Operations\GeneralBookingAirProductController;
use App\Http\Controllers\Operations\GeneralBookingHotelProductController;
use App\Http\Controllers\Operations\GeneralBookingTransportProductController;
use App\Http\Controllers\Operations\GeneralBookingVisaProductController;
use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use App\Services\Sales\NativeSalesInvoiceNumberNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        private readonly NativeSalesInvoiceRuntimeBridge $runtimeBridge,
        private readonly AirTicketInvoiceCommercialSyncService $airSync,
        private readonly NativeSalesInvoiceNumberNormalizer $invoiceNumbers,
        private readonly BookingInvoiceEligibilityResolver $eligibility,
        private readonly BookingCommercialCompletenessResolver $commercialCompleteness,
        private readonly NativeSalesInvoiceCreateCapability $createCapability,
    ) {}

    public function __invoke(Request $request, int $booking): RedirectResponse
    {
        if ($booking <= 0) {
            abort(404);
        }

        $existing = $this->find($booking);

        if ((int) ($existing['id'] ?? 0) > 0) {
            if ($request->isMethod('post')) {
                return $this->reviewSuccess($booking, 'Sales Invoice already exists. No duplicate invoice was created.');
            }
            return $this->open(
                (int) $existing['id'],
                'Existing Sales Invoice opened. No duplicate invoice was created.'
            );
        }

        if (! $this->createCapability->enabled()) {
            return $this->bookingError($request,$booking,$this->createCapability->disabledMessage());
        }

        if (! $this->bookingIsApproved($booking)) {
            return $this->bookingError($request, $booking, 'Approve the booking before creating its Sales Invoice.');
        }
        $commercial=$this->commercialState($request,$booking);
        if(!$commercial['complete']){
            return $this->bookingError($request,$booking,'Booking commercial data is incomplete. '.implode(' ',$commercial['reasons']));
        }

        try {
            $result = $this->runtimeBridge->create($request,$booking,(float)$commercial['expected_total'],(int)$commercial['product_count']);
        } catch (ValidationException $error) {
            return $this->bookingError(
                $request,
                $booking,
                $error->errors()['invoice'][0]
                    ?? $error->getMessage()
            );
        } catch (Throwable $error) {
            Log::error(
                'ERP-11.3.167 native Sales Invoice transaction failed and was rolled back.',
                [
                    'booking_id' => $booking,
                    'exception' => get_class($error),
                    'message' => $error->getMessage(),
                    'file' => basename($error->getFile()),
                    'line' => $error->getLine(),
                ]
            );

            return $this->bookingError(
                $request,
                $booking,
                'Sales Invoice could not be created or safely verified. The transaction was rolled back and the failure has been logged.'
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
                $request,
                $booking,
                'The native Sales Invoice operation returned without creating a linked invoice.'
            );
        }

        $this->invoiceNumbers->normalize($invoiceId);

        $this->syncAirNonBlocking(
            $invoiceId,
            $booking
        );

        if ($request->isMethod('post')) return $this->reviewSuccess($booking, 'Sales Invoice created successfully.');
        return $this->open($invoiceId, 'Sales Invoice created successfully.');
    }

    private function bookingIsApproved(int $bookingId): bool
    {
        if (! Schema::hasTable('bookings')) return false;
        $columns = Schema::getColumnListing('bookings');
        $row=DB::table('bookings')->where('id',$bookingId)->first();
        return $row ? $this->eligibility->resolve((array)$row)['eligible'] : false;
    }

    private function commercialState(Request $request,int $bookingId):array
    {
        $air=$this->snapshot(fn()=>app(GeneralBookingAirProductController::class)->show($request,$bookingId)->getData(true));
        $hotel=$this->snapshot(fn()=>app(GeneralBookingHotelProductController::class)->show($request,$bookingId)->getData(true));
        $transport=$this->snapshot(fn()=>app(GeneralBookingTransportProductController::class)->show($request,$bookingId)->getData(true));
        $visa=$this->snapshot(fn()=>app(GeneralBookingVisaProductController::class)->show($request,$bookingId)->getData(true));
        $selected=[];
        if(($air['itinerary']??[])||($air['tickets']??[]))$selected[]='air';
        if($hotel['stays']??[])$selected[]='hotel';
        if($transport['transports']??[])$selected[]='transport';
        if($visa['visa_rows']??[])$selected[]='visa';
        $state=$this->commercialCompleteness->resolve($selected,$air,$hotel,$transport,$visa);
        $state['expected_total']=round(array_sum([(float)($air['summary']['customer_total']??0),(float)($hotel['summary']['customer_total']??0),(float)($transport['summary']['customer_total']??0),(float)($visa['summary']['customer_total']??0)]),2);
        $state['product_count']=count($selected);
        return $state;
    }

    private function snapshot(callable $resolver):array
    {
        try{$value=$resolver();return is_array($value)?$value:[];}catch(Throwable $error){report($error);return [];}
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
        Request $request,
        int $bookingId,
        string $message
    ): RedirectResponse {
        if($request->isMethod('post')) return redirect()->route('bookings.review.show',['booking'=>$bookingId])->with('review_error',trim($message));
        return redirect()
            ->to(
                url('/operations/bookings/'.$bookingId)
            )
            ->withErrors([
                'invoice' => trim($message),
            ]);
    }

    private function reviewSuccess(int $bookingId,string $message):RedirectResponse
    {
        return redirect()->route('bookings.review.show',['booking'=>$bookingId])->with('review_success',$message);
    }
}
