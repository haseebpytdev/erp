<?php

namespace App\Services\Accounting;

use App\Services\Administration\ErpBranchAccessAuthority;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only hybrid booking/payment statement.
 *
 * Operational booking rows come from the existing booking report authority.
 * Cash movements come only from posted cash vouchers and verified posted
 * customer advance returns. Native journal invoice/cost rows are intentionally
 * excluded so a posted booking is never counted twice.
 */
final class BookingPaymentStatementService
{
    public function __construct(
        private readonly CustomerVendorBookingReportService $bookingReport,
        private readonly PartyStatementService $partyStatements,
        private readonly ErpBranchAccessAuthority $branches,
        private readonly CashVoucherLegacyBranchReconciliationService $legacyVoucherOwnership,
    ) {}

    public function filters(Request $request): array
    {
        $mode = strtolower((string) $request->query('mode', 'customer')) === 'vendor' ? 'vendor' : 'customer';
        $to = $this->date($request->query('to')) ?? now()->toDateString();
        $from = $this->date($request->query('from')) ?? Carbon::parse($to)->startOfYear()->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        $partyRequest = $request->duplicate(['party_type' => $mode]);
        $partyRequest->setUserResolver(fn () => $request->user());
        $partyFilters = $this->partyStatements->filters($partyRequest);
        $partyId = (int) $request->query('party_id', 0);
        $parties = $partyFilters['parties'];
        if ($partyId > 0 && ! collect($parties)->contains(fn (array $row): bool => (int) ($row['id'] ?? 0) === $partyId)) abort(403, 'The selected party is outside your authorized scope.');
        $selectedParty = $partyId > 0 ? collect($parties)->first(fn (array $row): bool => (int) ($row['id'] ?? 0) === $partyId) : null;
        $partyName = (string) ($selectedParty['name'] ?? '');
        $pageSize = strtolower((string) $request->query('page_size', '50'));
        if (! in_array($pageSize, ['50', '60', '100', 'all'], true)) $pageSize = '50';
        $isSuperAdmin = $this->branches->isSuperAdmin($request->user());
        return [
            'mode' => $mode, 'from' => $from, 'to' => $to, 'party_id' => $partyId, 'party_name' => $partyName,
            'parties' => $parties, 'branch_ids' => $this->branches->allowedBranchIds($request->user()),
            'company_id' => $isSuperAdmin ? null : $this->branches->companyIdForUser($request->user()),
            'super_admin_scope' => $isSuperAdmin,
            'page_size' => $pageSize,
        ];
    }

    public function statement(Request $request, bool $paginate = true): array
    {
        $filters = $this->filters($request);
        if ($filters['party_id'] < 1) return $this->emptyStatement($request, $filters);
        $movements = collect();
        $movements = $movements->merge($this->bookingMovements($request, $filters));
        $movements = $movements->merge($this->cashVoucherMovements($filters));
        if ($filters['mode'] === 'customer') $movements = $movements->merge($this->customerReturnMovements($filters));
        $movements = $movements->sortBy(fn (array $row): array => [$row['date'], $row['identity']])->values();
        $opening = 0.0; $period = [];
        foreach ($movements as $row) {
            $net = round((float) $row['debit'] - (float) $row['credit'], 2);
            if ($row['date'] < $filters['from']) { $opening = round($opening + $net, 2); continue; }
            if ($row['date'] <= $filters['to']) $period[] = $row + ['net' => $net];
        }
        $balance = round($opening, 2); $debitTotal = 0.0; $creditTotal = 0.0; $rows = [];
        foreach ($period as $row) {
            $balance = round($balance + $row['net'], 2);
            $row['balance'] = $balance;
            $debitTotal += (float) $row['debit']; $creditTotal += (float) $row['credit'];
            unset($row['net'], $row['identity']);
            $rows[] = $row;
        }
        $closing = round($opening + $debitTotal - $creditTotal, 2);
        $pageSize = $filters['page_size'] === 'all' || ! $paginate ? max(1, count($rows)) : (int) $filters['page_size'];
        $page = $filters['page_size'] === 'all' || ! $paginate ? 1 : LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(collect($rows)->forPage($page, $pageSize)->values(), count($rows), $pageSize, $page, ['path' => $request->url(), 'query' => $request->query()]);
        return [
            'filters' => $filters, 'rows' => $rows, 'paginator' => $paginator, 'statement_ready' => true,
            'opening' => round($opening, 2), 'total_debit' => round($debitTotal, 2), 'total_credit' => round($creditTotal, 2),
            'closing' => $closing, 'closing_side' => $closing > 0 ? 'Dr' : ($closing < 0 ? 'Cr' : '0.00'),
            'balance_label' => $closing > 0 ? 'Outstanding / Debit' : ($closing < 0 ? 'Advance / Credit' : 'Settled'),
        ];
    }

