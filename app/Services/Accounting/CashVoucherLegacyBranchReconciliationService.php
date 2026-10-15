<?php

namespace App\Services\Accounting;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only evidence classifier for pre-B1 cash voucher ownership. */
final class CashVoucherLegacyBranchReconciliationService
{
    private const PAGE_SIZE = 100;

    public function page(array $filters = [], int $page = 1): LengthAwarePaginator
    {
        $query = $this->voucherQuery($filters)->orderByDesc('id');
        $paginator = $query->paginate(self::PAGE_SIZE, ['*'], 'page', max(1, $page));
        $classified = collect($this->classifyRows($paginator->getCollection()->all()));
        if (! empty($filters['classification'])) $classified = $classified->where('classification', (string) $filters['classification'])->values();
        $paginator->setCollection($classified);
        return $paginator;
    }

    public function summary(array $filters = []): array
    {
        $totals = array_fill_keys([
            'legacy_total','booking_proven','journal_proven','source_document_proven',
            'multiple_sources_consistent','source_mismatch','unresolved',
            'current_ownership_match','current_ownership_mismatch',
        ], 0);
        $query = $this->voucherQuery($filters)->orderBy('id');
        $query->chunkById(self::PAGE_SIZE, function ($rows) use (&$totals): void {
            foreach ($this->classifyRows($rows->all()) as $record) {
                $totals['legacy_total']++;
                $key = match ($record['classification']) {
                    'BOOKING_PROVEN' => 'booking_proven',
                    'JOURNAL_PROVEN' => 'journal_proven',
                    'SOURCE_DOCUMENT_PROVEN' => 'source_document_proven',
                    'MULTIPLE_SOURCES_CONSISTENT' => 'multiple_sources_consistent',
                    'SOURCE_MISMATCH' => 'source_mismatch',
                    default => 'unresolved',
                };
                $totals[$key]++;
                if ($record['current_ownership_match'] === 'YES') $totals['current_ownership_match']++;
                if ($record['current_ownership_match'] === 'NO') $totals['current_ownership_mismatch']++;
            }
        }, 'id');
        return $totals;
    }

    public function csvRows(array $filters = []): iterable
    {
        $query = $this->voucherQuery($filters)->orderBy('id');
        $headers = ['voucher_id','voucher_no','voucher_type','status','voucher_date','booking_id','current_company_id','current_branch_id','booking_company_id','booking_branch_id','journal_company_id','journal_branch_id','source_company_id','source_branch_id','classification','proposed_company_id','proposed_branch_id','evidence_sources','reason'];
        yield $headers;
        $page = 1;
        do {
            $rows = (clone $query)->forPage($page++, self::PAGE_SIZE)->get();
            foreach ($this->classifyRows($rows->all()) as $record) yield $this->csvRecord($record, $headers);
        } while ($rows->count() === self::PAGE_SIZE);
    }

    public function classifyRows(array $rows): array
    {
        if ($rows === []) return [];
        $ids = array_values(array_map(static fn ($row): int => (int) $row->id, $rows));
        $bookings = $this->bookingEvidence($rows);
        $journals = $this->journalEvidence($ids);
        $sources = $this->sourceEvidence($ids);
        return array_map(function ($voucher) use ($bookings, $journals, $sources): array {
            $id = (int) $voucher->id;
            $evidence = [];
            foreach (["BOOKING" => $bookings[$id] ?? null, "JOURNAL" => $journals[$id] ?? null, "SOURCE_DOCUMENT" => $sources[$id] ?? null] as $name => $value) {
                if ($value && ($value['status'] ?? 'NONE') !== 'NONE') $evidence[$name] = $value;
            }
            $pairs = array_values(array_filter(array_map(static fn ($value) => ($value['company_id'] ?? 0) > 0 && ($value['branch_id'] ?? 0) > 0 ? [(int) $value['company_id'], (int) $value['branch_id']] : null, $evidence)));
            $unique = array_values(array_unique(array_map(static fn ($pair): string => implode(':', $pair), $pairs)));
            $classification = 'UNRESOLVED';
            $reason = 'No deterministic source proves both company and branch.';
            if (count($unique) > 1) { $classification = 'SOURCE_MISMATCH'; $reason = 'Authoritative evidence sources disagree.'; }
            elseif (count($unique) === 1 && count($pairs) > 1) { $classification = 'MULTIPLE_SOURCES_CONSISTENT'; $reason = 'All authoritative evidence sources agree.'; }
            elseif (count($unique) === 1) {
                $classification = match (array_key_first($evidence)) { 'BOOKING' => 'BOOKING_PROVEN', 'JOURNAL' => 'JOURNAL_PROVEN', default => 'SOURCE_DOCUMENT_PROVEN' };
                $reason = 'Exactly one authoritative evidence family proves ownership.';
            }
            $proposed = count($unique) === 1 ? $pairs[0] : [null, null];
            $current = ((int) ($voucher->company_id ?? 0) > 0 && (int) ($voucher->branch_id ?? 0) > 0) ? [(int) $voucher->company_id, (int) $voucher->branch_id] : [null, null];
            $currentState = $current[0] === null ? 'EMPTY' : (count($unique) === 1 && $current === $proposed ? 'YES' : (count($unique) === 1 ? 'NO' : 'PARTIAL'));
            return [
                'voucher_id' => $id, 'voucher_no' => (string) ($voucher->voucher_no ?? ''), 'voucher_type' => (string) ($voucher->voucher_type ?? ''), 'status' => (string) ($voucher->status ?? ''), 'voucher_date' => (string) ($voucher->voucher_date ?? ''), 'booking_id' => $voucher->booking_id ?? null,
                'current_company_id' => $current[0], 'current_branch_id' => $current[1], 'booking_company_id' => $bookings[$id]['company_id'] ?? null, 'booking_branch_id' => $bookings[$id]['branch_id'] ?? null, 'journal_company_id' => $journals[$id]['company_id'] ?? null, 'journal_branch_id' => $journals[$id]['branch_id'] ?? null, 'source_company_id' => $sources[$id]['company_id'] ?? null, 'source_branch_id' => $sources[$id]['branch_id'] ?? null,
                'classification' => $classification, 'proposed_company_id' => $proposed[0], 'proposed_branch_id' => $proposed[1], 'evidence_sources' => implode(',', array_keys($evidence)), 'reason' => $reason, 'current_ownership_match' => $currentState,
            ];
        }, $rows);
    }

