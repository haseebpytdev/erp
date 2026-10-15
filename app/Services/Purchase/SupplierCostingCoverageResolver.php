<?php

namespace App\Services\Purchase;

use App\Services\Administration\ErpCommercialBranchAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, bounded operational projection for Supplier Costing coverage. */
final class SupplierCostingCoverageResolver
{
    private const PAGE_SIZE = 50;

    public function __construct(private readonly BookingSupplierObligationResolver $obligations, private readonly ErpCommercialBranchAuthority $commercialBranches) {}

    /** @return array<string,mixed> */
    public function resolve(int $page = 1, string $search = ''): array
    {
        $page = max(1, $page); $search = trim($search);
        [$ids, $total, $lastPage, $normalizedPage] = $this->candidatePage($page, $search, request()->user());
        $queue = []; $ready = 0; $attention = 0;
        foreach ($ids as $bookingId) {
            $rows = $this->obligations->resolve($bookingId);
            $reference = $this->bookingReference($bookingId);
            $available = array_values(array_filter($rows, fn (array $row): bool => ($row['status'] ?? '') === 'available' && (int) ($row['supplier_id'] ?? 0) > 0 && (float) ($row['source_cost'] ?? 0) > 0));
            $groups = [];
            foreach ($available as $row) $groups[(int) $row['supplier_id']][] = $row;
            foreach ($groups as $supplierId => $sources) { $ready++; $queue[] = $this->readyRow($bookingId, $reference, $supplierId, $sources); }
            foreach ($rows as $row) {
                if (! in_array((string) ($row['status'] ?? ''), ['vendor_required', 'cost_required', 'legacy_review', 'review_required'], true)) continue;
                $attention++; $queue[] = $this->issueRow($bookingId, $reference, $row);
            }
            foreach ($this->staleAutoDraftRows($bookingId, $reference) as $row) { $attention++; $queue[] = $row; }
        }
        $from = $total ? (($normalizedPage - 1) * self::PAGE_SIZE) + 1 : 0; $to = $total ? min($total, $normalizedPage * self::PAGE_SIZE) : 0;
        return ['bounded' => true, 'candidate_limit' => self::PAGE_SIZE, 'candidate_total' => $total, 'candidate_from' => $from, 'candidate_to' => $to, 'page_size' => self::PAGE_SIZE, 'coverage_page' => $normalizedPage, 'coverage_last_page' => $lastPage, 'search' => $search, 'cards' => $this->lifecycleCards($ready, $attention), 'queue' => $queue, 'queue_total' => count($queue), 'queue_page' => $normalizedPage, 'queue_last_page' => $lastPage, 'empty_message' => $queue === [] ? 'No ready or attention items exist in this coverage page.' : ''];
    }