    private function emptyStatement(Request $request, array $filters): array
    {
        $paginator = new LengthAwarePaginator(collect(), 0, 50, 1, ['path' => $request->url(), 'query' => $request->query()]);
        return [
            'filters' => $filters, 'rows' => [], 'paginator' => $paginator,
            'opening' => 0.0, 'total_debit' => 0.0, 'total_credit' => 0.0,
            'closing' => 0.0, 'closing_side' => '0.00', 'balance_label' => 'Select a party',
            'statement_ready' => false,
        ];
    }

    private function bookingMovements(Request $request, array $filters): Collection
    {
        $query = $request->duplicate([
            'tab' => $filters['mode'], 'party_id' => $filters['party_id'], 'date_range' => 'custom',
            'from' => '1900-01-01', 'to' => $filters['to'], 'page_size' => 'all', 'generate' => '1', 'include_cancelled' => '1',
        ]);
        $query->setUserResolver(fn () => $request->user());
        $data = $this->bookingReport->report($query, 0);
        $passengerParticulars = $this->bookingPassengerParticulars(collect($data['rows'] ?? [])->map(fn ($row): array => (array) $row)->all());
        $out = collect();
        foreach ($data['rows'] as $row) {
            $amount = $filters['mode'] === 'customer' ? (float) ($row['sale'] ?? 0) : (float) ($row['vendor_cost'] ?? 0);
            $bookingId = (int) ($row['booking_id'] ?? 0);
            $out->push([
                'date' => $this->date($row['booking_date'] ?? null) ?? $filters['to'],
                'type' => $filters['mode'] === 'customer' ? 'Booking Sale' : 'Vendor Booking Cost',
                'reference' => (string) ($row['booking_no'] ?? ''), 'booking_no' => (string) ($row['booking_no'] ?? '—'),
                'particulars' => $passengerParticulars[$bookingId] ?? '—',
                'debit' => $filters['mode'] === 'customer' ? round($amount, 2) : 0.0,
                'credit' => $filters['mode'] === 'vendor' ? round($amount, 2) : 0.0,
                'identity' => 'booking:'.$bookingId,
            ]);
        }
        return $out;
    }

    /** @return array<int,string> */
    private function bookingPassengerParticulars(array $rows): array
    {
        $ids = collect($rows)->map(fn (array $row): int => (int) ($row['booking_id'] ?? 0))->filter()->unique()->values()->all();
        if ($ids === [] || ! Schema::hasTable('booking_passengers')) return [];
        $columns = Schema::getColumnListing('booking_passengers');
        if (! in_array('booking_id', $columns, true)) return [];
        $nameColumns = array_values(array_filter(
            ['full_name', 'passenger_name', 'name', 'first_name', 'given_name', 'middle_name', 'last_name', 'surname'],
            static fn (string $column): bool => in_array($column, $columns, true),
        ));
        $leadColumns = array_values(array_filter(
            ['is_lead', 'is_primary', 'lead_passenger', 'is_main'],
            static fn (string $column): bool => in_array($column, $columns, true),
        ));
        $select = array_values(array_unique(array_merge(['booking_id', 'id'], $nameColumns, $leadColumns)));
        $query = DB::table('booking_passengers')->whereIn('booking_id', $ids);
        if (in_array('is_active', $columns, true)) $query->where('is_active', 1);
        if (in_array('status', $columns, true)) $query->whereNotIn(DB::raw('LOWER(status)'), ['removed', 'deleted', 'inactive']);
        $passengers = $query->orderBy('id')->get($select)->groupBy('booking_id');
        $result = [];
        foreach ($passengers as $bookingId => $group) {
            $lead = $group->first(function (object $passenger) use ($leadColumns): bool {
                foreach ($leadColumns as $column) if (filter_var($passenger->{$column} ?? null, FILTER_VALIDATE_BOOLEAN)) return true;
                return false;
            }) ?? $group->first();
            if (! $lead) continue;
            $parts = [];
            foreach (['full_name', 'passenger_name', 'name'] as $column) {
                if (in_array($column, $nameColumns, true) && trim((string) ($lead->{$column} ?? '')) !== '') {
                    $parts = [trim((string) $lead->{$column})];
                    break;
                }
            }
            if ($parts === []) {
                foreach (['first_name', 'given_name', 'middle_name', 'last_name', 'surname'] as $column) {
                    if (in_array($column, $nameColumns, true) && trim((string) ($lead->{$column} ?? '')) !== '') $parts[] = trim((string) $lead->{$column});
                }
            }
            $label = trim(implode(' ', $parts));
            $result[(int) $bookingId] = ($label !== '' ? $label : '—').' · '.$group->count().' PAX';
        }
        return $result;
    }

