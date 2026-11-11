<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Branch;
use App\Services\Sales\BookingCommercialLineItemResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class BookingQuotationController extends Controller
{
    public function __construct(private readonly BookingCommercialLineItemResolver $commercial)
    {
    }

    public function print(Request $request, Booking $booking): View
    {
        $this->authorizeBooking($request, $booking);
        $booking->load(['company', 'branch', 'customer']);
        $document = $this->commercial->resolve($booking);

        return view('sales.quotations.print', [
            'booking' => $booking,
            'company' => $booking->company,
            'branch' => $booking->branch,
            'customer' => $booking->customer,
            'document' => $document,
        ]);
    }

    private function authorizeBooking(Request $request, Booking $booking): void
    {
        $user = $request->user();
        abort_unless($user, 403);
        if ($user->is_super_admin || $user->access_scope === 'ALL_BRANCHES') {
            abort_unless(Branch::query()->where('company_id', $booking->company_id)->whereKey($booking->branch_id)->where('is_active', true)->exists(), 403);
            return;
        }

        $allowed = $user->branches()->where('branches.company_id', $booking->company_id)->where('branches.is_active', true);
        if ($user->access_scope === 'PRIMARY_BRANCH') $allowed->wherePivot('is_primary', true);
        abort_unless($allowed->whereKey($booking->branch_id)->exists(), 403);
    }
}
