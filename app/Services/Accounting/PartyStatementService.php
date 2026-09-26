<?php

namespace App\Services\Accounting;

use App\Services\Operations\UnifiedGroupPackageDataSource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Read-only combined customer/vendor party statement over posted journals. */
final class PartyStatementService
{
    public function __construct(
        private readonly ChartOfAccountsWorkspaceService $chart,
        private readonly UnifiedGroupPackageDataSource $bookingData,
        private readonly PartyStatementEnrichmentResolver $enrichment,
    ) {}

    public function filters(Request $request): array
    {
        $type = strtolower((string) $request->query('party_type', 'customer')) === 'vendor' ? 'vendor' : 'customer';
        $to = $this->date((string) $request->query('to', now()->toDateString()));
        // A client statement defaults to the active financial year, not the
        // current month, so material advances/receipts remain visible as rows.
        $from = $this->date((string) $request->query('from', $this->financialYearStart($to)));
        if ($from > $to) [$from, $to] = [$to, $from];
        $parties = $type === 'vendor' ? $this->bookingData->vendors() : $this->bookingData->customers();
        $partyId = (int) $request->query('party_id', 0);
        if ($partyId > 0 && ! collect($parties)->contains(fn (array $row): bool => (int) ($row['id'] ?? 0) === $partyId)) $partyId = 0;
        return compact('type', 'from', 'to', 'parties', 'partyId');
    }

    public function statement(array $filters): array
    {
        $accounts = $this->chart->schema();
        $accountRows = $this->chartAccounts($accounts);
        $scope = $this->scopeIds($accountRows, $filters['type']);
        $rows = $this->journalRows($filters, $scope);
        $opening = 0.0;
        $period = [];
        foreach ($rows as $row) {
            $net = round((float) $row['debit'] - (float) $row['credit'], 2);
            if (abs($net) < 0.005) continue;
            if ($row['date'] < $filters['from']) {
                $opening += $net;
                continue;
            }
            if ($row['date'] <= $filters['to']) $period[] = $row + ['net' => $net];
        }
        usort($period, static fn (array $a, array $b): int => [$a['date'], $a['journal_id']] <=> [$b['date'], $b['journal_id']]);
        $balance = round($opening, 2); $totalDebit = 0.0; $totalCredit = 0.0; $out = [];
        foreach ($period as $row) {
            $balance = round($balance + $row['net'], 2);
            $debit = $row['net'] > 0 ? $row['net'] : 0.0;
            $credit = $row['net'] < 0 ? abs($row['net']) : 0.0;
            $totalDebit += $debit; $totalCredit += $credit;
            $row['debit'] = round($debit, 2); $row['credit'] = round($credit, 2); $row['balance'] = $balance;
            // Enrichment is deliberately applied after journal netting and balance
            // calculation: it can only add display metadata, never financial data.
            $row = array_merge($row, $this->enrichment->resolve($row));
            $out[] = $row;
        }
        $closing = round($opening + $totalDebit - $totalCredit, 2);
        return [
            'filters' => $filters, 'rows' => $out, 'opening' => round($opening, 2),
            'total_debit' => round($totalDebit, 2), 'total_credit' => round($totalCredit, 2),
            'closing' => $closing, 'closing_side' => $closing > 0 ? 'Dr' : ($closing < 0 ? 'Cr' : '0.00'),
            'caption' => $this->caption($filters['type'], $closing), 'accounts' => $scope,
        ];
    }

    private function chartAccounts(array $schema): array
    {
        $select = [$schema['id'].' as id', $schema['code'].' as code', $schema['name'].' as name'];
        foreach (['control_type', 'active', 'status'] as $field) if (! empty($schema[$field])) $select[] = $schema[$field].' as '.$field;
        return DB::table($schema['table'])->select($select)->get()->map(fn (object $row): array => (array) $row)->all();
    }

    private function scopeIds(array $accounts, string $type): array
    {
        $wanted = $type === 'vendor' ? [['VENDOR_AP', '2110'], ['VENDOR_ADVANCE', '1140']] : [['CUSTOMER_AR', '1130'], ['CUSTOMER_ADVANCE', '2120']];
        $ids = [];
        foreach ($wanted as [$control, $code]) {
            $matched = array_values(array_filter($accounts, fn (array $a): bool => strtoupper(trim((string) ($a['control_type'] ?? ''))) === $control));
            if ($matched === []) $matched = array_values(array_filter($accounts, fn (array $a): bool => trim((string) $a['code']) === $code));
            foreach ($matched as $account) $ids[(int) $account['id']] = trim((string) $account['code']);
        }
        return $ids;
    }

