<?php

namespace App\Services\Purchase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Creates and refreshes only source-linked Supplier Costing Drafts.
 *
 * The BookingSupplierObligationResolver remains the sole commercial/source
 * authority. This service only projects those read-only obligations into the
 * existing Supplier Costing document model.
 */
final class SupplierCostingDraftSynchronizer
{
    public function __construct(
        private readonly BookingSupplierObligationResolver $obligations,
        private readonly SupplierCostingService $costing,
    ) {}

    /** @return array<string,mixed> */
    public function syncBooking(int $bookingId, mixed $user = null, string $reason = 'auto_sync'): array
    {
        if ($bookingId <= 0 || ! Schema::hasTable('supplier_costings') || ! Schema::hasTable('supplier_costing_lines') || ! Schema::hasTable('supplier_costing_source_links')) {
            return ['ok' => false, 'code' => 'schema_not_ready', 'created' => 0, 'updated' => 0, 'attention' => 0];
        }

        try {
            return DB::transaction(function () use ($bookingId, $user, $reason): array {
                $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
                if (! $booking) return ['ok' => false, 'code' => 'booking_missing', 'created' => 0, 'updated' => 0, 'attention' => 0];

                $existing = DB::table('supplier_costings')->where('booking_id', $bookingId)
                    ->whereIn('status', ['draft', 'pending_approval', 'approved', 'posted'])
                    ->lockForUpdate()->get();
                $auto = $existing->filter(fn (object $row): bool => $this->isAutoManaged((int) $row->id));
                $legacy = $existing->filter(fn (object $row): bool => ! DB::table('supplier_costing_source_links')->where('supplier_costing_id', $row->id)->exists());

                $created = 0; $updated = 0; $attention = 0; $skipped = 0; $refreshedSuppliers = [];
                // Exclude each auto-managed Draft while re-resolving it so
                // its own source claims do not hide the current obligations.
                foreach ($auto as $draft) {
                    if ((string) $draft->status !== 'draft') continue;
                    $fresh = array_values(array_filter($this->obligations->resolve($bookingId, (int) $draft->id), fn (array $row): bool => ($row['status'] ?? '') === 'available' && (int) ($row['supplier_id'] ?? 0) === (int) $draft->supplier_id && (float) ($row['source_cost'] ?? 0) > 0));
                    if ($fresh !== []) {
                        $this->refreshDraft($draft, $fresh); $updated++; $refreshedSuppliers[(int) $draft->supplier_id] = true;
                    }
                }
                $rows = $this->obligations->resolve($bookingId);
                $groups = [];
                foreach ($rows as $row) {
                    if (($row['status'] ?? '') === 'available' && (int) ($row['supplier_id'] ?? 0) > 0 && (float) ($row['source_cost'] ?? 0) > 0) {
                        $groups[(int) $row['supplier_id']][] = $row;
                    } elseif (in_array((string) ($row['status'] ?? ''), ['vendor_required', 'cost_required', 'legacy_review', 'review_required'], true)) {
                        $attention++;
                    }
                }

                foreach ($groups as $supplierId => $sources) {
                    if (isset($refreshedSuppliers[$supplierId])) continue;
                    $draft = $auto->first(fn (object $row): bool => (int) $row->supplier_id === $supplierId && (string) $row->status === 'draft');
                    if (! $draft) {
                        $active = $existing->first(fn (object $row): bool => (int) $row->supplier_id === $supplierId && in_array((string) $row->status, ['pending_approval', 'approved', 'posted'], true));
                        if ($active) { $skipped++; continue; }
                        $id = $this->createDraft($bookingId, $supplierId, $sources, $user);
                        $created++; $auto = $auto->push(DB::table('supplier_costings')->where('id', $id)->first());
                        continue;
                    }
                    $this->refreshDraft($draft, $sources);
                    $updated++;
                }

                // A legacy active document has no source authority. Preserve it
                // and expose review_required rather than guessing or backfilling.
                if ($legacy->isNotEmpty()) $attention += $legacy->count();
                return ['ok' => true, 'code' => 'synced', 'created' => $created, 'updated' => $updated, 'attention' => $attention, 'skipped' => $skipped];
            });
        } catch (Throwable $exception) {
            report($exception);
            return ['ok' => false, 'code' => 'sync_failed', 'message' => $exception->getMessage(), 'created' => 0, 'updated' => 0, 'attention' => 1];
        }
    }

