<?php

namespace App\Services\Administration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Resolves commercial documents to the canonical C89.2 branch authority. */
final class ErpCommercialBranchAuthority
{
    public function __construct(private readonly ErpBranchAccessAuthority $branches) {}

    public function scopeSupplierCostings($query, mixed $user)
    {
        $allowed = $this->branches->allowedBranchIds($user);
        if ($allowed === null) return $query;
        if ($allowed === []) return $query->whereRaw('1 = 0');
        return $query->whereExists(function ($booking) use ($allowed): void {
            $booking->selectRaw('1')->from('bookings')
                ->whereColumn('bookings.id', 'supplier_costings.booking_id')
                ->whereIn('bookings.branch_id', $allowed)
                ->whereNotNull('bookings.company_id');
        });
    }

    public function assertBooking(mixed $user, int $bookingId): object
    {
        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        abort_unless($booking, 404);
        $this->branches->assertMayAccessBranch($user, (int) ($booking->branch_id ?? 0));
        return $booking;
    }

    public function assertSupplierCosting(mixed $user, int $costingId): object
    {
        $row = DB::table('supplier_costings')->where('id', $costingId)->first();
        abort_unless($row, 404);
        abort_unless((int) ($row->booking_id ?? 0) > 0, 404);
        $this->assertBooking($user, (int) $row->booking_id);
        return $row;
    }

    public function assertInvoice(mixed $user, int $invoiceId): object
    {
        $invoice = DB::table('sales_invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404);
        if ((int) ($invoice->booking_id ?? 0) > 0) {
            $booking = $this->assertBooking($user, (int) $invoice->booking_id);
            abort_unless((int) $invoice->branch_id === (int) ($booking->branch_id ?? 0), 404);
            abort_unless((int) $invoice->company_id === (int) ($booking->company_id ?? 0), 404);
            return $invoice;
        }
        $this->branches->assertMayAccessBranch($user, (int) ($invoice->branch_id ?? 0));
        return $invoice;
    }

    public function assertRequestedBranch(mixed $user, mixed $requested): ?int
    {
        return $this->branches->branchFilter($user, $requested);
    }
}