    private function cashVoucherMovements(array $filters): Collection
    {
        if (! Schema::hasTable('cash_vouchers')) return collect();
        $columns = Schema::getColumnListing('cash_vouchers');
        foreach (['id','voucher_no','voucher_type','party_type','party_id','amount','status'] as $required) if (! in_array($required, $columns, true)) return collect();
        $query = DB::table('cash_vouchers')->where('party_type', $filters['mode'] === 'vendor' ? 'supplier' : 'customer')->where('status', 'posted');
        if (in_array('reversed_at', $columns, true)) $query->whereNull('reversed_at');
        if (in_array('reversal_reference', $columns, true)) $query->where(function ($q): void { $q->whereNull('reversal_reference')->orWhere('reversal_reference', ''); });
        if ($filters['party_id'] > 0) $query->where('party_id', $filters['party_id']);
        $this->scopeQuery($query, $columns, $filters);
        $vouchers = $query->get();
        // Historical posted vouchers may predate persisted company/branch
        // ownership. Resolve only those incomplete rows through the existing
        // read-only reconciliation authority; never infer scope from the
        // current user or silently treat NULL as globally visible.
        $legacyEvidence = [];
        $legacyRows = $vouchers->filter(function (object $voucher): bool {
            return (int) ($voucher->company_id ?? 0) < 1 || (int) ($voucher->branch_id ?? 0) < 1;
        })->values()->all();
        foreach (array_chunk($legacyRows, 100) as $chunk) {
            foreach ($this->legacyVoucherOwnership->classifyRows($chunk) as $evidence) {
                $legacyEvidence[(int) ($evidence['voucher_id'] ?? 0)] = $evidence;
            }
        }
        $ids = $vouchers->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $allocations = $this->voucherAllocations($ids);
        $bookingNos = $this->bookingNumbers(collect($allocations)->flatten()->unique()->values()->all());
        $out = collect();
        foreach ($vouchers as $voucher) {
            $scope = $this->voucherScope($voucher, $filters, $legacyEvidence[(int) $voucher->id] ?? null);
            if ($scope === null) continue;
            $type = strtolower((string) $voucher->voucher_type);
            $isCustomer = $filters['mode'] === 'customer';
            $isRefund = $isCustomer && in_array($type, ['customer_reimbursement','customer_refund'], true);
            // Supplier receipts are not assumed to be refunds: the native
            // voucher contract has no verified vendor-refund discriminator.
            // Include only an explicitly typed refund until that authority is
            // available, preventing an ordinary receipt from being relabeled.
            $isVendorRefund = ! $isCustomer && str_contains($type, 'refund');
            $isPayment = $isCustomer ? in_array($type, ['receipt','customer_advance'], true) : in_array($type, ['payment','supplier_advance','vendor_advance'], true);
            if (! $isRefund && ! $isVendorRefund && ! $isPayment) continue;
            $amount = round((float) $voucher->amount, 2); if ($amount <= 0) continue;
            $date = $this->date(($voucher->posted_at ?? null) ? substr((string) $voucher->posted_at, 0, 10) : ($voucher->value_date ?? $voucher->voucher_date)) ?? $filters['to'];
            $bookingIds = $allocations[(int) $voucher->id] ?? [];
            $bookingLabel = $bookingIds ? implode(', ', array_values(array_filter(array_map(fn (int $id): string => $bookingNos[$id] ?? ('#'.$id), $bookingIds)))) : '—';
            $out->push([
                'date' => $date, 'type' => $isRefund || $isVendorRefund ? ($isCustomer ? 'Customer Refund Paid' : 'Vendor Refund Received') : ($isCustomer ? ($type === 'customer_advance' ? 'Customer Advance Receipt' : 'Customer Receipt') : ($type === 'supplier_advance' || $type === 'vendor_advance' ? 'Supplier Advance Payment' : 'Vendor Payment')),
                'reference' => (string) $voucher->voucher_no, 'booking_no' => $bookingLabel,
                'particulars' => $bookingLabel === '—' ? 'Posted cash movement' : 'Posted cash movement · Booking(s): '.$bookingLabel,
                'debit' => ($isCustomer && $isRefund) || $isVendorRefund || (!$isCustomer && $isPayment) ? $amount : 0.0,
                'credit' => ($isCustomer && ! $isRefund) || (!$isCustomer && ! $isVendorRefund && ! $isPayment) ? $amount : 0.0,
                'identity' => 'voucher:'.(int) $voucher->id,
            ]);
        }
        return $out;
    }