    private function isAutoManaged(int $costingId): bool
    {
        if (! Schema::hasTable('supplier_costing_activities')) return false;
        return DB::table('supplier_costing_activities')->where('supplier_costing_id', $costingId)
            ->whereIn('action', ['auto_create', 'auto_sync'])->exists();
    }

    private function createDraft(int $bookingId, int $supplierId, array $sources, mixed $user): int
    {
        $now = now(); $products = array_values(array_unique(array_map(fn (array $row): string => (string) $row['product_type'], $sources)));
        $supplierName = (string) ($sources[0]['supplier_name'] ?? ('Vendor #'.$supplierId));
        $id = DB::table('supplier_costings')->insertGetId([
            'costing_no' => $this->costing->nextNumber(), 'booking_id' => $bookingId,
            'supplier_id' => $supplierId, 'supplier_name' => $supplierName,
            'service_type' => count($products) === 1 ? $products[0] : 'Multiple Products',
            'cost_date' => $now->toDateString(), 'currency_code' => 'PKR', 'exchange_rate' => 1,
            'base_cost' => 0, 'tax_amount' => 0, 'other_charges' => 0, 'total_cost' => 0,
            'status' => 'draft', 'created_by' => is_object($user) ? ($user->id ?? null) : null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->replaceLines($id, $sources, []);
        $this->costing->recalculate((int) $id);
        $this->costing->activity((int) $id, 'auto_create', null, 'draft', is_object($user) ? $user : null, 'Automatically projected from booking supplier obligations.');
        return (int) $id;
    }

    private function refreshDraft(object $draft, array $sources): void
    {
        $links = DB::table('supplier_costing_source_links')->where('supplier_costing_id', $draft->id)->get()->keyBy('source_key');
        $adjustments = [];
        foreach ($links as $link) {
            $line = DB::table('supplier_costing_lines')->where('id', $link->supplier_costing_line_id)->first();
            if ($line) $adjustments[(string) $link->source_key] = ['tax_amount' => (float) $line->tax_amount, 'other_charges' => (float) $line->other_charges];
        }
        $products = array_values(array_unique(array_map(fn (array $row): string => (string) $row['product_type'], $sources)));
        DB::table('supplier_costings')->where('id', $draft->id)->update([
            'supplier_name' => (string) ($sources[0]['supplier_name'] ?? $draft->supplier_name),
            'service_type' => count($products) === 1 ? $products[0] : 'Multiple Products', 'updated_at' => now(),
        ]);
        $this->replaceLines((int) $draft->id, $sources, $adjustments);
        $this->costing->recalculate((int) $draft->id);
        $this->costing->activity((int) $draft->id, 'auto_sync', 'draft', 'draft', null, 'Automatically refreshed from booking supplier obligations.');
    }

    private function replaceLines(int $costingId, array $sources, array $adjustments): void
    {
        DB::table('supplier_costing_source_links')->where('supplier_costing_id', $costingId)->delete();
        DB::table('supplier_costing_lines')->where('supplier_costing_id', $costingId)->delete();
        $now = now();
        foreach (array_values($sources) as $index => $source) {
            $key = (string) $source['source_key']; $adjustment = $adjustments[$key] ?? ['tax_amount' => 0, 'other_charges' => 0];
            $base = round((float) $source['source_cost'], 2); $tax = round((float) $adjustment['tax_amount'], 2); $other = round((float) $adjustment['other_charges'], 2);
            $lineId = DB::table('supplier_costing_lines')->insertGetId([
                'supplier_costing_id' => $costingId, 'line_no' => $index + 1,
                'service_type' => $source['product_type'], 'description' => $source['source_reference'],
                'passenger_id' => $source['passenger_id'] ?? null, 'passenger_name' => $source['detail'] ?: ($source['passenger_name'] ?? null),
                'supplier_service_ref' => $source['source_reference'], 'base_cost' => $base, 'tax_amount' => $tax,
                'other_charges' => $other, 'total_cost' => round($base + $tax + $other, 2),
                'source_snapshot_json' => json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('supplier_costing_source_links')->insert([
                'supplier_costing_id' => $costingId, 'supplier_costing_line_id' => $lineId,
                'booking_id' => $source['booking_id'], 'booking_service_id' => $source['booking_service_id'],
                'source_type' => $source['source_type'], 'source_id' => $source['source_id'], 'source_key' => $key,
                'supplier_id' => $source['supplier_id'], 'product_type' => $source['product_type'],
                'source_cost_snapshot' => $base, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
