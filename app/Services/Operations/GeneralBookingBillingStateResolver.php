<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GeneralBookingBillingStateResolver
{
    private const INACTIVE = ['cancelled', 'canceled', 'void', 'voided', 'rejected'];
    private const POSTED = ['posted', 'posted_to_gl', 'final', 'finalized'];

    public function resolve(int $bookingId): array
    {
        $tablesReady = Schema::hasTable('general_booking_billing_batches')
            && Schema::hasTable('general_booking_billing_batch_items')
            && Schema::hasTable('general_booking_invoice_links');
        $native = $this->nativeInvoices($bookingId);
        $baseBatch = null;
        $supplementaryBatches = [];
        $baseInvoice = null;
        $supplementaryInvoices = [];
        $allLinkedInvoices = [];
        $approvedUninvoiced = 0.0;
        $nextBatchNo = 0;
        $nextInvoiceSequence = 0;
        $integrityErrors = [];

        if ($tablesReady) {
            $batches = DB::table('general_booking_billing_batches')->where('booking_id', $bookingId)->orderBy('batch_no')->get();
            $batchById = $batches->keyBy('id');
            $nextBatchNo = $batches->max('batch_no') === null ? 0 : ((int) $batches->max('batch_no') + 1);
            foreach ($batches as $batch) {
                try { GeneralBookingBillingBatchContract::assertBatchSequence((int) $batch->batch_no, (string) $batch->batch_type); }
                catch (\Throwable $e) { $integrityErrors[] = 'batch:'.(int) $batch->id.':'.$e->getMessage(); }
            }
            $links = DB::table('general_booking_invoice_links as l')
                ->leftJoin('general_booking_billing_batches as b', 'b.id', '=', 'l.batch_id')
                ->leftJoin('sales_invoices as si', 'si.id', '=', 'l.sales_invoice_id')
                ->where('l.booking_id', $bookingId)->orderBy('l.invoice_sequence')->get([
                    'l.id as link_id', 'l.batch_id', 'l.link_type', 'l.invoice_sequence', 'l.sales_invoice_id',
                    'l.invoice_no_snapshot', 'b.batch_no', 'b.batch_type', 'b.status as batch_status', 'b.customer_total',
                    'si.invoice_no', 'si.status as invoice_status', 'si.grand_total', 'si.customer_party_id', 'si.journal_entry_id',
                ]);
            $nextInvoiceSequence = $links->max('invoice_sequence') === null ? 0 : ((int) $links->max('invoice_sequence') + 1);
            $linkedBatchIds = [];
            foreach ($links as $link) {
                if (! $batchById->has($link->batch_id)) { $integrityErrors[] = 'link:'.(int) $link->link_id.':missing_batch'; continue; }
                try { GeneralBookingBillingBatchContract::assertLinkConsistency((int) $link->batch_no, (string) $link->batch_type, (int) $link->invoice_sequence, (string) $link->link_type); }
                catch (\Throwable $e) { $integrityErrors[] = 'link:'.(int) $link->link_id.':'.$e->getMessage(); }
                $invoice = [
                    'id' => (int) $link->sales_invoice_id,
                    'invoice_no' => (string) ($link->invoice_no ?: $link->invoice_no_snapshot ?: ''),
                    'status' => strtolower(trim((string) $link->invoice_status)),
                    'grand_total' => round((float) $link->grand_total, 2),
                    'journal_entry_id' => $link->journal_entry_id ? (int) $link->journal_entry_id : null,
                    'batch_id' => (int) $link->batch_id, 'invoice_sequence' => (int) $link->invoice_sequence,
                ];
                if ($link->invoice_status === null) $integrityErrors[] = 'link:'.(int) $link->link_id.':missing_native_invoice';
                if ($link->invoice_status !== null && ! in_array(strtolower(trim((string) $link->invoice_status)), self::INACTIVE, true)) {
                    $linkedBatchIds[(int) $link->batch_id] = true;
                }
                $allLinkedInvoices[] = $invoice;
                if ((string) $link->link_type === 'base') $baseInvoice = $invoice;
                else $supplementaryInvoices[] = $invoice;
            }
            foreach ($batches as $batch) {
                $view = [
                    'id' => (int) $batch->id, 'batch_no' => (int) $batch->batch_no,
                    'batch_type' => (string) $batch->batch_type, 'status' => (string) $batch->status,
                    'customer_total' => round((float) $batch->customer_total, 2),
                    'invoice' => null,
                ];
                $matching = array_values(array_filter($allLinkedInvoices, static fn (array $invoice): bool => $invoice['batch_id'] === (int) $batch->id));
                $view['invoice'] = $matching[0] ?? null;
                if ((int) $batch->batch_no === 0) $baseBatch = $view;
                else $supplementaryBatches[] = $view;
                if (strtolower((string) $batch->status) === 'approved' && ! isset($linkedBatchIds[(int) $batch->id])) $approvedUninvoiced += (float) $batch->customer_total;
            }
            $approvedUninvoiced = round($approvedUninvoiced, 2);
        }

        $activeLinked = array_values(array_filter($allLinkedInvoices, fn (array $invoice): bool => ! in_array($invoice['status'], self::INACTIVE, true)));
        $postedLinked = array_values(array_filter($activeLinked, fn (array $invoice): bool => in_array($invoice['status'], self::POSTED, true)));
        $activeNative = array_values(array_filter($native, fn (array $invoice): bool => ! in_array($invoice['status'], self::INACTIVE, true)));
        return [
            'booking_id' => $bookingId, 'schema_ready' => $tablesReady,
            'base_batch' => $baseBatch, 'supplementary_batches' => $supplementaryBatches,
            'base_invoice' => $baseInvoice, 'supplementary_invoices' => $supplementaryInvoices,
            'all_linked_invoices' => $allLinkedInvoices,
            'active_invoice_count' => count($activeLinked), 'posted_invoice_count' => count($postedLinked),
            'total_invoiced' => round(array_sum(array_column($activeLinked, 'grand_total')), 2),
            'total_posted' => round(array_sum(array_column($postedLinked, 'grand_total')), 2),
            'approved_uninvoiced_total' => $approvedUninvoiced,
            'next_batch_no' => $nextBatchNo, 'next_invoice_sequence' => $nextInvoiceSequence,
            'legacy_base_candidate' => count($activeNative) === 1 ? $activeNative[0] : null,
            'legacy_invoice_ambiguous' => count($activeNative) > 1,
            'billing_integrity_ok' => $integrityErrors === [],
            'billing_integrity_errors' => array_values(array_unique($integrityErrors)),
        ];
    }

    private function nativeInvoices(int $bookingId): array
    {
        if (! Schema::hasTable('sales_invoices')) return [];
        return DB::table('sales_invoices')->where('booking_id', $bookingId)->orderBy('id')->get([
            'id', 'invoice_no', 'status', 'grand_total', 'customer_party_id', 'journal_entry_id',
        ])->map(static fn ($row): array => [
            'id' => (int) $row->id, 'invoice_no' => (string) $row->invoice_no,
            'status' => strtolower(trim((string) $row->status)), 'grand_total' => round((float) $row->grand_total, 2),
            'customer_party_id' => $row->customer_party_id ? (int) $row->customer_party_id : null,
            'journal_entry_id' => $row->journal_entry_id ? (int) $row->journal_entry_id : null,
        ])->all();
    }
}
