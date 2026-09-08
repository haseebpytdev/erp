<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeSalesInvoiceInspector;
use Illuminate\Http\JsonResponse;

final class GeneralBookingInvoiceSummaryController extends Controller
{
    public function show(int $booking, NativeSalesInvoiceInspector $invoices): JsonResponse
    {
        $invoice = $invoices->find($booking);
        return response()->json([
            'ok'=>true,
            'booking_id'=>$booking,
            'invoice'=>$invoice,
            'url'=>route('operations.bookings.sales-invoice.stable', ['booking'=>$booking]),
        ]);
    }
}
