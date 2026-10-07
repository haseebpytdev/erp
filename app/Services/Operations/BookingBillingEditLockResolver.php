<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single billing boundary for mutations of the original booking scope.
 * Additional Services is deliberately outside this boundary.
 */
final class BookingBillingEditLockResolver
{
    private const INACTIVE = ['cancelled', 'canceled', 'void', 'voided', 'rejected'];
    private const FINAL = ['pending_approval', 'approved', 'posted', 'posted_to_gl', 'final', 'finalized'];

    public function resolve(int $bookingId): array
    {
        $base = ['locked' => false, 'code' => 'none', 'status' => 'none', 'reason' => ''];
        if ($bookingId <= 0 || ! Schema::hasTable('sales_invoices')) return $base;

        $rows = DB::table('sales_invoices')->where('booking_id', $bookingId)->get(['status', 'invoice_no']);
        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row->status ?? '')));
            if (in_array($status, self::INACTIVE, true)) continue;
            if ($status === 'draft') {
                return [
                    'locked' => true,
                    'code' => 'draft_invoice',
                    'status' => 'draft_invoice',
                    'reason' => 'This booking has an active Draft Sales Invoice. Cancel the Draft Sales Invoice before changing or progressing the original booking workflow.',
                    'invoice_no' => trim((string) ($row->invoice_no ?? '')),
                ];
            }
            if (in_array($status, self::FINAL, true)) {
                return [
                    'locked' => true,
                    'code' => 'final_invoice',
                    'status' => $status,
                    'reason' => 'This booking already has an active Sales Invoice. New products or services must be added through Additional Services.',
                    'invoice_no' => trim((string) ($row->invoice_no ?? '')),
                ];
            }
        }

        if (Schema::hasTable('general_booking_billing_batches') && Schema::hasTable('general_booking_invoice_links')) {
            $query = DB::table('general_booking_billing_batches as b')
                ->leftJoin('general_booking_invoice_links as l', 'l.batch_id', '=', 'b.id')
                ->where('b.booking_id', $bookingId)
                ->where('b.batch_type', 'supplementary')
                ->whereIn('b.status', ['approved']);
            $batch = $query->orderBy('b.batch_no')->first(['b.status', 'b.batch_no', 'l.id as link_id']);
            if ($batch) {
                $linked = (int) ($batch->link_id ?? 0) > 0;
                return [
                    'locked' => true,
                    'code' => $linked ? 'supplement_invoice' : 'approved_supplement',
                    'status' => $linked ? 'supplement_invoice' : 'approved_supplement',
                    'reason' => $linked
                        ? 'This booking has a Supplementary Sales Invoice. The original booking scope remains protected.'
                        : 'This booking has an approved supplementary batch awaiting invoice. Complete the supplementary invoice flow before changing the original scope.',
                    'batch_no' => (int) ($batch->batch_no ?? 0),
                ];
            }
        }

        return $base;
    }
}