    private function voucherQuery(array $filters): Builder
    {
        $columns = Schema::getColumnListing('cash_vouchers');
        $query = DB::table('cash_vouchers');
        foreach (['voucher_type','status'] as $field) if (! empty($filters[$field]) && in_array($field, $columns, true)) $query->where($field, (string) $filters[$field]);
        if (! empty($filters['q']) && in_array('voucher_no', $columns, true)) $query->where('voucher_no', 'like', '%'.trim((string) $filters['q']).'%');
        return $query;
    }

    private function bookingEvidence(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($row): int => (int) ($row->booking_id ?? 0), $rows))));
        if ($ids === []) return [];
        foreach (['bookings','travel_bookings','booking_group_package_unified','general_bookings'] as $table) {
            if (! Schema::hasTable($table)) continue; $columns = Schema::getColumnListing($table); $branch = $this->first($columns, ['branch_id','office_id']);
            if (! $branch || ! in_array('company_id', $columns, true)) continue; $key = in_array('id', $columns, true) ? 'id' : $this->first($columns, ['booking_id']); if (! $key) continue;
            $out=[]; foreach (DB::table($table)->whereIn($key, $ids)->get() as $row) $out[(int) $row->{$key}] = ['status'=>'VALID','company_id'=>(int)$row->company_id,'branch_id'=>(int)$row->{$branch}]; return $out;
        }
        return [];
    }

    private function journalEvidence(array $ids): array
    {
        if (! Schema::hasTable('journal_entries')) return [];
        $columns = Schema::getColumnListing('journal_entries'); if (! in_array('source_type',$columns,true) || ! in_array('source_id',$columns,true) || ! in_array('company_id',$columns,true) || ! in_array('branch_id',$columns,true)) return [];
        $out=[]; foreach (DB::table('journal_entries')->where('source_type','cash_voucher')->whereIn('source_id',$ids)->get() as $row) { $id=(int)$row->source_id; $pair=[(int)$row->company_id,(int)$row->branch_id]; if (($out[$id]['company_id']??null)!==null && ($out[$id]['company_id']!==$pair[0] || $out[$id]['branch_id']!==$pair[1])) {$out[$id]=['status'=>'CONFLICT'];} elseif (!isset($out[$id])) {$out[$id]=['status'=>'VALID','company_id'=>$pair[0],'branch_id'=>$pair[1],'count'=>1];} else {$out[$id]['count']++;$out[$id]['status']='CONSISTENT_MULTIPLE';} } return $out;
    }

    private function sourceEvidence(array $ids): array
    {
        if (! Schema::hasTable('cash_voucher_allocations')) return [];
        $columns = Schema::getColumnListing('cash_voucher_allocations'); if (! in_array('cash_voucher_id',$columns,true)) return [];
        foreach (['party_opening_balances','customer_advance_returns','supplier_costings','sales_invoices'] as $table) {
            if (! Schema::hasTable($table)) continue; $target = $this->first($columns,['target_id','advance_source_id']); if (!$target) continue; $sourceCols=Schema::getColumnListing($table); $branch=$this->first($sourceCols,['branch_id','office_id']); if (!$branch || !in_array('company_id',$sourceCols,true)) continue;
            $out=[]; foreach (DB::table('cash_voucher_allocations as a')->join($table.' as s','s.id','=','a.'.$target)->whereIn('a.cash_voucher_id',$ids)->get(['a.cash_voucher_id','s.company_id','s.'.$branch.' as branch_id']) as $row) $out[(int)$row->cash_voucher_id]=['status'=>'VALID','company_id'=>(int)$row->company_id,'branch_id'=>(int)$row->branch_id]; return $out;
        }
        return [];
    }

    private function csvRecord(array $record, array $headers): array { return array_map(static fn (string $header) => $header === 'evidence_sources' ? $record[$header] : ($record[$header] ?? null), $headers); }
    private function first(array $columns, array $names): ?string { foreach ($names as $name) if (in_array($name, $columns, true)) return $name; return null; }
}
