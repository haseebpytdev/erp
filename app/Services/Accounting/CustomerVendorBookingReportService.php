<?php

namespace App\Services\Accounting;

use App\Services\Administration\ErpBranchAccessAuthority;
use App\Services\Administration\ErpRoleAccessPolicy;
use App\Services\Operations\BookingProductSummaryResolver;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Read-only operational booking activity projection.
 *
 * This deliberately reads saved booking/service snapshots and draft supplier
 * costings; it never creates or alters accounting documents.
 */
final class CustomerVendorBookingReportService
{
    /**
     * Opt-in diagnostics for isolated local/testing profiling only. These are
     * never enabled for normal ERP requests and deliberately omit SQL text and
     * bindings so no report data can leak through diagnostics.
     */
    private bool $profileEnabled = false;
    private int $profileQueryCount = 0;
    private float $profileQueryDurationMs = 0.0;
    private int $profileResolverCalls = 0;
    private int $profileStartedAt = 0;
    private array $profileDurations = [];

    public function __construct(
        private readonly BookingProductSummaryResolver $products,
        private readonly ErpBranchAccessAuthority $branches,
    ) {}

    public function report(Request $request, int $perPage = 50): array
    {
        $this->startProfiling($request);
        abort_unless(Schema::hasTable('bookings'), 503, 'Booking report data is unavailable.');
        $filters = $this->filters($request);
        $partyOptions = $this->partyOptions($filters);
        if (! $request->boolean('generate')) {
            return $this->emptyReport($filters, $partyOptions);
        }
        if ($filters['party_id'] < 1 || ! collect($partyOptions)->contains(fn (array $party): bool => (int) $party['id'] === $filters['party_id'])) {
            throw ValidationException::withMessages(['party_id' => 'Select an authorized party before generating the report.']);
        }
        $columns = Schema::getColumnListing('bookings');
        $query = DB::table('bookings');
        $id = 'id';
        $query->where(function ($q) use ($columns, $filters): void {
            if ($filters['company_id'] !== null && in_array('company_id', $columns, true)) $q->where('company_id', $filters['company_id']);
            if ($filters['branch_ids'] !== null && in_array('branch_id', $columns, true)) {
                if ($filters['branch_ids'] === []) $q->whereRaw('1 = 0');
                else $q->whereIn('branch_id', $filters['branch_ids']);
            }
        });
        if ($filters['from'] && in_array('booking_date', $columns, true)) $query->whereDate('booking_date', '>=', $filters['from']);
        if ($filters['to'] && in_array('booking_date', $columns, true)) $query->whereDate('booking_date', '<=', $filters['to']);
        if ($filters['status'] !== '' && in_array('status', $columns, true)) $query->where('status', $filters['status']);
        elseif (! $filters['include_cancelled'] && in_array('status', $columns, true)) $query->whereNotIn(DB::raw('LOWER(status)'), ['cancelled', 'canceled']);
        if ($filters['booking_type'] !== '' && in_array('booking_type', $columns, true)) $query->where('booking_type', $filters['booking_type']);
        if ($filters['search'] !== '') {
            $searchColumns = array_values(array_filter(['booking_no', 'booking_number', 'reference'], fn (string $c): bool => in_array($c, $columns, true)));
            if ($searchColumns) $query->where(function ($q) use ($searchColumns, $filters): void { foreach ($searchColumns as $column) $q->orWhere($column, 'like', '%'.$filters['search'].'%'); });
        }
        if ($filters['tab'] === 'customer' && $filters['party_id'] > 0) {
            $partyColumns = array_values(array_filter(['customer_party_id', 'agent_party_id', 'customer_id', 'agent_id'], fn (string $c): bool => in_array($c, $columns, true)));
            if ($partyColumns) $query->where(function ($q) use ($partyColumns, $filters): void { foreach ($partyColumns as $column) $q->orWhere($column, $filters['party_id']); });
        }
        $sortColumn = match ($filters['sort']) {
            'amount' => in_array('booking_date', $columns, true) ? 'booking_date' : $id,
            'passengers' => $id,
            'party' => $id,
            default => in_array('booking_date', $columns, true) ? 'booking_date' : $id,
        };
        // Resolve the complete authorized filtered set before calculating any
        // derived values. This is required because sale/cost/passenger values
        // are authoritative service projections rather than booking columns.
        // Date ordering is kept in SQL; computed ordering is applied below.
        $query->orderBy($sortColumn, $filters['direction'])->orderBy($id, $filters['direction']);
        $bookingStartedAt = hrtime(true);
        $bookings = collect($query->get());
        $this->profileDuration('booking_query_ms', $bookingStartedAt);
        $rows = $this->rows($bookings, $filters, $partyOptions);
        $sortStartedAt = hrtime(true);
        $rows = $this->sortRows($rows, $filters);
        $this->profileDuration('sorting_ms', $sortStartedAt);
        $requestedSize = $filters['page_size'] === 'all' ? 0 : (int) $filters['page_size'];
        $effectivePerPage = $perPage === 0 ? 0 : ($perPage === 50 ? $requestedSize : $perPage);
        $pageSize = $effectivePerPage > 0 ? max(1, min(100, $effectivePerPage)) : max(1, $rows->count());
        $page = $effectivePerPage > 0 ? LengthAwarePaginator::resolveCurrentPage() : 1;
        $paginationStartedAt = hrtime(true);
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $pageSize)->values(),
            $rows->count(),
            $pageSize,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        $this->profileDuration('pagination_ms', $paginationStartedAt);
        $costStatus = in_array(strtolower((string) $request->query('cost_status', '')), ['draft','unapproved','approved','posted','unresolved'], true) ? ucfirst(strtolower((string) $request->query('cost_status', ''))) : '';
        return [
            'filters' => $filters,
            'paginator' => $paginator,
            'rows' => $rows,
            'metrics' => $this->metrics($rows),
            'parties' => $partyOptions,
            'statuses' => $this->distinct('status'),
            'bookingTypes' => $this->distinct('booking_type'),
            'costStatuses' => ['Draft', 'Unapproved', 'Approved', 'Posted', 'Unresolved'],
            'largeResultWarning' => $filters['page_size'] === 'all' && $rows->count() > 5000,
            'profile' => $this->profileData(),
            'generated' => true,
        ];
    }

    private function emptyReport(array $filters, array $partyOptions): array
    {
        $empty = collect();
        return [
            'filters' => $filters,
            'paginator' => new LengthAwarePaginator($empty, 0, 50, 1, ['path' => request()->url(), 'query' => request()->query()]),
            'rows' => $empty,
            'metrics' => ['bookings' => 0, 'passengers' => 0, 'sale' => 0.0, 'vendor' => 0.0],
            'parties' => $partyOptions,
            'statuses' => $this->distinct('status'),
            'bookingTypes' => $this->distinct('booking_type'),
            'costStatuses' => ['Draft', 'Unapproved', 'Approved', 'Posted', 'Unresolved'],
            'largeResultWarning' => false,
            'profile' => $this->profileData(),
            'generated' => false,
        ];
    }

    private function filters(Request $request): array
    {
        $range = strtolower((string) $request->query('date_range', 'all'));
        $today = now()->toDateString();
        $from = $to = null;
        if ($range === 'today') $from = $to = $today;
        elseif ($range === 'month') { $from = now()->startOfMonth()->toDateString(); $to = now()->endOfMonth()->toDateString(); }
        elseif ($range === 'custom') { $from = $this->date($request->query('from')); $to = $this->date($request->query('to')); }
        if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
        $tab = strtolower((string) $request->query('tab', 'customer')) === 'vendor' ? 'vendor' : 'customer';
        $branchId = $this->branches->branchFilter($request->user(), $request->query('branch_id'));
        $allowed = $this->branches->allowedBranchIds($request->user());
        if ($branchId !== null) $allowed = [$branchId];
        $status = trim((string) $request->query('status', ''));
        $includeCancelled = $request->boolean('include_cancelled')
            || ($status !== '' && in_array(strtolower($status), ['cancelled', 'canceled'], true));
        $costStatus = in_array(strtolower((string) $request->query('cost_status', '')), ['draft','unapproved','approved','posted','unresolved'], true)
            ? ucfirst(strtolower((string) $request->query('cost_status', '')))
            : '';
        $isSuperAdmin = $this->branches->isSuperAdmin($request->user());
        return [
            'tab' => $tab, 'date_range' => in_array($range, ['all','today','month','custom'], true) ? $range : 'all',
            'from' => $from, 'to' => $to, 'party_id' => max(0, (int) $request->query('party_id', 0)),
            'status' => $status, 'booking_type' => trim((string) $request->query('booking_type', '')),
            'sort' => in_array($request->query('sort'), ['date','amount','passengers','party'], true) ? (string) $request->query('sort') : 'date',
            'direction' => strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc',
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 120),
            'page_size' => in_array(strtolower((string) $request->query('page_size', '50')), ['50','60','100','all'], true) ? strtolower((string) $request->query('page_size', '50')) : '50',
            'cost_status' => $tab === 'vendor' ? $costStatus : '',
            'branch_ids' => $allowed, 'branch_id' => $branchId,
            'company_id' => $isSuperAdmin ? null : $this->branches->companyIdForUser($request->user()),
            'is_super_admin' => $isSuperAdmin,
            'include_cancelled' => $includeCancelled,
        ];
    }

    private function rows(Collection $bookings, array $filters, array $partyOptions): Collection
    {
        if ($bookings->isEmpty()) return collect();
        $ids = $bookings->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $passengerStartedAt = hrtime(true);
        $passengers = $this->passengerCounts($ids);
        $this->profileDuration('passenger_lookup_ms', $passengerStartedAt);
        $vendorStartedAt = hrtime(true);
        $vendors = $filters['tab'] === 'vendor' ? $this->vendorAllocations($ids) : [];
        $this->profileDuration('vendor_allocation_ms', $vendorStartedAt);
        $parties = $filters['tab'] === 'customer' ? collect($partyOptions)->pluck('name', 'id')->all() : [];
        $result = collect();
        foreach ($bookings as $booking) {
            $id = (int) $booking->id;
            $sale = 0.0;
            if ($filters['tab'] === 'customer') {
                $resolverStartedAt = hrtime(true);
                $this->profileResolverCalls++;
                $summary = $this->products->resolve($id);
                $this->profileDuration('product_summary_resolution_ms', $resolverStartedAt, true);
                foreach (['air','hotel','transport','visa'] as $product) $sale += (float) ($summary[$product]['customer_total'] ?? 0);
                // The unified Complete Package is a booking-level commercial
                // parent, not a native booking_service row. Add its canonical
                // final sale once; included component rows remain excluded by
                // the resolver and cannot double-count this amount.
                $sale += (float) ($this->products->completePackageSummary($id)['customer_total'] ?? 0);
            }
            $base = [
                'booking_id' => $id,
                'booking_no' => (string) ($booking->booking_no ?? $booking->booking_number ?? $booking->reference ?? ('Booking #'.$id)),
                'booking_date' => (string) ($booking->booking_date ?? ''),
                'created_at' => (string) ($booking->created_at ?? ''),
                'customer' => $this->partyName($booking, $parties),
                'booking_type' => (string) ($booking->booking_type ?? '—'),
                'passengers' => (int) ($passengers[$id] ?? 0),
                'status' => (string) ($booking->status ?? '—'),
                'sale' => round($sale, 2),
            ];
            if ($filters['tab'] === 'customer') { $base['vendor'] = null; $base['vendor_cost'] = null; $result->push($base); continue; }
            $allocations = $vendors[$id] ?? [['vendor_id' => null, 'vendor' => 'Unassigned / unresolved', 'cost' => null, 'status' => 'Unresolved']];
            if ($filters['party_id'] > 0) $allocations = array_values(array_filter($allocations, fn (array $allocation): bool => (int) ($allocation['vendor_id'] ?? 0) === $filters['party_id']));
            if ($filters['cost_status'] !== '') $allocations = array_values(array_filter($allocations, fn (array $allocation): bool => strcasecmp((string) ($allocation['status'] ?? ''), $filters['cost_status']) === 0));
            if ($allocations === []) continue;
            foreach ($allocations as $allocation) $result->push($base + ['vendor' => $allocation['vendor'], 'vendor_cost' => $allocation['cost'], 'cost_status' => $allocation['status']]);
        }
        return $result;
    }

    private function passengerCounts(array $ids): array
    {
        if (! Schema::hasTable('booking_passengers')) return [];
        $columns = Schema::getColumnListing('booking_passengers'); $query = DB::table('booking_passengers')->whereIn('booking_id', $ids);
        if (in_array('is_active', $columns, true)) $query->where('is_active', 1);
        if (in_array('status', $columns, true)) $query->whereNotIn(DB::raw('LOWER(status)'), ['removed','deleted','inactive']);
        return $query->select('booking_id')->selectRaw('COUNT(*) as total')->groupBy('booking_id')->pluck('total','booking_id')->map(fn ($v): int => (int) $v)->all();
    }

    private function vendorAllocations(array $ids): array
    {
        $result = [];
        if (Schema::hasTable('supplier_costings')) {
            $columns = Schema::getColumnListing('supplier_costings');
            if (in_array('booking_id', $columns, true)) foreach (DB::table('supplier_costings')->whereIn('booking_id', $ids)->get() as $row) {
                if (in_array('status', $columns, true) && in_array(strtolower((string) $row->status), ['cancelled','deleted','reversed'], true)) continue;
                $result[(int) $row->booking_id][] = ['vendor_id' => (int) ($row->supplier_id ?? 0) ?: null, 'vendor' => (string) ($row->supplier_name ?? 'Unassigned / unresolved'), 'cost' => isset($row->total_cost) ? round((float) $row->total_cost, 2) : null, 'status' => (string) ($row->status ?? 'Draft')];
            }
        }
        // Some installed releases persist vendor costing only on the saved
        // booking-service snapshot. Use it only when no supplier-costing row
        // exists for that booking, so posted/draft costing is never doubled.
        if (Schema::hasTable('booking_services')) {
            $columns = Schema::getColumnListing('booking_services');
            $vendorColumn = collect(['vendor_id', 'supplier_id'])->first(fn (string $column): bool => in_array($column, $columns, true));
            $costColumn = collect(['supplier_cost', 'vendor_cost', 'cost', 'supplier_amount'])->first(fn (string $column): bool => in_array($column, $columns, true));
            // Vendor association and payable amount are separate contracts.
            // A saved service vendor qualifies a booking for vendor activity
            // even when no supplier-cost column exists on booking_services.
            // In that case cost remains null (informational only); never
            // reinterpret line_total as a supplier payable.
            if ($vendorColumn && in_array('booking_id', $columns, true)) {
                $select = ['booking_id', $vendorColumn];
                if ($costColumn) $select[] = $costColumn;
                $serviceRows = DB::table('booking_services')->whereIn('booking_id', $ids)->whereNotNull($vendorColumn)->get($select);
                foreach ($serviceRows->groupBy('booking_id') as $bookingId => $group) {
                    if (isset($result[(int) $bookingId])) continue;
                    foreach ($group->groupBy($vendorColumn) as $vendorId => $vendorRows) {
                        $name = (string) ($vendorRows->first()->vendor_name ?? $vendorRows->first()->supplier_name ?? ('Vendor #'.$vendorId));
                        $cost = $costColumn ? round((float) $vendorRows->sum($costColumn), 2) : null;
                        $result[(int) $bookingId][] = ['vendor_id' => (int) $vendorId, 'vendor' => $name, 'cost' => $cost, 'status' => (string) ($vendorRows->first()->status ?? 'Draft')];
                    }
                }
            }
        }
        if (Schema::hasTable('booking_group_package_unified')) {
            $columns = Schema::getColumnListing('booking_group_package_unified');
            if (in_array('booking_id', $columns, true) && in_array('vendor_id', $columns, true) && in_array('supplier_cost', $columns, true)) {
                $packageRows = DB::table('booking_group_package_unified')->whereIn('booking_id', $ids)->whereNotNull('vendor_id')->get();
                foreach ($packageRows->groupBy('booking_id') as $bookingId => $group) {
                    if (isset($result[(int) $bookingId])) continue;
                    foreach ($group->groupBy('vendor_id') as $vendorId => $vendorRows) {
                        $first = $vendorRows->first();
                        $status = (string) ($first->save_status ?? $first->booking_workflow_status ?? 'Draft');
                        $result[(int) $bookingId][] = [
                            'vendor_id' => (int) $vendorId,
                            'vendor' => (string) ($first->vendor_name ?? ('Vendor #'.$vendorId)),
                            'cost' => round((float) $vendorRows->sum('supplier_cost'), 2),
                            'status' => ucfirst(strtolower($status)),
                        ];
                    }
                }
            }
        }
        // Product-specific native detail rows are authoritative when no
        // supplier-costing/service/package allocation exists. Collect all
        // product vendors first so a booking with multiple vendors remains
        // separated without duplicating an earlier authoritative allocation.
        $detailAllocations = [];
        if (Schema::hasTable('air_ticket_details') && Schema::hasTable('booking_services')) {
            $columns = Schema::getColumnListing('air_ticket_details');
            if (in_array('booking_service_id', $columns, true) && in_array('supplier_vendor_party_id', $columns, true)) {
                $cost = collect(['net_supplier_cost','supplier_total','vendor_total','cost_price'])->first(fn (string $c): bool => in_array($c, $columns, true));
                $select = ['hbs.booking_id', 'atd.supplier_vendor_party_id']; if ($cost) $select[] = 'atd.'.$cost.' as vendor_cost';
                $rows = DB::table('air_ticket_details as atd')->join('booking_services as hbs', 'hbs.id', '=', 'atd.booking_service_id')->whereIn('hbs.booking_id', $ids)->whereNotNull('atd.supplier_vendor_party_id')->get($select);
                foreach ($rows as $row) $detailAllocations[(int) $row->booking_id][] = [(int) $row->supplier_vendor_party_id, (float) ($row->vendor_cost ?? 0)];
            }
        }
        if (Schema::hasTable('booking_visa_services') && Schema::hasColumn('booking_visa_services', 'booking_id') && Schema::hasColumn('booking_visa_services', 'vendor_id')) {
            $columns = Schema::getColumnListing('booking_visa_services'); $cost = collect(['vendor_cost_pkr','supplier_total','vendor_total','cost_price'])->first(fn (string $c): bool => in_array($c, $columns, true));
            $select = ['booking_id','vendor_id']; if ($cost) $select[] = $cost.' as vendor_cost';
            foreach (DB::table('booking_visa_services')->whereIn('booking_id', $ids)->whereNotNull('vendor_id')->get($select) as $row) $detailAllocations[(int) $row->booking_id][] = [(int) $row->vendor_id, (float) ($row->vendor_cost ?? 0)];
        }
        if (Schema::hasTable('transport_booking_details') && Schema::hasTable('booking_services')) {
            $columns = Schema::getColumnListing('transport_booking_details');
            $serviceCol = in_array('booking_service_id', $columns, true) ? 'booking_service_id' : null;
            $direct = collect(['vendor_id','supplier_id','service_provider_id'])->first(fn (string $c): bool => in_array($c, $columns, true));
            $company = in_array('transport_company_id', $columns, true) ? 'transport_company_id' : null;
            $cost = collect(['cost_price','vendor_cost','supplier_cost','cost_amount'])->first(fn (string $c): bool => in_array($c, $columns, true));
            if ($serviceCol && ($direct || $company)) {
                $select = ['t.'.$serviceCol]; if ($direct) $select[] = 't.'.$direct.' as vendor_id'; if ($company) $select[] = 't.'.$company.' as transport_company_id'; if ($cost) $select[] = 't.'.$cost.' as vendor_cost';
                $transportRows = DB::table('transport_booking_details as t')->join('booking_services as hbs', 'hbs.id', '=', 't.'.$serviceCol)->whereIn('hbs.booking_id', $ids)->get(array_merge(['hbs.booking_id'], $select));
                foreach ($transportRows as $row) {
                    $vendorId = (int) ($row->vendor_id ?? 0);
                    if ($vendorId <= 0 && (int) ($row->transport_company_id ?? 0) > 0 && Schema::hasTable('travel_voucher_partners') && Schema::hasColumn('travel_voucher_partners','default_vendor_party_id')) $vendorId = (int) (DB::table('travel_voucher_partners')->where('id', (int) $row->transport_company_id)->value('default_vendor_party_id') ?? 0);
                    if ($vendorId > 0) $detailAllocations[(int) $row->booking_id][] = [$vendorId, (float) ($row->vendor_cost ?? 0)];
                }
            }
        }
        if (Schema::hasTable('hotel_booking_details') && Schema::hasTable('booking_services')) {
            $columns = Schema::getColumnListing('hotel_booking_details');
            if (in_array('booking_service_id', $columns, true) && in_array('supplier_vendor_party_id', $columns, true)) {
                $rows = DB::table('hotel_booking_details as hbd')->join('booking_services as hbs', 'hbs.id', '=', 'hbd.booking_service_id')->whereIn('hbs.booking_id', $ids)->whereNotNull('hbd.supplier_vendor_party_id')->get(['hbs.booking_id','hbd.supplier_vendor_party_id','hbd.net_supplier_cost']);
                foreach ($rows as $row) $detailAllocations[(int) $row->booking_id][] = [(int) $row->supplier_vendor_party_id, (float) ($row->net_supplier_cost ?? 0)];
            }
        }
        foreach ($detailAllocations as $bookingId => $allocations) {
            if (isset($result[(int) $bookingId])) continue;
            foreach (collect($allocations)->groupBy(fn (array $a): int => $a[0]) as $vendorId => $rows) {
                $name = (string) (DB::table('parties')->where('id', (int) $vendorId)->value('display_name') ?? ('Vendor #'.$vendorId));
                $result[(int) $bookingId][] = ['vendor_id' => (int) $vendorId, 'vendor' => $name, 'cost' => round((float) collect($rows)->sum(fn (array $a): float => $a[1]), 2), 'status' => 'Draft'];
            }
        }
        return $result;
    }

    private function sortRows(Collection $rows, array $filters): Collection
    {
        $field = match ($filters['sort']) {
            'amount' => $filters['tab'] === 'vendor' ? 'vendor_cost' : 'sale',
            'passengers' => 'passengers',
            'party' => $filters['tab'] === 'vendor' ? 'vendor' : 'customer',
            default => 'booking_date',
        };
        return $rows->sortBy(
            fn (array $row): mixed => $row[$field] ?? ($filters['direction'] === 'asc' ? null : ''),
            SORT_NATURAL | SORT_FLAG_CASE,
            $filters['direction'] !== 'asc'
        )->values();
    }

    private function metrics(Collection $rows): array
    {
        $metricsStartedAt = hrtime(true);
        $bookingIds = $rows->pluck('booking_id')->unique()->values();
        $byBooking = [];
        foreach ($rows as $row) {
            $bookingId = (int) ($row['booking_id'] ?? 0);
            if (! array_key_exists($bookingId, $byBooking)) $byBooking[$bookingId] = $row;
        }
        $passengers = 0;
        $sale = 0.0;
        foreach ($bookingIds as $bookingId) {
            $row = $byBooking[(int) $bookingId] ?? [];
            $passengers += (int) ($row['passengers'] ?? 0);
            $sale += (float) ($row['sale'] ?? 0);
        }
        $result = ['bookings' => $bookingIds->count(), 'passengers' => $passengers, 'sale' => round($sale, 2), 'vendor' => round((float) $rows->sum(fn ($row): float => (float) ($row['vendor_cost'] ?? 0)), 2)];
        $this->profileDuration('metrics_ms', $metricsStartedAt);
        return $result;
    }

    private function partyOptions(array $filters): array
    {
        if (! Schema::hasTable('parties') || ! Schema::hasTable('party_roles')) return [];
        if (! $filters['is_super_admin'] && $filters['company_id'] === null) return [];
        $columns = Schema::getColumnListing('parties');
        $name = in_array('display_name', $columns, true) ? 'display_name' : (in_array('name', $columns, true) ? 'name' : null);
        if (! $name || $filters['branch_ids'] === []) return [];
        $query = DB::table('parties')->where('parties.is_active', 1);
        if ($filters['company_id'] !== null && in_array('company_id', $columns, true)) {
            $query->where('parties.company_id', $filters['company_id']);
        }
        $roles = $filters['tab'] === 'vendor' ? ['vendor', 'supplier'] : ['customer', 'agent'];
        $today = now()->toDateString();
        $query->whereExists(function ($roleQuery) use ($roles, $today): void {
            $roleQuery->selectRaw('1')->from('party_roles as pr')
                ->whereColumn('pr.party_id', 'parties.id')
                ->where('pr.is_active', 1)
                ->whereIn(DB::raw('LOWER(pr.role)'), $roles)
                ->where(function ($q) use ($today): void { $q->whereNull('pr.starts_on')->orWhereDate('pr.starts_on', '<=', $today); })
                ->where(function ($q) use ($today): void { $q->whereNull('pr.ends_on')->orWhereDate('pr.ends_on', '>=', $today); });
        });
        if ($filters['branch_ids'] !== null) {
            if (! Schema::hasTable('bookings')) return [];
            $query->whereExists(function ($bookingQuery) use ($filters): void {
                $bookingQuery->selectRaw('1')->from('bookings')
                    ->whereColumn('bookings.company_id', 'parties.company_id')
                    ->where(function ($q) use ($filters): void {
                        if ($filters['tab'] === 'customer') {
                            $q->whereColumn('bookings.customer_party_id', 'parties.id')
                                ->orWhereColumn('bookings.agent_party_id', 'parties.id');
                            return;
                        }
                        $hasVendorSource = false;
                        if (Schema::hasTable('supplier_costings')) {
                            $q->whereExists(fn ($source) => $source->selectRaw('1')->from('supplier_costings')->whereColumn('supplier_costings.booking_id', 'bookings.id')->whereColumn('supplier_costings.supplier_id', 'parties.id'));
                            $hasVendorSource = true;
                        }
                        if (Schema::hasTable('booking_services')) {
                            $source = fn ($sourceQuery) => $sourceQuery->selectRaw('1')->from('booking_services')->whereColumn('booking_services.booking_id', 'bookings.id')->whereColumn('booking_services.vendor_id', 'parties.id');
                            $hasVendorSource ? $q->orWhereExists($source) : $q->whereExists($source);
                            $hasVendorSource = true;
                        }
                        if (Schema::hasTable('booking_group_package_unified')) {
                            $source = fn ($sourceQuery) => $sourceQuery->selectRaw('1')->from('booking_group_package_unified')->whereColumn('booking_group_package_unified.booking_id', 'bookings.id')->whereColumn('booking_group_package_unified.vendor_id', 'parties.id');
                            $hasVendorSource ? $q->orWhereExists($source) : $q->whereExists($source);
                            $hasVendorSource = true;
                        }
                        if (Schema::hasTable('hotel_booking_details') && Schema::hasTable('booking_services')) {
                            $source = fn ($sourceQuery) => $sourceQuery->selectRaw('1')
                                ->from('hotel_booking_details as hbd')
                                ->join('booking_services as hbs', 'hbs.id', '=', 'hbd.booking_service_id')
                                ->whereColumn('hbs.booking_id', 'bookings.id')
                                ->whereColumn('hbd.supplier_vendor_party_id', 'parties.id');
                            $hasVendorSource ? $q->orWhereExists($source) : $q->whereExists($source);
                            $hasVendorSource = true;
                        }
                        if (Schema::hasTable('air_ticket_details') && Schema::hasTable('booking_services') && Schema::hasColumn('air_ticket_details','booking_service_id') && Schema::hasColumn('air_ticket_details','supplier_vendor_party_id')) {
                            $source = fn ($sourceQuery) => $sourceQuery->selectRaw('1')->from('air_ticket_details as atd')->join('booking_services as hbs', 'hbs.id', '=', 'atd.booking_service_id')->whereColumn('hbs.booking_id', 'bookings.id')->whereColumn('atd.supplier_vendor_party_id', 'parties.id');
                            $hasVendorSource ? $q->orWhereExists($source) : $q->whereExists($source); $hasVendorSource = true;
                        }
                        if (Schema::hasTable('booking_visa_services') && Schema::hasColumn('booking_visa_services','booking_id') && Schema::hasColumn('booking_visa_services','vendor_id')) {
                            $source = fn ($sourceQuery) => $sourceQuery->selectRaw('1')->from('booking_visa_services as bvs')->whereColumn('bvs.booking_id', 'bookings.id')->whereColumn('bvs.vendor_id', 'parties.id');
                            $hasVendorSource ? $q->orWhereExists($source) : $q->whereExists($source); $hasVendorSource = true;
                        }
                        if (Schema::hasTable('transport_booking_details') && Schema::hasTable('booking_services')) {
                            $transportCols = Schema::getColumnListing('transport_booking_details');
                            if (in_array('booking_service_id', $transportCols, true) && (in_array('vendor_id', $transportCols, true) || in_array('supplier_id', $transportCols, true) || in_array('service_provider_id', $transportCols, true))) {
                                $vendorCol = collect(['vendor_id','supplier_id','service_provider_id'])->first(fn (string $c): bool => in_array($c, $transportCols, true));
                                $source = fn ($sourceQuery) => $sourceQuery->selectRaw('1')->from('transport_booking_details as tbd')->join('booking_services as hbs', 'hbs.id', '=', 'tbd.booking_service_id')->whereColumn('hbs.booking_id', 'bookings.id')->whereColumn('tbd.'.$vendorCol, 'parties.id');
                                $hasVendorSource ? $q->orWhereExists($source) : $q->whereExists($source); $hasVendorSource = true;
                            }
                            if (in_array('booking_service_id', $transportCols, true) && in_array('transport_company_id', $transportCols, true) && Schema::hasTable('travel_voucher_partners') && Schema::hasColumn('travel_voucher_partners','default_vendor_party_id')) {
                                $source = fn ($sourceQuery) => $sourceQuery->selectRaw('1')->from('transport_booking_details as tbd')->join('booking_services as hbs', 'hbs.id', '=', 'tbd.booking_service_id')->join('travel_voucher_partners as tvp', 'tvp.id', '=', 'tbd.transport_company_id')->whereColumn('hbs.booking_id', 'bookings.id')->whereColumn('tvp.default_vendor_party_id', 'parties.id');
                                $hasVendorSource ? $q->orWhereExists($source) : $q->whereExists($source); $hasVendorSource = true;
                            }
                        }
                        if (! $hasVendorSource) {
                            $q->whereRaw('1 = 0');
                        }
                    });
                if ($filters['branch_ids'] === []) $bookingQuery->whereRaw('1 = 0');
                else $bookingQuery->whereIn('bookings.branch_id', $filters['branch_ids']);
            });
        }
        return $query->orderBy('parties.'.$name)->limit(2000)
            ->get(['parties.id', 'parties.'.$name.' as name'])
            ->map(fn ($row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->unique('id')->values()->all();
    }

    private function partyName(object $booking, array $parties): string
    {
        foreach (['customer_party_id','customer_id','agent_party_id','agent_id'] as $field) if (! empty($booking->{$field})) return $parties[(int) $booking->{$field}] ?? 'Party #'.(int) $booking->{$field};
        return 'Unassigned / unresolved';
    }

    private function distinct(string $column): array
    {
        if (! Schema::hasTable('bookings') || ! Schema::hasColumn('bookings', $column)) return [];
        return DB::table('bookings')->whereNotNull($column)->distinct()->orderBy($column)->pluck($column)->map(fn ($v): string => (string) $v)->filter()->values()->all();
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value); if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function startProfiling(Request $request): void
    {
        $this->profileEnabled = app()->environment(['local', 'testing']) && $request->boolean('_report_profile');
        if (! $this->profileEnabled && app()->environment('production')) {
            $this->profileEnabled = $this->consumeProductionProfileToken($request);
        }
        $this->profileQueryCount = 0;
        $this->profileQueryDurationMs = 0.0;
        $this->profileResolverCalls = 0;
        $this->profileDurations = [];
        $this->profileStartedAt = hrtime(true);
        if (! $this->profileEnabled) return;

        DB::listen(function ($query): void {
            // Aggregate timing only; never retain SQL text or bindings.
            if ($this->profileQueryCount < 10000) $this->profileQueryCount++;
            $this->profileQueryDurationMs += (float) ($query->time ?? 0);
        });
    }

    /**
     * One-use production diagnostic gate. The token is supplied only through
     * an operator-added header, never a URL, and is accepted only for the
     * existing report route by a super-admin. It expires after five minutes.
     */
    private function consumeProductionProfileToken(Request $request): bool
    {
        $routeName = (string) optional($request->route())->getName();
        if (! str_starts_with($routeName, 'accounting.customer-vendor-bookings.')) return false;
        $user = $request->user();
        if (! $user || ! app(ErpRoleAccessPolicy::class)->isSuperAdmin($user)) return false;
        $expected = trim((string) env('ERP_REPORT_PROFILE_TOKEN', ''));
        $presented = trim((string) $request->header('X-ERP-Report-Profile', ''));
        if ($expected === '' || $presented === '' || ! hash_equals($expected, $presented)) return false;
        return Cache::add('erp-report-profile-once:'.hash('sha256', $presented), true, now()->addMinutes(5));
    }

    private function profileDuration(string $name, int $startedAt, bool $accumulate = false): void
    {
        if (! $this->profileEnabled) return;
        $duration = (hrtime(true) - $startedAt) / 1_000_000;
        if ($accumulate) $this->profileDurations[$name] = ($this->profileDurations[$name] ?? 0.0) + $duration;
        else $this->profileDurations[$name] = $duration;
    }

    private function profileData(): ?array
    {
        if (! $this->profileEnabled) return null;
        return $this->profileDurations + [
            'total_ms' => round((hrtime(true) - $this->profileStartedAt) / 1_000_000, 3),
            'query_count' => $this->profileQueryCount,
            'query_duration_ms' => round($this->profileQueryDurationMs, 3),
            'resolver_calls' => $this->profileResolverCalls,
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ];
    }
}
