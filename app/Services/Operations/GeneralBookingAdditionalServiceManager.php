<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C44 workflow shell for additional services.  It owns only billing-batch
 * foundation state; product, invoice and journal authorities remain native.
 */
final class GeneralBookingAdditionalServiceManager
{
    private const SCHEMA_MESSAGE = 'Additional Services requires the General Booking Billing database upgrade.';
    private const ISSUED = ['approved', 'posted', 'posted_to_gl', 'final', 'finalized'];
    private const PENDING = ['submitted', 'pending', 'pending_approval', 'awaiting_approval'];
    private const INACTIVE = ['cancelled', 'canceled', 'void', 'voided', 'rejected'];
    private const OPEN = ['draft', 'pending_approval', 'approved'];

    public function __construct(
        private readonly BookingEditLockResolver $locks,
        private readonly GeneralBookingBillingStateResolver $state,
    ) {
    }

    public function indexState(int $bookingId, ?int $userId = null): array
    {
        $base = $this->emptyState($bookingId);
        if (! Schema::hasTable('bookings')) return $base + ['booking_missing' => true];
        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        if (! $booking) return $base + ['booking_missing' => true];
        if (! $this->tablesReady()) {
            return $base + ['booking' => (array) $booking, 'booking_status' => $this->bookingStatus((array) $booking)];
        }

        $state = $this->state->resolve($bookingId);
        return $this->decorate($bookingId, (array) $booking, $state);
    }

    public function start(int $bookingId, ?int $userId = null): array
    {
        if (! Schema::hasTable('bookings')) return $this->blocked($bookingId, 'booking_missing', 'Booking was not found.');
        if (! $this->tablesReady()) return $this->blocked($bookingId, 'schema_not_ready', self::SCHEMA_MESSAGE);

        return DB::transaction(function () use ($bookingId, $userId): array {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            if (! $booking) return $this->blocked($bookingId, 'booking_missing', 'Booking was not found.');
            $eligibility = $this->eligibility((array) $booking);
            if (! $eligibility['allowed']) return $this->blocked($bookingId, $eligibility['code'], $eligibility['message']);

            $state = $this->state->resolve($bookingId);
            $base = $this->ensureBase($bookingId, $state, $userId ?? (int) (Auth::id() ?: 0));
            if (! $base['ok']) return $base;

            $batches = DB::table('general_booking_billing_batches')->where('booking_id', $bookingId)->orderBy('batch_no')->get();
            foreach ($batches as $batch) {
                $type = $this->canonical((string) $batch->batch_type);
                $status = $this->status((string) $batch->status);
                if ($type !== 'supplementary' || ! in_array($status, self::OPEN, true)) continue;
                $linked = DB::table('general_booking_invoice_links')->where('batch_id', $batch->id)->exists();
                if ($status === 'draft') return ['ok' => true, 'status' => 'draft', 'batch_id' => (int) $batch->id, 'reused' => true];
                if ($status === 'pending_approval') return $this->blocked($bookingId, 'pending_approval', 'Additional Services is pending approval and is read-only.');
                if ($status === 'approved' && ! $linked) return $this->blocked($bookingId, 'approved_uninvoiced', 'Additional Services is approved and awaiting its supplementary invoice.');
            }

            $baseRow = DB::table('general_booking_billing_batches')->where('booking_id', $bookingId)->where('batch_no', 0)->first();
            $next = ((int) (DB::table('general_booking_billing_batches')->where('booking_id', $bookingId)->max('batch_no'))) + 1;
            $next = max(1, $next);
            GeneralBookingBillingBatchContract::assertBatchSequence($next, 'supplementary');
            $now = now();
            $id = DB::table('general_booking_billing_batches')->insertGetId([
                'booking_id' => $bookingId, 'batch_no' => $next, 'batch_type' => 'supplementary', 'status' => 'draft',
                'currency_code' => (string) ($baseRow->currency_code ?? 'PKR'), 'exchange_rate' => (float) ($baseRow->exchange_rate ?? 1),
                'customer_total' => 0, 'created_by' => $userId ?? (int) (Auth::id() ?: 0), 'created_at' => $now, 'updated_at' => $now,
            ]);
            return ['ok' => true, 'status' => 'draft', 'batch_id' => (int) $id, 'batch_no' => $next, 'reused' => false];
        });
    }

    public function show(int $bookingId, int $batchId): array
    {
        $state = $this->indexState($bookingId);
        if (($state['booking_missing'] ?? false)) return $state;
        if (! ($state['schema_ready'] ?? false)) return $state + ['batch_missing' => false];
        $batch = DB::table('general_booking_billing_batches')->where('id', $batchId)->where('booking_id', $bookingId)->first();
        if (! $batch) return $state + ['batch_missing' => true];
        return $state + ['batch' => (array) $batch, 'product_items' => [], 'product_choices_disabled' => true];
    }