    private function journalRows(array $filters, array $scope): array
    {
        if ($scope === [] || ! Schema::hasColumns('journal_lines', ['journal_entry_id', 'party_type', 'party_id'])) return [];
        $columns = Schema::getColumnListing('journal_entries');
        $lineColumns = Schema::getColumnListing('journal_lines');
        $debit = in_array('base_debit', $lineColumns, true) ? 'base_debit' : 'debit';
        $credit = in_array('base_credit', $lineColumns, true) ? 'base_credit' : 'credit';
        $ref = $this->first($columns, ['reference', 'journal_no', 'entry_no', 'number']);
        $type = $this->first($columns, ['source_type', 'journal_type', 'entry_type', 'type']);
        $sourceId = $this->first($columns, ['source_id', 'reference_id', 'document_id']);
        $select = ['je.id as journal_id', 'je.journal_date as date', 'jl.account_id', 'jl.'.$debit.' as debit', 'jl.'.$credit.' as credit'];
        $select[] = $ref ? 'je.'.$ref.' as reference' : DB::raw("'' as reference");
        $select[] = $type ? 'je.'.$type.' as source_type' : DB::raw("'' as source_type");
        $select[] = $sourceId ? 'je.'.$sourceId.' as source_id' : DB::raw('NULL as source_id');
        $rows = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')->where('jl.party_type', $filters['type'])->where('jl.party_id', $filters['partyId'])
            ->whereIn('jl.account_id', array_keys($scope))->orderBy('je.journal_date')->orderBy('je.id')->get($select);
        $grouped = [];
        foreach ($rows as $row) {
            $key = (string) $row->journal_id;
            $grouped[$key] ??= ['journal_id' => (int) $row->journal_id, 'date' => Carbon::parse($row->date)->toDateString(), 'reference' => (string) ($row->reference ?? ''), 'source_type' => (string) ($row->source_type ?? ''), 'source_id' => $row->source_id, 'debit' => 0.0, 'credit' => 0.0];
            $grouped[$key]['debit'] += (float) $row->debit; $grouped[$key]['credit'] += (float) $row->credit;
        }
        return array_values(array_map(fn (array $row): array => $row + ['type' => $this->label($row['source_type'], $row['reference']), 'booking_no' => '—', 'product' => '—', 'party' => '—', 'service_ref' => '—', 'description' => $row['reference'] ?: 'Journal'], $grouped));
    }

    private function label(string $source, string $reference): string
    {
        $value = strtolower(trim($source));
        return match (true) {
            str_contains($value, 'invoice') || str_starts_with(strtoupper($reference), 'SI-') => 'Invoice',
            str_contains($value, 'receipt') || str_starts_with(strtoupper($reference), 'RV-') => 'Receipt',
            str_contains($value, 'advance') => 'Advance',
            str_contains($value, 'payment') || str_starts_with(strtoupper($reference), 'PV-') => 'Payment',
            str_contains($value, 'refund') => 'Refund',
            str_contains($value, 'credit') => 'Credit Note',
            str_contains($value, 'debit') => 'Debit Note',
            str_contains($value, 'adjust') => 'Adjustment',
            default => 'Journal',
        };
    }

    private function caption(string $type, float $closing): string
    {
        if ($type === 'customer') return $closing < 0 ? 'Customer Advance / Credit Balance' : 'Amount Receivable from Customer';
        return $closing > 0 ? 'Vendor Advance / Debit Balance' : 'Amount Payable to Vendor';
    }

    private function date(string $value): string { try { return Carbon::parse($value)->toDateString(); } catch (Throwable) { return now()->toDateString(); } }
    private function financialYearStart(string $asOf): string
    {
        foreach (['fiscal_years', 'financial_years', 'fiscal_year'] as $table) {
            if (! Schema::hasTable($table)) continue;
            try {
                $columns = Schema::getColumnListing($table);
                $start = $this->first($columns, ['start_date', 'date_from', 'from_date', 'starts_on', 'year_start', 'period_start', 'start_on']);
                $end = $this->first($columns, ['end_date', 'date_to', 'to_date', 'ends_on', 'year_end', 'period_end', 'end_on']);
                if (!$start || !$end) continue;
                $row = DB::table($table)->whereDate($start, '<=', $asOf)->whereDate($end, '>=', $asOf)->first();
                if ($row) return Carbon::parse($row->{$start})->toDateString();
            } catch (Throwable) { continue; }
        }
        return Carbon::parse($asOf)->startOfYear()->toDateString();
    }
    private function first(array $columns, array $wanted): ?string { foreach ($wanted as $name) if (in_array($name, $columns, true)) return $name; return null; }
}