    private function customerReturnMovements(array $filters): Collection
    {
        if (! Schema::hasTable('customer_advance_returns')) return collect();
        $columns = Schema::getColumnListing('customer_advance_returns');
        foreach (['id','return_no','customer_party_id','return_date','amount','status'] as $required) if (! in_array($required, $columns, true)) return collect();
        $query = DB::table('customer_advance_returns')->where('status', 'posted');
        if ($filters['party_id'] > 0) $query->where('customer_party_id', $filters['party_id']);
        $this->scopeQuery($query, $columns, $filters); $out = collect();
        if (! in_array('posting_reference', $columns, true)) return collect();
        foreach ($query->get() as $row) {
            $amount = round((float) $row->amount, 2); if ($amount <= 0) continue;
            $postingReference = trim((string) ($row->posting_reference ?? ''));
            if ($postingReference === '' || $this->returnMatchesPostedVoucher($postingReference)) continue;
            $out->push(['date' => $this->date($row->return_date) ?? $filters['to'], 'type' => 'Customer Refund Paid', 'reference' => (string) $row->return_no, 'booking_no' => '—', 'particulars' => 'Posted customer advance return', 'debit' => $amount, 'credit' => 0.0, 'identity' => 'customer-return:'.(int) $row->id]);
        }
        return $out;
    }

    private function returnMatchesPostedVoucher(string $reference): bool
    {
        if (! Schema::hasTable('cash_vouchers')) return false;
        $columns = Schema::getColumnListing('cash_vouchers');
        $query = DB::table('cash_vouchers')->where('status', 'posted');
        $query->where(function ($q) use ($columns, $reference): void {
            $matched = false;
            foreach (['posting_reference', 'transaction_reference', 'reversal_reference'] as $column) {
                if (in_array($column, $columns, true)) { $q->orWhere($column, $reference); $matched = true; }
            }
            if (! $matched) $q->whereRaw('1 = 0');
        });
        return $query->exists();
    }

