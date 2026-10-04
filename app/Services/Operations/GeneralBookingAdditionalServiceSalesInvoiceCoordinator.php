<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class GeneralBookingAdditionalServiceSalesInvoiceCoordinator
{
    public function __construct(
        private readonly GeneralBookingAdditionalServiceSnapshotIntegrity $integrity,
        private readonly GeneralBookingAdditionalServiceMaterializationPlanner $planner,
        private readonly SalesInvoiceService $salesInvoices,
    ) {}

    public function create(Request $request, int $bookingId, int $batchId): array
    {
        return DB::transaction(function () use ($request, $bookingId, $batchId): array {
            $this->assertFoundationReady();
            $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            $batch = DB::table('general_booking_billing_batches')
                ->where('id', $batchId)
                ->where('booking_id', $bookingId)
                ->lockForUpdate()
                ->first();
            if (! $batch) throw ValidationException::withMessages(['batch' => 'The supplementary billing batch was not found for this booking.']);
            if (strtolower((string) $batch->batch_type) !== 'supplementary') throw ValidationException::withMessages(['batch' => 'Base billing batches cannot create supplementary invoices.']);
            if (strtolower((string) $batch->status) !== 'approved') throw ValidationException::withMessages(['batch' => 'Only an approved supplementary batch can be invoiced.']);
            if ((int) $batch->batch_no < 1) throw ValidationException::withMessages(['batch' => 'Supplementary batches require a positive batch number.']);

            $existingLink = DB::table('general_booking_invoice_links')
                ->where('batch_id', $batchId)
                ->lockForUpdate()
                ->first();
            if ($existingLink) {
                if ((int) $existingLink->booking_id !== $bookingId
                    || (int) $existingLink->batch_id !== $batchId
                    || (int) $existingLink->sales_invoice_id <= 0) {
                    throw ValidationException::withMessages(['batch' => 'Existing supplementary invoice link is inconsistent.']);
                }
                $linkedInvoice = SalesInvoice::query()
                    ->whereKey((int) $existingLink->sales_invoice_id)
                    ->first();
                if (! $linkedInvoice) {
                    throw ValidationException::withMessages(['batch' => 'Existing supplementary invoice link has no native Sales Invoice.']);
                }
                if ((int) $linkedInvoice->booking_id !== $bookingId) {
                    throw ValidationException::withMessages(['batch' => 'Existing supplementary invoice link belongs to another booking.']);
                }
                if ($linkedInvoice->customer_party_id !== null
                    && $booking->customer_party_id !== null
                    && (int) $linkedInvoice->customer_party_id !== (int) $booking->customer_party_id) {
                    throw ValidationException::withMessages(['batch' => 'Existing supplementary invoice link belongs to another customer.']);
                }
                $invoiceNoSnapshot = trim((string) ($existingLink->invoice_no_snapshot ?? ''));
                if ($invoiceNoSnapshot !== '' && $invoiceNoSnapshot !== trim((string) $linkedInvoice->invoice_no)) {
                    throw ValidationException::withMessages(['batch' => 'Existing supplementary invoice number snapshot is inconsistent.']);
                }
                return [
                    'status' => 'already_invoiced',
                    'booking_id' => $bookingId,
                    'batch_id' => $batchId,
                    'sales_invoice_id' => (int) $existingLink->sales_invoice_id,
                    'invoice_no' => $linkedInvoice->invoice_no,
                    'invoice_status' => $linkedInvoice->status,
                    'invoice_sequence' => (int) $existingLink->invoice_sequence,
                ];
            }

            $items = DB::table('general_booking_billing_batch_items')
                ->where('batch_id', $batchId)
                ->where('booking_id', $bookingId)
                ->orderBy('line_no')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty()) throw ValidationException::withMessages(['batch' => 'An approved supplementary batch must contain materialized items.']);
            foreach ($items as $item) {
                if (! $item->source_table || (int) $item->source_id <= 0 || (int) $item->booking_service_id <= 0 || (int) $item->product_service_id <= 0) {
                    throw ValidationException::withMessages(['batch' => 'Every supplementary item must be fully materialized before invoicing.']);
                }
            }

            $frozen = $this->integrity->build($bookingId, $batchId);
            if (! hash_equals((string) $batch->source_snapshot_hash, (string) ($frozen['hash'] ?? ''))) throw ValidationException::withMessages(['batch' => 'The approved supplementary snapshot no longer matches its persisted items.']);
            $plan = $this->planner->plan($bookingId, $batchId);
            if (($plan['materialization_state'] ?? null) !== 'already_materialized' && ($plan['code'] ?? null) !== 'already_materialized') throw ValidationException::withMessages(['batch' => 'The supplementary batch is not fully materialized.']);

            $serviceIds = $items->pluck('booking_service_id')->map(static fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            if ($serviceIds === []) throw ValidationException::withMessages(['batch' => 'No valid booking services were materialized for this batch.']);
            $services = DB::table('booking_services')->whereIn('id', $serviceIds)->where('booking_id', $bookingId)->get()->keyBy('id');
            if ($services->count() !== count($serviceIds)) throw ValidationException::withMessages(['batch' => 'A supplementary item references a booking service outside this booking.']);
            foreach ($items as $item) {
                $service = $services->get($item->booking_service_id);
                if (! $service || (int) $service->product_service_id !== (int) $item->product_service_id) throw ValidationException::withMessages(['batch' => 'A supplementary item has an invalid Product Service ownership link.']);
            }

            $expectedTotal = round((float) ($frozen['totals']['customer_total'] ?? $batch->customer_total), 2);
            if ($expectedTotal <= 0) throw ValidationException::withMessages(['batch' => 'The approved supplementary customer total must be positive.']);
            if (! Schema::hasColumn('booking_services', 'line_total')) throw ValidationException::withMessages(['batch' => 'Native booking service totals are unavailable.']);
            $nativeTotal = round((float) DB::table('booking_services')->whereIn('id', $serviceIds)->sum('line_total'), 2);
            if (abs($nativeTotal - $expectedTotal) > 0.01) throw ValidationException::withMessages(['batch' => 'Frozen supplementary total does not reconcile to native booking services.']);

            $invoice = $this->salesInvoices->createFromBookingServices($request, $booking, $serviceIds);
            if (! $invoice instanceof SalesInvoice || ! $invoice->getKey()) throw ValidationException::withMessages(['invoice' => 'The native scoped Sales Invoice creator returned an invalid invoice.']);
            $invoice = $invoice->fresh(['lines']);
            if ((int) $invoice->booking_id !== $bookingId || strtoupper((string) $invoice->status) !== 'DRAFT' || (int) $invoice->customer_party_id !== (int) $booking->customer_party_id || trim((string) $invoice->invoice_no) === '') throw ValidationException::withMessages(['invoice' => 'The native supplementary Draft invoice failed identity validation.']);

            $lineIds = $invoice->lines->pluck('source_booking_service_id')->map(static fn ($id): int => (int) $id);
            if ($lineIds->contains(fn (int $id): bool => $id <= 0) || $lineIds->count() !== $lineIds->unique()->count() || $lineIds->sort()->values()->all() !== $serviceIds) throw ValidationException::withMessages(['invoice' => 'Native invoice lines do not exactly match the supplementary service scope.']);
            foreach ($invoice->lines as $line) {
                $service = $services->get($line->source_booking_service_id);
                if (! $service || (int) $line->product_service_id !== (int) $service->product_service_id) throw ValidationException::withMessages(['invoice' => 'A native invoice line has an invalid Product Service link.']);
            }
            $lineTotal = round((float) $invoice->lines->sum('line_total'), 2);
            if ((float) $invoice->grand_total <= 0 || abs($lineTotal - $expectedTotal) > 0.01 || abs((float) $invoice->grand_total - $expectedTotal) > 0.01) throw ValidationException::withMessages(['invoice' => 'Native supplementary invoice totals do not reconcile to the approved frozen total.']);

            $links = DB::table('general_booking_invoice_links')->where('booking_id', $bookingId)->lockForUpdate()->get();
            $invoiceSequence = $links->max('invoice_sequence') === null ? 1 : ((int) $links->max('invoice_sequence') + 1);
            if ($invoiceSequence < 1) throw ValidationException::withMessages(['invoice' => 'A valid supplementary invoice sequence could not be allocated.']);
            GeneralBookingBillingBatchContract::assertLinkConsistency((int) $batch->batch_no, 'supplementary', $invoiceSequence, 'supplementary');
            $link = ['booking_id' => $bookingId, 'batch_id' => $batchId, 'link_type' => 'supplementary', 'invoice_sequence' => $invoiceSequence, 'sales_invoice_id' => $invoice->getKey(), 'invoice_no_snapshot' => $invoice->invoice_no, 'created_by' => $request->user()?->id];
            if (Schema::hasColumn('general_booking_invoice_links', 'created_at')) $link['created_at'] = now();
            if (Schema::hasColumn('general_booking_invoice_links', 'updated_at')) $link['updated_at'] = now();
            DB::table('general_booking_invoice_links')->insert($link);
            if (Schema::hasColumn('general_booking_billing_batches', 'invoice_created_at') && $batch->invoice_created_at === null) DB::table('general_booking_billing_batches')->where('id', $batchId)->update(['invoice_created_at' => now(), 'updated_at' => now()]);
            return ['status' => 'created', 'booking_id' => $bookingId, 'batch_id' => $batchId, 'sales_invoice_id' => (int) $invoice->getKey(), 'invoice_no' => (string) $invoice->invoice_no, 'invoice_sequence' => $invoiceSequence];
        }, 3);
    }

    private function assertFoundationReady(): void
    {
        foreach (['bookings', 'booking_services', 'general_booking_billing_batches', 'general_booking_billing_batch_items', 'general_booking_invoice_links', 'sales_invoices'] as $table) {
            if (! Schema::hasTable($table)) throw ValidationException::withMessages(['billing' => 'Supplementary invoice foundation is unavailable.']);
        }
    }
}
