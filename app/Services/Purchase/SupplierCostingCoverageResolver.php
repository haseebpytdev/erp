<?php

namespace App\Services\Purchase;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, bounded operational projection for Supplier Costing coverage. */
final class SupplierCostingCoverageResolver
{
    public function __construct(private readonly BookingSupplierObligationResolver $obligations) {}

    /** @return array<string,mixed> */
    public function resolve(int $page = 1, string $search = ''): array
    {
        $page = max(1, $page); $perPage = 25; $queue = []; $ready = 0; $attention = 0;
        $candidateIds = $this->candidateBookingIds();
        foreach ($candidateIds as $bookingId) {
            $rows = $this->obligations->resolve($bookingId);
            $available = array_values(array_filter($rows, fn (array $row): bool => ($row['status'] ?? '') === 'available'));
            $issues = array_values(array_filter($rows, fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['vendor_required', 'cost_required', 'legacy_review', 'review_required'], true)));
            if ($issues !== []) { $attention++; }
            if ($available === []) continue;
            $groups = [];
            foreach ($available as $row) $groups[(int) $row['supplier_id']][] = $row;
            foreach ($groups as $supplierId => $sources) {
                $reference = $this->bookingReference($bookingId);
                if ($search !== '' && ! str_contains(strtolower($reference.' '.($sources[0]['supplier_name'] ?? '')), strtolower($search))) continue;
                $ready++;
                $queue[] = [
                    'booking_id' => $bookingId, 'booking_reference' => $reference,
                    'supplier_id' => $supplierId, 'supplier_name' => (string) ($sources[0]['supplier_name'] ?? 'Vendor #'.$supplierId),
                    'products' => implode(', ', array_values(array_unique(array_map(fn (array $row): string => (string) $row['product_type'], $sources)))),
                    'items' => count($sources), 'total' => round(array_sum(array_map(fn (array $row): float => (float) $row['source_cost'], $sources)), 2),
                ];
            }
        }
        $cards = $this->lifecycleCards($ready, $attention);
        $total = count($queue); $offset = ($page - 1) * $perPage;
        return ['bounded' => true, 'candidate_limit' => 250, 'cards' => $cards, 'queue' => array_slice($queue, $offset, $perPage), 'queue_total' => $total, 'queue_page' => $page, 'queue_last_page' => max(1, (int) ceil($total / $perPage)), 'empty_message' => $total ? '' : 'No booking supplier obligations are ready for costing.'];
    }

    /** @return list<int> */
    private function candidateBookingIds(): array
    {
        if (! Schema::hasTable('bookings')) return [];
        $columns = Schema::getColumnListing('bookings'); $status = collect(['status', 'approval_status', 'workflow_status', 'booking_status'])->first(fn (string $field): bool => in_array($field, $columns, true));
        $query = DB::table('bookings')->orderByDesc('id')->limit(250);
        if ($status) $query->whereIn($status, ['approved', 'Approved', 'confirmed', 'Confirmed', 'travel ready', 'Travel Ready']);
        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function bookingReference(int $id): string
    {
        $columns = Schema::getColumnListing('bookings'); $field = collect(['booking_reference', 'booking_ref', 'booking_no', 'booking_number', 'reference'])->first(fn (string $name): bool => in_array($name, $columns, true));
        return $field ? (string) (DB::table('bookings')->where('id', $id)->value($field) ?: 'Booking #'.$id) : 'Booking #'.$id;
    }

    private function lifecycleCards(int $ready, int $attention): array
    {
        $counts = DB::table('supplier_costings')->select('status', DB::raw('COUNT(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status');
        return [
            ['key' => 'ready', 'label' => 'Ready for Costing', 'count' => $ready],
            ['key' => 'draft', 'label' => 'Draft', 'count' => (int) ($counts['draft'] ?? 0)],
            ['key' => 'pending_approval', 'label' => 'Pending Approval', 'count' => (int) ($counts['pending_approval'] ?? 0)],
            ['key' => 'approved', 'label' => 'Approved', 'count' => (int) ($counts['approved'] ?? 0)],
            ['key' => 'posted', 'label' => 'Posted', 'count' => (int) ($counts['posted'] ?? 0)],
            ['key' => 'attention', 'label' => 'Attention Required', 'count' => $attention],
        ];
    }
}