    private function voucherAllocations(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('cash_voucher_allocations')) return [];
        $columns = Schema::getColumnListing('cash_voucher_allocations'); if (! in_array('cash_voucher_id', $columns, true)) return [];
        $bookingColumn = in_array('booking_id', $columns, true) ? 'booking_id' : null; if (! $bookingColumn) return [];
        $out = []; foreach (DB::table('cash_voucher_allocations')->whereIn('cash_voucher_id', $ids)->whereNotNull($bookingColumn)->get(['cash_voucher_id', $bookingColumn]) as $row) $out[(int) $row->cash_voucher_id][] = (int) $row->{$bookingColumn};
        foreach ($out as $id => $values) $out[$id] = array_values(array_unique($values));
        return $out;
    }

    private function bookingNumbers(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('bookings')) return [];
        $columns = Schema::getColumnListing('bookings'); $number = collect(['booking_no','booking_number','reference'])->first(fn (string $c): bool => in_array($c, $columns, true)); if (! $number) return [];
        return DB::table('bookings')->whereIn('id', $ids)->pluck($number, 'id')->map(fn ($v): string => (string) $v)->all();
    }

    private function scopeQuery($query, array $columns, array $filters): void
    {
        $hasCompany = in_array('company_id', $columns, true);
        $hasBranch = in_array('branch_id', $columns, true);
        // The authority uses NULL exclusively for an authenticated
        // Super Admin's company-wide branch scope. Preserve that meaning;
        // an explicit empty array remains fail-closed.
        $rawBranchIds = array_key_exists('branch_ids', $filters) ? $filters['branch_ids'] : [];
        $unrestrictedBranches = ($rawBranchIds === null) && (($filters['super_admin_scope'] ?? false) === true);
        $branchIds = array_values(array_filter(
            array_map('intval', (array) ($unrestrictedBranches ? [] : $rawBranchIds)),
            static fn (int $id): bool => $id > 0,
        ));
        if (! $unrestrictedBranches && $branchIds === [] && $hasBranch) {
            $query->whereRaw('1 = 0');
            return;
        }
        if ($unrestrictedBranches) {
            if ($hasCompany && $filters['company_id'] !== null) $query->where('company_id', $filters['company_id']);
            return;
        }

        // Keep incomplete legacy rows in the bounded candidate set so the
        // authoritative reconciliation service can prove their ownership.
        // Complete rows remain constrained in SQL; unresolved rows are
        // rejected later by voucherScope().
        if ($hasCompany || $hasBranch) {
            $query->where(function ($scope) use ($filters, $hasCompany, $hasBranch, $unrestrictedBranches, $branchIds): void {
                $scope->where(function ($complete) use ($filters, $hasCompany, $hasBranch, $unrestrictedBranches, $branchIds): void {
                    if ($hasCompany && $filters['company_id'] !== null) $complete->where('company_id', $filters['company_id']);
                    if ($hasBranch && ! $unrestrictedBranches) $complete->whereIn('branch_id', $branchIds);
                });
                if ($hasCompany) $scope->orWhereNull('company_id');
                if ($hasBranch) $scope->orWhereNull('branch_id');
            });
        }
    }

    /** @return array{company_id:int,branch_id:int}|null */
    private function voucherScope(object $voucher, array $filters, ?array $evidence): ?array
    {
        $company = (int) ($voucher->company_id ?? 0);
        $branch = (int) ($voucher->branch_id ?? 0);
        if ($company > 0 && $branch > 0) {
            return $this->scopeAllowed($company, $branch, $filters) ? ['company_id' => $company, 'branch_id' => $branch] : null;
        }

        if (! $evidence || ! in_array((string) ($evidence['classification'] ?? ''), [
            'BOOKING_PROVEN', 'JOURNAL_PROVEN', 'SOURCE_DOCUMENT_PROVEN', 'MULTIPLE_SOURCES_CONSISTENT',
        ], true)) return null;

        $company = (int) ($evidence['proposed_company_id'] ?? 0);
        $branch = (int) ($evidence['proposed_branch_id'] ?? 0);
        $persistedCompany = (int) ($voucher->company_id ?? 0);
        $persistedBranch = (int) ($voucher->branch_id ?? 0);
        if (($persistedCompany > 0 && $persistedCompany !== $company) || ($persistedBranch > 0 && $persistedBranch !== $branch)) return null;
        return $company > 0 && $branch > 0 && $this->scopeAllowed($company, $branch, $filters)
            ? ['company_id' => $company, 'branch_id' => $branch]
            : null;
    }

    private function scopeAllowed(int $company, int $branch, array $filters): bool
    {
        if ($filters['company_id'] !== null && $company !== (int) $filters['company_id']) return false;
        $branchIds = array_key_exists('branch_ids', $filters) ? $filters['branch_ids'] : [];
        if ($branchIds === null && (($filters['super_admin_scope'] ?? false) === true)) return true;
        return in_array($branch, array_map('intval', (array) $branchIds), true);
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value); if ($value === '') return null;
        try { $date = Carbon::parse($value)->toDateString(); return $date; } catch (\Throwable) { return null; }
    }
}