    /** @return array{0:list<int>,1:int,2:int,3:int} */
    private function candidatePage(int $page, string $search, mixed $user): array
    {
        if (! Schema::hasTable('bookings')) return [[], 0, 1, 1];
        $columns = Schema::getColumnListing('bookings'); $status = collect(['status', 'approval_status', 'workflow_status', 'booking_status'])->first(fn (string $field): bool => in_array($field, $columns, true)); $reference = collect(['booking_reference', 'booking_ref', 'booking_no', 'booking_number', 'reference'])->first(fn (string $field): bool => in_array($field, $columns, true));
        $base = DB::table('bookings');
        $allowed = app(\App\Services\Administration\ErpBranchAccessAuthority::class)->allowedBranchIds($user);
        if ($allowed === []) $base->whereRaw('1 = 0');
        elseif ($allowed !== null) $base->whereIn('branch_id', $allowed)->whereNotNull('company_id');
        if ($status) $base->whereIn($status, ['approved', 'Approved', 'confirmed', 'Confirmed', 'travel ready', 'Travel Ready']);
        if ($search !== '' && $reference) $base->where($reference, 'like', '%'.mb_substr($search, 0, 120).'%');
        $total = (clone $base)->count(); $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE)); $normalizedPage = min(max(1, $page), $lastPage);
        return [(clone $base)->orderByDesc('id')->forPage($normalizedPage, self::PAGE_SIZE)->pluck('id')->map(fn ($id): int => (int) $id)->all(), $total, $lastPage, $normalizedPage];
    }

    private function readyRow(int $bookingId, string $reference, int $supplierId, array $sources): array
    {
        return ['state' => 'ready', 'state_label' => 'Ready for Costing', 'issue' => '', 'action' => 'Create Draft', 'action_url' => route('purchase.supplier-costing.booking-draft', ['booking' => $bookingId, 'supplier' => $supplierId]), 'booking_id' => $bookingId, 'booking_reference' => $reference, 'supplier_id' => $supplierId, 'supplier_name' => (string) ($sources[0]['supplier_name'] ?? 'Vendor #'.$supplierId), 'products' => implode(', ', array_values(array_unique(array_map(fn (array $row): string => (string) $row['product_type'], $sources)))), 'items' => count($sources), 'total' => round(array_sum(array_map(fn (array $row): float => (float) $row['source_cost'], $sources)), 2)];
    }

    private function issueRow(int $bookingId, string $reference, array $row): array
    {
        $state = (string) ($row['status'] ?? 'review_required'); $labels = ['vendor_required' => 'Vendor Required', 'cost_required' => 'Cost Required', 'legacy_review' => 'Legacy Review', 'review_required' => 'Review Required']; $action = in_array($state, ['legacy_review', 'review_required'], true) && (int) ($row['costing_id'] ?? 0) > 0 ? 'Open Costing' : 'Open Booking';
        return ['state' => $state, 'state_label' => $labels[$state] ?? 'Review Required', 'issue' => (string) ($row['status_label'] ?? 'Supplier Costing requires review.'), 'action' => $action, 'action_url' => $action === 'Open Costing' ? route('purchase.supplier-costing.show', ['costing' => (int) $row['costing_id']]) : route('bookings.review.show', ['booking' => $bookingId]), 'booking_id' => $bookingId, 'booking_reference' => $reference, 'supplier_id' => (int) ($row['supplier_id'] ?? 0), 'supplier_name' => (string) ($row['supplier_name'] ?? '—'), 'products' => (string) ($row['product_type'] ?? 'Other'), 'items' => 1, 'total' => round((float) ($row['source_cost'] ?? 0), 2), 'costing_id' => (int) ($row['costing_id'] ?? 0)];
    }

    /** @return list<array<string,mixed>> */
    private function staleAutoDraftRows(int $bookingId, string $reference): array
    {
        if (! Schema::hasTable('supplier_costings') || ! Schema::hasTable('supplier_costing_source_links') || ! Schema::hasTable('supplier_costing_activities')) return [];
        $result = [];
        foreach (DB::table('supplier_costings')->where('booking_id', $bookingId)->where('status', 'draft')->get() as $draft) {
            $auto = DB::table('supplier_costing_activities')->where('supplier_costing_id', $draft->id)->whereIn('action', ['auto_create', 'auto_sync', 'auto_review_required'])->exists(); if (! $auto) continue;
            $links = DB::table('supplier_costing_source_links')->where('supplier_costing_id', $draft->id)->get(); $current = collect($this->obligations->resolve($bookingId, (int) $draft->id))->keyBy('source_key'); $reason = null;
            foreach ($links as $link) { $source = $current->get((string) $link->source_key); if (! $source) { $reason = 'Previously costed booking source is no longer present in authoritative booking data.'; break; } if ((int) ($source['supplier_id'] ?? 0) !== (int) $draft->supplier_id) { $reason = 'Authoritative booking vendor changed after this Draft was created.'; break; } if (($source['status'] ?? '') === 'already_costed' && (int) ($source['costing_id'] ?? 0) !== (int) $draft->id) { $reason = 'Booking source is claimed by another active Supplier Costing.'; break; } if ((int) ($source['supplier_id'] ?? 0) <= 0 || (float) ($source['source_cost'] ?? 0) <= 0) { $reason = 'Authoritative supplier or cost is no longer valid.'; break; } }
            $latest = DB::table('supplier_costing_activities')->where('supplier_costing_id', $draft->id)->whereIn('action', ['auto_create', 'auto_sync', 'auto_review_required'])->orderByDesc('id')->first(); if ($reason === null && (($latest->action ?? null) !== 'auto_review_required')) continue;
            $result[] = ['state' => 'review_required', 'state_label' => 'Review Required', 'issue' => $reason ?: (string) $latest->notes, 'action' => 'Open Costing', 'action_url' => route('purchase.supplier-costing.show', ['costing' => (int) $draft->id]), 'booking_id' => $bookingId, 'booking_reference' => $reference, 'supplier_id' => (int) $draft->supplier_id, 'supplier_name' => (string) ($draft->supplier_name ?: '—'), 'products' => (string) ($draft->service_type ?: 'Multiple Products'), 'items' => $links->count(), 'total' => (float) $draft->total_cost, 'costing_id' => (int) $draft->id];
        }
        return $result;
    }

    private function bookingReference(int $id): string
    {
        $columns = Schema::getColumnListing('bookings'); $field = collect(['booking_reference', 'booking_ref', 'booking_no', 'booking_number', 'reference'])->first(fn (string $name): bool => in_array($name, $columns, true)); return $field ? (string) (DB::table('bookings')->where('id', $id)->value($field) ?: 'Booking #'.$id) : 'Booking #'.$id;
    }

    private function lifecycleCards(int $ready, int $attention): array
    {
        $countQuery = DB::table('supplier_costings');
        $this->commercialBranches->scopeSupplierCostings($countQuery, request()->user());
        $counts = $countQuery->select('status', DB::raw('COUNT(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status');
        return [['key' => 'ready', 'label' => 'Ready for Costing', 'count' => $ready, 'scope' => 'Current coverage page'], ['key' => 'draft', 'label' => 'Draft', 'count' => (int) ($counts['draft'] ?? 0), 'scope' => 'Supplier Costing documents'], ['key' => 'pending_approval', 'label' => 'Pending Approval', 'count' => (int) ($counts['pending_approval'] ?? 0), 'scope' => 'Supplier Costing documents'], ['key' => 'approved', 'label' => 'Approved', 'count' => (int) ($counts['approved'] ?? 0), 'scope' => 'Supplier Costing documents'], ['key' => 'posted', 'label' => 'Posted', 'count' => (int) ($counts['posted'] ?? 0), 'scope' => 'Supplier Costing documents'], ['key' => 'attention', 'label' => 'Attention Required', 'count' => $attention, 'scope' => 'Current coverage page']];
    }
}