    private function ensureBase(int $bookingId, array $state, int $userId): array
    {
        if ($state['base_invoice'] ?? null) {
            return $this->baseStatus($bookingId, $state['base_invoice']);
        }
        if ($state['all_linked_invoices'] ?? []) return $this->blocked($bookingId, 'base_invoice_missing', 'A valid Base Sales Invoice link is required before Additional Services.');
        if (($state['legacy_invoice_ambiguous'] ?? false)) return $this->blocked($bookingId, 'legacy_invoice_ambiguous', 'Multiple active legacy Sales Invoices were found; no Base Invoice was guessed.');
        $candidate = $state['legacy_base_candidate'] ?? null;
        if (! $candidate) return $this->blocked($bookingId, 'base_invoice_missing', 'No active Base Sales Invoice was found.');
        $baseStatus = $this->status((string) ($candidate['status'] ?? ''));
        if (! in_array($baseStatus, self::ISSUED, true)) return $this->blocked($bookingId, $this->baseStatusCode($baseStatus), 'The Base Sales Invoice must resolve to an issued commercial state first.');

        $invoiceId = (int) ($candidate['id'] ?? 0);
        $invoice = DB::table('sales_invoices')->where('id', $invoiceId)->first();
        if (! $invoice) return $this->blocked($bookingId, 'base_invoice_missing', 'The legacy Base Sales Invoice no longer exists.');
        $values = (array) $invoice;
        $currency = (string) ($values['currency_code'] ?? $values['currency'] ?? 'PKR');
        $rate = (float) ($values['exchange_rate'] ?? $values['fx_rate'] ?? 1);
        $total = (float) ($values['grand_total'] ?? 0);
        $payload = json_encode(['sales_invoice_id' => $invoiceId, 'invoice_no' => $values['invoice_no'] ?? null, 'grand_total' => $total], JSON_UNESCAPED_SLASHES);
        $now = now();
        $batchId = DB::table('general_booking_billing_batches')->insertGetId([
            'booking_id' => $bookingId, 'batch_no' => 0, 'batch_type' => 'base', 'status' => 'invoice_created',
            'currency_code' => $currency, 'exchange_rate' => $rate, 'source_snapshot_hash' => hash('sha256', (string) $payload),
            'customer_subtotal' => (float) ($values['subtotal'] ?? 0), 'discount_total' => (float) ($values['discount_total'] ?? 0),
            'customer_total' => $total, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        GeneralBookingBillingBatchContract::assertLinkConsistency(0, 'base', 0, 'base');
        DB::table('general_booking_invoice_links')->insert([
            'booking_id' => $bookingId, 'batch_id' => $batchId, 'link_type' => 'base', 'invoice_sequence' => 0,
            'sales_invoice_id' => $invoiceId, 'invoice_no_snapshot' => (string) ($values['invoice_no'] ?? ''),
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return ['ok' => true, 'status' => 'base_adopted'];
    }

    private function baseStatus(int $bookingId, array $invoice): array
    {
        $status = $this->status((string) ($invoice['status'] ?? ''));
        return in_array($status, self::ISSUED, true)
            ? ['ok' => true, 'status' => 'base_ready']
            : $this->blocked($bookingId, $this->baseStatusCode($status), 'The Base Sales Invoice must resolve to an issued commercial state first.');
    }

    private function baseStatusCode(string $status): string
    {
        if ($status === 'draft') return 'base_invoice_still_draft';
        if (in_array($status, self::PENDING, true)) return 'base_invoice_pending';
        if (in_array($status, self::INACTIVE, true)) return 'base_invoice_inactive';
        return 'base_invoice_unissued';
    }

    private function decorate(int $bookingId, array $booking, array $state): array
    {
        $eligibility = $this->eligibility($booking);
        $state['booking'] = $booking; $state['booking_status'] = $this->bookingStatus($booking);
        $state['schema_ready'] = true; $state['message'] = null; $state['eligibility_code'] = $eligibility['code'];
        $baseStatus = null;
        if (is_array($state['base_invoice'] ?? null)) {
            $baseStatus = $this->status((string) ($state['base_invoice']['status'] ?? ''));
        } elseif (! ($state['all_linked_invoices'] ?? []) && is_array($state['legacy_base_candidate'] ?? null)) {
            $baseStatus = $this->status((string) ($state['legacy_base_candidate']['status'] ?? ''));
        }
        $baseReady = $baseStatus !== null && in_array($baseStatus, self::ISSUED, true);
        $baseAdoptable = ! ($state['base_invoice'] ?? null) && ! ($state['all_linked_invoices'] ?? []) && $baseReady;
        $baseAllowed = $baseReady || $baseAdoptable;
        $open = $this->openBatchState($state['supplementary_batches'] ?? []);
        $state['can_start_new_batch'] = $eligibility['allowed'] && $baseAllowed && ! $open['blocking'];
        $state['continue_batch_id'] = $open['continue_batch_id'];
        $state['continue_batch_no'] = $open['continue_batch_no'];
        $state['open_batch_status'] = $open['status'];
        $baseCode = $baseStatus === null
            ? (($state['legacy_invoice_ambiguous'] ?? false) ? 'legacy_invoice_ambiguous' : 'base_invoice_missing')
            : $this->baseStatusCode($baseStatus);
        $state['entry_code'] = ! $eligibility['allowed'] ? $eligibility['code'] : ($baseAllowed ? ($open['code'] ?? 'eligible') : $baseCode);
        $state['entry_message'] = $this->entryMessage((string) $state['entry_code']);
        $state['can_start'] = $state['can_start_new_batch'];
        return $state;
    }

    private function openBatchState(array $batches): array
    {
        foreach ($batches as $batch) {
            $status = $this->status((string) ($batch['status'] ?? ''));
            if ($status === 'draft') return ['blocking' => true, 'status' => $status, 'code' => 'draft_open', 'continue_batch_id' => (int) ($batch['id'] ?? 0), 'continue_batch_no' => (int) ($batch['batch_no'] ?? 0)];
            if ($status === 'pending_approval') return ['blocking' => true, 'status' => $status, 'code' => 'pending_approval', 'continue_batch_id' => null, 'continue_batch_no' => null];
            if ($status === 'approved' && ! ($batch['has_invoice_link'] ?? false)) return ['blocking' => true, 'status' => $status, 'code' => 'approved_uninvoiced', 'continue_batch_id' => null, 'continue_batch_no' => null];
        }
        return ['blocking' => false, 'status' => null, 'code' => 'eligible', 'continue_batch_id' => null, 'continue_batch_no' => null];
    }

    private function entryMessage(string $code): ?string
    {
        return match ($code) {
            'schema_not_ready' => self::SCHEMA_MESSAGE,
            'base_invoice_still_draft' => 'The Base Sales Invoice is still Draft; complete the original workflow first.',
            'base_invoice_pending' => 'The Base Sales Invoice is pending approval.',
            'base_invoice_inactive' => 'The Base Sales Invoice is inactive and cannot be used.',
            'base_invoice_missing' => 'No active Base Sales Invoice was found.',
            'legacy_invoice_ambiguous' => 'Multiple active legacy Sales Invoices were found; no Base Invoice was guessed.',
            'draft_open' => 'Continue the existing Additional Services draft.',
            'pending_approval' => 'Additional Services is pending approval and is read-only.',
            'approved_uninvoiced' => 'Additional Services is approved and awaiting its supplementary invoice.',
            default => null,
        };
    }

    private function eligibility(array $booking): array
    {
        $lock = $this->locks->fromRow($booking);
        $status = strtolower(trim((string) ($lock['status'] ?? 'draft')));
        if (in_array($status, ['approved', 'travel ready'], true)) return ['allowed' => true, 'code' => 'eligible', 'message' => ''];
        $code = $status === 'pending approval' ? 'pending_approval' : ($status === 'reopened' ? 'reopened' : 'draft');
        return ['allowed' => false, 'code' => $code, 'message' => 'Additional Services is available only for Approved or Travel Ready bookings.'];
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('general_booking_billing_batches') && Schema::hasTable('general_booking_billing_batch_items') && Schema::hasTable('general_booking_invoice_links');
    }

    private function emptyState(int $bookingId): array
    {
        return ['booking_id' => $bookingId, 'schema_ready' => false, 'message' => self::SCHEMA_MESSAGE, 'base_batch' => null, 'supplementary_batches' => [], 'total_invoiced' => 0, 'total_posted' => 0, 'approved_uninvoiced_total' => 0, 'can_start' => false];
    }

    private function blocked(int $bookingId, string $status, string $message): array
    {
        return ['ok' => false, 'booking_id' => $bookingId, 'status' => $status, 'message' => $message];
    }

    private function status(string $value): string { return str_replace([' ', '-'], '_', strtolower(trim($value))); }
    private function canonical(string $value): string { try { return GeneralBookingBillingBatchContract::canonicalBatchType($value); } catch (\Throwable) { return ''; } }
    private function bookingStatus(array $booking): string { return $this->locks->fromRow($booking)['status'] ?? 'Draft'; }
}
